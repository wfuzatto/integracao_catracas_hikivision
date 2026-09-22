<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

final class HcpOpenApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpCode = 0, public readonly array $response = [])
    {
        parent::__construct($message);
    }
}

function hcp_configured(): bool
{
    return HCP_APP_KEY !== '' && HCP_APP_SECRET !== '';
}

function hcp_access_level_id(string $name): ?string
{
    $map = HCP_ACCESS_LEVEL_MAP;
    if (!isset($map[$name]) || (string)$map[$name] === '') return null;
    return (string)$map[$name];
}

function hcp_base64_image(string $path): string
{
    $absolute = hcp_face_image_path($path);
    if (!is_file($absolute)) throw new HcpOpenApiException('A foto facial não foi encontrada no servidor.');
    $bytes = file_get_contents($absolute);
    if ($bytes === false) throw new HcpOpenApiException('Não foi possível ler a foto facial.');
    return base64_encode($bytes);
}

function hcp_face_image_path(string $path): string
{
    $absolute = __DIR__ . '/' . ltrim($path, '/');
    if (!is_file($absolute)) return $absolute;
    $info = getimagesize($absolute);
    $mime = $info['mime'] ?? '';
    if ($mime === 'image/jpeg') return $absolute;

    $dir = __DIR__ . '/uploads/faces/normalized';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $target = $dir . '/' . pathinfo($absolute, PATHINFO_FILENAME) . '.jpg';
    if (is_file($target) && filemtime($target) >= filemtime($absolute) && filesize($target) > 0) return $target;

    $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File ' .
        escapeshellarg(__DIR__ . '/normalize-face.ps1') .
        ' -Source ' . escapeshellarg($absolute) .
        ' -Target ' . escapeshellarg($target);
    exec($cmd, $output, $code);
    if ($code !== 0 || !is_file($target) || filesize($target) === 0) {
        throw new HcpOpenApiException('Nao foi possivel converter a foto facial para JPEG antes do envio.');
    }
    return $target;
}

function hcp_certificate_no(?string $value): string
{
    // HikCentral rejects formatted Brazilian document strings (e.g. CPF with
    // dots and a dash). Keep the display value in our database, but send the
    // normalized alphanumeric value to OpenAPI.
    return preg_replace('/[^0-9A-Za-z]/', '', (string)$value) ?? '';
}

function hcp_save_qr_image(string $value, int $reservationId): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    if (str_contains($value, ',')) $value = substr($value, strpos($value, ',') + 1);
    $bytes = base64_decode($value, true);
    if ($bytes === false || $bytes === '') return null;
    $dir = __DIR__ . '/uploads/qr';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'hcp-' . $reservationId . '-' . bin2hex(random_bytes(8)) . '.png';
    if (file_put_contents($dir . '/' . $name, $bytes) === false) return null;
    return 'uploads/qr/' . $name;
}

final class HcpOpenApiClient
{
    public function request(string $path, array $body): array
    {
        if (!hcp_configured()) {
            throw new HcpOpenApiException('OpenAPI não configurado: informe AppKey e AppSecret no config.local.php.');
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $nonce = bin2hex(random_bytes(16));
        $accept = '*/*';
        $contentType = 'application/json';
        $contentMd5 = base64_encode(md5($json, true));
        $timestamp = (string)(int)floor(microtime(true) * 1000);
        $signatureHeaders = 'x-ca-key,x-ca-nonce,x-ca-timestamp';
        // Artemis signs the request method, standard headers, the listed
        // canonical headers, and the complete /artemis resource path.
        $stringToSign = "POST\n{$accept}\n{$contentMd5}\n{$contentType}\n" .
            "x-ca-key:" . HCP_APP_KEY . "\n" .
            "x-ca-nonce:{$nonce}\n" .
            "x-ca-timestamp:{$timestamp}\n" .
            $path;
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, HCP_APP_SECRET, true));

        $ch = curl_init(rtrim(HCP_BASE_URL, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: ' . $accept,
                'Content-MD5: ' . $contentMd5,
                'Content-Type: ' . $contentType,
                'x-ca-key: ' . HCP_APP_KEY,
                'x-ca-nonce: ' . $nonce,
                'x-ca-timestamp: ' . $timestamp,
                'x-ca-signature-headers: ' . $signatureHeaders,
                'x-ca-signature: ' . $signature,
                'userId: ' . HCP_USER_ID,
                'Content-Length: ' . strlen($json),
            ],
            CURLOPT_SSL_VERIFYPEER => HCP_TLS_VERIFY,
            CURLOPT_SSL_VERIFYHOST => HCP_TLS_VERIFY ? 2 : 0,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) throw new HcpOpenApiException('Falha de comunicação com o HikCentral: ' . $error, $httpCode);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string)$raw)) ?? '');
            $detail = $plain !== '' ? mb_substr($plain, 0, 240) : 'resposta não JSON';
            throw new HcpOpenApiException(
                'HikCentral HTTP ' . ($httpCode > 0 ? $httpCode : 'sem status') . ': ' . $detail,
                $httpCode
            );
        }
        $code = (string)($decoded['code'] ?? $decoded['ResponseStatus']['ErrorCode'] ?? '0');
        if ($httpCode >= 400 || ($code !== '0' && strtolower($code) !== 'success')) {
            $message = (string)($decoded['msg'] ?? $decoded['ResponseStatus']['ErrorDescription'] ?? 'Erro sem descrição');
            if (str_contains($message, '60051')) {
                $message = 'Documento ja possui uma reserva/visitante ativo no HikCentral para este periodo. Use outro documento ou finalize/remova a visita anterior.';
            }
            throw new HcpOpenApiException('HikCentral (' . $code . '): ' . $message, $httpCode, $decoded);
        }
        return $decoded;
    }

    private function visitorInfo(array $reservation, string $groupName, ?string $visitorId = null): array
    {
        $info = [
            'visitorFamilyName' => $reservation['last_name'],
            'visitorGivenName' => $reservation['first_name'],
            'visitorGroupName' => $groupName,
            'gender' => ['M' => 1, 'F' => 2, 'N' => 0][$reservation['gender'] ?? 'N'] ?? 0,
            'email' => $reservation['email'] ?? '',
            'phoneNo' => $reservation['phone'] ?? '',
            'companyName' => 'Vale',
            'certificateType' => 111,
            'certificateNo' => hcp_certificate_no($reservation['document_number'] ?? ''),
            'remark' => 'Vale Visitor #' . $reservation['id'],
            'accessInfo' => [
                'electrostaticDetectionType' => 0,
                'qrCodeValidNum' => 1,
            ],
            'faces' => [['faceData' => hcp_base64_image((string)$reservation['photo_path'])]],
        ];
        if ($visitorId !== null && $visitorId !== '') $info['visitorId'] = $visitorId;
        return $info;
    }

    private function accessLevelList(array|string $accessNames): array
    {
        $accessNames = is_array($accessNames) ? $accessNames : [$accessNames];
        $result = [];
        $seen = [];
        foreach (array_unique($accessNames) as $accessName) {
            $level = $this->getVisitorLevel((string)$accessName);
            $levelId = (string)$level['privilegeGroupId'];
            if (isset($seen[$levelId])) continue;
            $seen[$levelId] = true;
            $result[] = ['accessLevel' => [
                'id' => ctype_digit($levelId) ? (int)$levelId : $levelId,
                'baseInfo' => ['name' => $level['privilegeGroupName']],
            ]];
        }
        return $result;
    }

    public function createReservation(array $reservation, string $groupName, array|string $accessNames): array
    {
        return $this->request('/artemis/api/visitor/v2/appointment', [
            'receptionistId' => '',
            'appointStartTime' => iso8601($reservation['entry_at']),
            'appointEndTime' => iso8601($reservation['exit_at']),
            'visitReasonType' => 4,
            'visitReasonDetail' => 'Ingresso diário',
            'accessInfo' => ['accessLevelList' => $this->accessLevelList($accessNames)],
            'visitorInfoList' => [['VisitorInfo' => $this->visitorInfo($reservation, $groupName)]],
        ]);
    }

    public function updateReservation(array $reservation, string $groupName, array|string $accessNames, string $appointmentId, string $visitorId): array
    {
        return $this->request('/artemis/api/visitor/v2/appointment/update', [
            'appointRecordId' => $appointmentId,
            'receptionistId' => '',
            'appointStartTime' => iso8601($reservation['entry_at']),
            'appointEndTime' => iso8601($reservation['exit_at']),
            'visitReasonType' => 4,
            'visitReasonDetail' => 'Ingresso diário',
            'accessInfo' => ['accessLevelList' => $this->accessLevelList($accessNames)],
            'visitorInfoList' => [['VisitorInfo' => $this->visitorInfo($reservation, $groupName, $visitorId)]],
        ]);
    }

    public function registerReservation(array $reservation, string $groupName, array|string $accessNames, string $appointmentId, string $visitorId): array
    {
        return $this->request('/artemis/api/visitor/v1/registerment', [
            'appointId' => $appointmentId,
            'visitorId' => $visitorId,
            'visitStartTime' => iso8601($reservation['entry_at']),
            'visitEndTime' => iso8601($reservation['exit_at']),
            'visitPurposeType' => 4,
            'visitPurpose' => 'Ingresso diario',
            'accessInfo' => ['accessLevelList' => $this->accessLevelList($accessNames)],
            'visitorInfoList' => [['VisitorInfo' => $this->visitorInfo($reservation, $groupName, $visitorId)]],
        ]);
    }

    public function checkoutVisitor(string $appointRecordId): array
    {
        if ($appointRecordId === '') throw new HcpOpenApiException('O ID do registro de check-in e obrigatorio para o checkout.');
        return $this->request('/artemis/api/visitor/v1/visitor/out', ['appointRecordId' => $appointRecordId]);
    }

    public function getVisitorStatus(string $visitorId, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        return $this->request('/artemis/api/visitor/v1/appointment/getVisitorStatus', [
            'visitorId' => $visitorId,
            'visitTimePoint' => $at->format('Y-m-d\\TH:i:sP'),
        ]);
    }

    public function getRegistrationRecordStatus(array $reservation): array
    {
        $visitorId = (string)($reservation['hcp_visitor_id'] ?? '');
        $recordId = (string)($reservation['hcp_registration_id'] ?? '');
        if ($visitorId === '' || $recordId === '' || empty($reservation['entry_at']) || empty($reservation['exit_at'])) {
            throw new HcpOpenApiException('Nao foi possivel conferir o registro de check-in vinculado a esta reserva.');
        }

        $timezone = new DateTimeZone('America/Sao_Paulo');
        $start = new DateTimeImmutable((string)$reservation['entry_at'], $timezone);
        $end = new DateTimeImmutable((string)$reservation['exit_at'], $timezone);
        if ($end <= $start) throw new HcpOpenApiException('O intervalo da reserva e invalido para consultar o registro de check-in.');

        $rangeStart = $start->modify('-1 day');
        $rangeEnd = $end->modify('+1 day');
        if ($rangeEnd->getTimestamp() - $rangeStart->getTimestamp() > 31 * 86400) {
            throw new HcpOpenApiException('O intervalo da reserva excede o limite de busca segura do HikCentral.');
        }

        $pageSize = 499;
        $maxPages = 10;
        $matches = [];
        $total = null;
        for ($pageNo = 1; $pageNo <= $maxPages; $pageNo++) {
            $response = $this->request('/artemis/api/visitor/v1/register/getVistorRegisterRecord', [
                'pageNo' => $pageNo,
                'pageSize' => $pageSize,
                'visitStartTime' => $rangeStart->format('Y-m-d\\TH:i:sP'),
                'visitEndTime' => $rangeEnd->format('Y-m-d\\TH:i:sP'),
                'sortField' => 'visitingTime',
                'orderType' => '0',
            ]);
            $data = $response['data'] ?? [];
            $rows = $data['list'] ?? null;
            if (!is_array($rows)) throw new HcpOpenApiException('O HikCentral retornou uma lista de registros invalida.');
            if (isset($data['totalNum'])) $total = max(0, (int)$data['totalNum']);
            if ($total !== null && $total > $pageSize * $maxPages) {
                throw new HcpOpenApiException('Ha registros demais no intervalo para confirmar a visita sem ambiguidade.');
            }

            foreach ($rows as $row) {
                $base = $row['visitorBaseInfo'] ?? [];
                if (!is_array($base) || (string)($row['recordId'] ?? '') !== $recordId || (string)($base['visitorId'] ?? '') !== $visitorId) {
                    continue;
                }
                if (empty($base['visitStartTime']) || empty($base['visitEndTime'])) continue;
                try {
                    $rowStart = new DateTimeImmutable((string)$base['visitStartTime'], $timezone);
                    $rowEnd = new DateTimeImmutable((string)$base['visitEndTime'], $timezone);
                } catch (Throwable) {
                    continue;
                }
                if ($rowStart->getTimestamp() === $start->getTimestamp() && $rowEnd->getTimestamp() === $end->getTimestamp()) {
                    $matches[] = $row;
                }
            }

            $hasMore = $total !== null ? $pageNo * $pageSize < $total : count($rows) === $pageSize;
            if (!$hasMore) break;
            if ($pageNo === $maxPages) throw new HcpOpenApiException('A busca atingiu o limite de paginas; nenhum checkout foi enviado.');
        }

        if (count($matches) !== 1) {
            throw new HcpOpenApiException('O registro exato desta reserva nao foi encontrado de forma unica no HikCentral.');
        }

        $recordStatus = (string)($matches[0]['visitorStatus'] ?? '');
        $statusMap = ['0' => '3', '1' => '4', '2' => '5', '3' => '6', '4' => '7'];
        if (!isset($statusMap[$recordStatus])) throw new HcpOpenApiException('O HikCentral retornou um status de registro desconhecido.');

        return [
            'data' => ['visitorStatus' => $statusMap[$recordStatus]],
            'registrationRecordStatus' => $recordStatus,
        ];
    }

    public function findAppointmentId(array $reservation, string $visitorId): string
    {
        $start = new DateTimeImmutable($reservation['entry_at'], new DateTimeZone('America/Sao_Paulo'));
        $end = new DateTimeImmutable($reservation['exit_at'], new DateTimeZone('America/Sao_Paulo'));
        $rangeStart = $start->setTime(0, 0)->format('Y-m-d\\TH:i:sP');
        $rangeEnd = $end->modify('+1 day')->setTime(0, 0)->format('Y-m-d\\TH:i:sP');
        $response = $this->request('/artemis/api/visitor/v1/appointment/appointmentlist', [
            'pageNo' => 1,
            'pageSize' => 100,
            'appointStartTime' => $rangeStart,
            'appointEndTime' => $rangeEnd,
            'appointState' => -1,
            'orderType' => 0,
        ]);
        $matches = [];
        foreach (($response['data']['list'] ?? []) as $row) {
            $info = $row['visitorInfo'] ?? $row['VisitorInfo'] ?? [];
            if ((string)($info['visitorId'] ?? '') === $visitorId && !empty($row['appointID'])) {
                $matches[] = (string)$row['appointID'];
            }
        }
        $matches = array_values(array_unique($matches));
        if (count($matches) !== 1) {
            throw new HcpOpenApiException('Reserva sincronizada, mas nÃ£o foi possÃ­vel identificar um Ãºnico ID atualizado no HikCentral.');
        }
        return $matches[0];
    }

    public function getVisitorLevel(string $name): array
    {
        // HikCentral currently has this level under the misspelled name.
        // Keep the intended spelling in the local UI while resolving that level.
        $remoteName = $name === 'SAIDA ACQUAVALE' ? 'SAIDA AQCUAVALE' : $name;
        $matches = [];
        for ($page = 1; ; $page++) {
            $data = $this->request('/artemis/api/acs/v1/privilege/group', [
                'pageNo' => $page, 'pageSize' => 100, 'type' => 2,
            ])['data'] ?? [];
            foreach (($data['list'] ?? []) as $level) {
                if (($level['privilegeGroupName'] ?? '') === $remoteName) $matches[] = $level;
            }
            if ($page * 100 >= (int)($data['total'] ?? 0)) break;
        }
        if (count($matches) !== 1 || empty($matches[0]['ElementList'])) {
            throw new HcpOpenApiException('O segmento selecionado deve existir no Visitor e possuir catracas vinculadas.');
        }
        return $matches[0];
    }

    public function reapplyVisitorAccess(string $visitorId, array $doorIds): array
    {
        if ($visitorId === '' || !$doorIds) throw new HcpOpenApiException('Visitante e catracas de destino obrigatorios.');
        return $this->request('/artemis/api/visitor/v1/auth/reapplication', [
            'orderId' => '',
            'ImmediateDownload' => 0,
            'personIds' => $visitorId,
            'doorIndexCodes' => implode(',', $doorIds),
        ]);
    }
}
