<?php
declare(strict_types=1);

require_once __DIR__ . '/delivery.php';


function aqv_ensure_schema(): void
{
    static $done=false;
    if ($done) return;
    $done=true;
    $pdo=db();
    foreach (['source_system VARCHAR(60) NULL','source_order_code VARCHAR(64) NULL','source_ticket_code VARCHAR(64) NULL'] as $definition) {
        [$column]=explode(' ',$definition,2);
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND COLUMN_NAME=?");
        $st->execute([$column]);
        if ((int)$st->fetchColumn()===0) $pdo->exec('ALTER TABLE reservations ADD COLUMN '.$definition);
    }
    $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND INDEX_NAME='uq_res_source_ticket'");
    $st->execute();
    if ((int)$st->fetchColumn()===0) $pdo->exec('ALTER TABLE reservations ADD UNIQUE KEY uq_res_source_ticket (source_system,source_ticket_code)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS acquavale_import_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_code VARCHAR(64) NOT NULL UNIQUE, remote_order_id VARCHAR(64) NULL,
        claim_token CHAR(64) NOT NULL, consumer VARCHAR(100) NOT NULL DEFAULT 'vale-visitor', buyer_email VARCHAR(190) NULL,
        buyer_phone VARCHAR(40) NULL,total DECIMAL(12,2) NULL,paid_at DATETIME NULL,reservation_code VARCHAR(100) NULL,
        payload_json LONGTEXT NOT NULL,state ENUM('received','processing','confirmed','failed','acked') NOT NULL DEFAULT 'received',
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,last_attempt_at DATETIME NULL,last_error TEXT NULL,received_at DATETIME NOT NULL,
        acked_at DATETIME NULL,updated_at DATETIME NOT NULL,INDEX idx_aqv_import_state(state,last_attempt_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS acquavale_import_tickets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,import_order_id BIGINT UNSIGNED NOT NULL,ticket_code VARCHAR(64) NOT NULL UNIQUE,
        source_visitor_id VARCHAR(64) NULL,product_name VARCHAR(190) NULL,valid_from DATE NOT NULL,valid_to DATE NOT NULL,photo_url TEXT NULL,
        photo_path VARCHAR(255) NULL,photo_sha256 CHAR(64) NULL,photo_mime_type VARCHAR(80) NULL,photo_size INT UNSIGNED NULL,
        local_reservation_id BIGINT UNSIGNED NULL,state ENUM('received','processing','photo_saved','local_created','syncing','confirmed','failed','acked') NOT NULL DEFAULT 'received',
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,last_attempt_at DATETIME NULL,last_error TEXT NULL,hcp_delivery_report LONGTEXT NULL,
        confirmed_at DATETIME NULL,acked_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
        CONSTRAINT fk_aqv_import_ticket_order FOREIGN KEY(import_order_id) REFERENCES acquavale_import_orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_aqv_import_ticket_reservation FOREIGN KEY(local_reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
        INDEX idx_aqv_ticket_state(state,last_attempt_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS acquavale_webhook_deliveries (
        delivery_id VARCHAR(128) PRIMARY KEY,import_order_id BIGINT UNSIGNED NOT NULL,received_at DATETIME NOT NULL,
        CONSTRAINT fk_aqv_delivery_order FOREIGN KEY(import_order_id) REFERENCES acquavale_import_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

aqv_ensure_schema();


function aqv_configured(): bool
{
    $parts = parse_url(AQV_WEB_BASE_URL);
    return AQV_SHARED_SECRET !== ''
        && strlen(AQV_SHARED_SECRET) >= 32
        && AQV_WEB_BASE_URL !== ''
        && AQV_WEB_API_KEY !== ''
        && is_array($parts)
        && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)
        && trim((string)($parts['host'] ?? '')) !== '';
}

function aqv_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function aqv_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

function aqv_verify_signature(string $raw): string
{
    if (!aqv_configured()) throw new RuntimeException('Integração AcquaVale não configurada.');

    $timestamp = aqv_header('X-AQV-Timestamp');
    $deliveryId = aqv_header('X-AQV-Delivery-Id');
    $signature = aqv_header('X-AQV-Signature');

    if ($timestamp === '' || !ctype_digit($timestamp)) throw new RuntimeException('Timestamp ausente ou inválido.');
    if ($deliveryId === '' || strlen($deliveryId) > 128) throw new RuntimeException('Delivery ID ausente ou inválido.');
    if (!str_starts_with($signature, 'sha256=')) throw new RuntimeException('Assinatura ausente ou inválida.');

    $skew = abs(time() - (int)$timestamp);
    if ($skew > AQV_SIGNATURE_MAX_SKEW_SECONDS) throw new RuntimeException('Webhook expirado.');

    $expected = hash_hmac('sha256', $timestamp . "\n" . $raw, AQV_SHARED_SECRET);
    $provided = substr($signature, 7);
    if (!hash_equals($expected, $provided)) throw new RuntimeException('Assinatura inválida.');

    return $deliveryId;
}

function aqv_accept_payload(array $payload, string $deliveryId): int
{
    if (($payload['event'] ?? '') !== 'sale.paid') throw new InvalidArgumentException('Evento não suportado.');
    $order = $payload['order'] ?? null;
    if (!is_array($order)) throw new InvalidArgumentException('Pedido ausente.');

    $orderCode = trim((string)($order['order_code'] ?? ''));
    $claim = trim((string)($order['claim_token'] ?? ''));
    $tickets = $order['tickets'] ?? null;
    if ($orderCode === '' || $claim === '' || !is_array($tickets) || !$tickets) {
        throw new InvalidArgumentException('Pedido incompleto.');
    }
    $reservation = is_array($order['reservation'] ?? null) ? $order['reservation'] : [];
    $checkin = trim((string)($reservation['checkin'] ?? ''));
    $checkout = trim((string)($reservation['checkout'] ?? ''));
    $checkinDate = $checkin !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $checkin) : null;
    $checkoutDate = $checkout !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $checkout) : null;
    if ($checkin !== '' && (!$checkinDate || $checkinDate->format('Y-m-d') !== $checkin)) {
        throw new InvalidArgumentException('Data de check-in invalida.');
    }
    if ($checkout !== '' && (!$checkoutDate || $checkoutDate->format('Y-m-d') !== $checkout)) {
        throw new InvalidArgumentException('Data de check-out invalida.');
    }
    if ($checkin !== '' && $checkout !== '' && $checkout < $checkin) {
        throw new InvalidArgumentException('O check-out nao pode ser anterior ao check-in.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT import_order_id FROM acquavale_webhook_deliveries WHERE delivery_id=?');
        $st->execute([$deliveryId]);
        $existing = (int)($st->fetchColumn() ?: 0);
        if ($existing > 0) {
            $pdo->commit();
            return $existing;
        }

        $st = $pdo->prepare('SELECT id FROM acquavale_import_orders WHERE order_code=? FOR UPDATE');
        $st->execute([$orderCode]);
        $importOrderId = (int)($st->fetchColumn() ?: 0);

        if ($importOrderId === 0) {
            $st = $pdo->prepare(
                "INSERT INTO acquavale_import_orders
                 (order_code,remote_order_id,claim_token,consumer,buyer_email,buyer_phone,total,paid_at,reservation_code,payload_json,state,received_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'received',NOW(),NOW())"
            );
            $st->execute([
                $orderCode,
                (string)($order['id'] ?? '') ?: null,
                $claim,
                (string)($payload['consumer'] ?? 'vale-visitor'),
                trim((string)($order['buyer_email'] ?? '')) ?: null,
                trim((string)($order['buyer_phone'] ?? '')) ?: null,
                isset($order['total']) ? (float)$order['total'] : null,
                trim((string)($order['paid_at'] ?? '')) ?: null,
                trim((string)($order['reservation']['code'] ?? '')) ?: null,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $importOrderId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare(
                "UPDATE acquavale_import_orders
                 SET claim_token=?,consumer=?,payload_json=?,last_error=NULL,updated_at=NOW()
                 WHERE id=?"
            )->execute([
                $claim,
                (string)($payload['consumer'] ?? 'vale-visitor'),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $importOrderId,
            ]);
        }

        $ticketInsert = $pdo->prepare(
            "INSERT INTO acquavale_import_tickets
             (import_order_id,ticket_code,source_visitor_id,product_name,valid_from,valid_to,photo_url,state,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,'received',NOW(),NOW())
             ON DUPLICATE KEY UPDATE
                import_order_id=VALUES(import_order_id),
                source_visitor_id=VALUES(source_visitor_id),
                product_name=VALUES(product_name),
                valid_from=VALUES(valid_from),valid_to=VALUES(valid_to),photo_url=VALUES(photo_url),updated_at=NOW()"
        );
        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) continue;
            $ticketCode = trim((string)($ticket['ticket_code'] ?? ''));
            if ($ticketCode === '') throw new InvalidArgumentException('Ticket sem código.');
            $validFrom = trim((string)($ticket['valid_from'] ?? ''));
            $validTo = trim((string)($ticket['valid_to'] ?? ''));
            $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom);
            $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $validTo);
            if (!$fromDate || $fromDate->format('Y-m-d') !== $validFrom || !$toDate || $toDate->format('Y-m-d') !== $validTo || $validTo < $validFrom) {
                throw new InvalidArgumentException('Validade do ticket invalida.');
            }
            $ticketInsert->execute([
                $importOrderId,
                $ticketCode,
                (string)($ticket['visitor_id'] ?? '') ?: null,
                trim((string)($ticket['product_name'] ?? '')) ?: null,
                $validFrom,
                $validTo,
                trim((string)($ticket['photo_url'] ?? '')) ?: null,
            ]);
        }

        $pdo->prepare('INSERT INTO acquavale_webhook_deliveries (delivery_id,import_order_id,received_at) VALUES (?,?,NOW())')
            ->execute([$deliveryId, $importOrderId]);
        $pdo->commit();
        return $importOrderId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function aqv_payload_ticket(array $orderPayload, string $ticketCode): array
{
    foreach (($orderPayload['order']['tickets'] ?? []) as $ticket) {
        if (is_array($ticket) && (string)($ticket['ticket_code'] ?? '') === $ticketCode) return $ticket;
    }
    throw new RuntimeException('Ticket não existe mais no payload recebido.');
}

function aqv_download_photo(string $url, string $ticketCode): array
{
    if ($url === '') throw new RuntimeException('Foto do visitante não foi informada pelo site.');
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)) {
        throw new RuntimeException('URL de foto inválida.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . AQV_WEB_API_KEY, 'Accept: image/*'],
        CURLOPT_SSL_VERIFYPEER => AQV_WEB_TLS_VERIFY,
        CURLOPT_SSL_VERIFYHOST => AQV_WEB_TLS_VERIFY ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
    curl_close($ch);

    if ($body === false) throw new RuntimeException('Falha ao baixar foto: ' . $error);
    if ($http !== 200) throw new RuntimeException('Site de vendas retornou HTTP ' . $http . ' ao baixar a foto.');
    if (strlen($body) < 100 || strlen($body) > AQV_MAX_PHOTO_BYTES) throw new RuntimeException('Tamanho de foto inválido.');

    $mime = trim(explode(';', $contentType)[0]);
    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($body);
    if ($detectedMime !== $mime) throw new RuntimeException('Conteudo de foto invalido.');
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('Formato de foto não suportado: ' . $mime);

    $dir = __DIR__ . '/storage/private/acquavale_faces/' . date('Y/m');
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar o diretório privado de fotos.');

    $safeTicket = preg_replace('/[^A-Za-z0-9_-]/', '_', $ticketCode) ?: 'ticket';
    $name = $safeTicket . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $absolute = $dir . '/' . $name;
    $tmp = $absolute . '.tmp';
    if (file_put_contents($tmp, $body, LOCK_EX) === false) throw new RuntimeException('Não foi possível salvar a foto temporária.');
    if (!rename($tmp, $absolute)) { @unlink($tmp); throw new RuntimeException('Não foi possível finalizar a foto.'); }

    $relative = substr($absolute, strlen(__DIR__) + 1);
    return ['path'=>str_replace('\\','/',$relative),'sha256'=>hash('sha256',$body),'mime'=>$mime,'size'=>strlen($body)];
}

function aqv_group_id(): int
{
    $st = db()->prepare('SELECT id FROM guest_groups WHERE active=1 AND name=? LIMIT 1');
    $st->execute(['Day use']);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id < 1) throw new RuntimeException('Grupo local "Day use" não encontrado.');
    return $id;
}

function aqv_access_level_ids(): array
{
    $names = ['ENTRADA ACQUAVALE','SAIDA ACQUAVALE'];
    $st = db()->prepare('SELECT id,name FROM access_levels WHERE active=1 AND name IN (?,?)');
    $st->execute($names);
    $rows = $st->fetchAll();
    $map = [];
    foreach ($rows as $row) $map[(string)$row['name']] = (int)$row['id'];
    foreach ($names as $name) if (empty($map[$name])) throw new RuntimeException('Access level local não encontrado: ' . $name);
    return [$map[$names[0]], $map[$names[1]]];
}

function aqv_create_local_reservation(array $ticket, array $visitor, string $orderCode, string $photoPath): int
{
    ensure_visitor_flow_column();
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM reservations WHERE source_system='acquavale_vendas' AND source_ticket_code=? LIMIT 1");
    $st->execute([(string)$ticket['ticket_code']]);
    $existing = (int)($st->fetchColumn() ?: 0);
    if ($existing > 0) return $existing;

    $entryAt = (new DateTimeImmutable((string)$ticket['valid_from'], new DateTimeZone('America/Sao_Paulo')))->setTime(0,0,0)->format('Y-m-d H:i:s');
    $exitAt = (new DateTimeImmutable((string)$ticket['valid_to'], new DateTimeZone('America/Sao_Paulo')))->setTime(23,59,59)->format('Y-m-d H:i:s');
    $groupId = aqv_group_id();
    $levels = aqv_access_level_ids();
    $gender = match (strtolower((string)($visitor['sex'] ?? ''))) { 'feminino'=>'F', 'masculino'=>'M', default=>'N' };
    $qrToken = bin2hex(random_bytes(24));

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "INSERT INTO reservations
             (first_name,last_name,email,phone,group_id,access_level_id,entry_at,exit_at,document_type,document_number,gender,photo_path,qr_token,qr_payload,visitor_flow_status,source_system,source_order_code,source_ticket_code)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'REGISTERED','acquavale_vendas',?,?)"
        );
        $st->execute([
            trim((string)($visitor['first_name'] ?? '')),
            trim((string)($visitor['last_name'] ?? '')),
            trim((string)($visitor['email'] ?? '')) ?: null,
            trim((string)($visitor['phone'] ?? '')) ?: null,
            $groupId,
            $levels[0],
            $entryAt,
            $exitAt,
            in_array(($visitor['document_type'] ?? ''), ['CPF','RG','CNH'], true) ? $visitor['document_type'] : 'OUTRO',
            trim((string)($visitor['document_number'] ?? '')) ?: null,
            $gender,
            $photoPath,
            $qrToken,
            'AQV:' . $ticket['ticket_code'],
            $orderCode,
            (string)$ticket['ticket_code'],
        ]);
        $reservationId = (int)$pdo->lastInsertId();
        $link = $pdo->prepare('INSERT IGNORE INTO reservation_access_levels (reservation_id,access_level_id) VALUES (?,?)');
        foreach ($levels as $levelId) $link->execute([$reservationId,$levelId]);
        $pdo->commit();
        return $reservationId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int)($e instanceof PDOException ? ($e->errorInfo[1] ?? 0) : 0) === 1062) {
            $st = $pdo->prepare("SELECT id FROM reservations WHERE source_system='acquavale_vendas' AND source_ticket_code=? LIMIT 1");
            $st->execute([(string)$ticket['ticket_code']]);
            $existing = (int)($st->fetchColumn() ?: 0);
            if ($existing > 0) return $existing;
        }
        throw $e;
    }
}

function aqv_site_post(string $action, array $payload): array
{
    $url = rtrim(AQV_WEB_BASE_URL, '/') . '/api.php?action=' . rawurlencode($action);
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $raw,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . AQV_WEB_API_KEY, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => AQV_WEB_TLS_VERIFY,
        CURLOPT_SSL_VERIFYHOST => AQV_WEB_TLS_VERIFY ? 2 : 0,
    ]);
    $body = curl_exec($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($body === false) throw new RuntimeException('Falha no callback ao site: ' . $error);
    $json = json_decode((string)$body, true);
    if ($http < 200 || $http >= 300 || !is_array($json) || empty($json['ok'])) {
        $message = is_array($json) ? (string)($json['error'] ?? $json['message'] ?? '') : '';
        throw new RuntimeException('Callback ao site HTTP ' . $http . ($message !== '' ? ': ' . $message : ''));
    }
    return $json;
}

function aqv_callback_ticket(array $importOrder, array $importTicket, string $state, ?array $report = null, ?string $message = null): void
{
    $reservation = null;
    if (!empty($importTicket['local_reservation_id'])) {
        $st = db()->prepare('SELECT * FROM reservations WHERE id=?');
        $st->execute([(int)$importTicket['local_reservation_id']]);
        $reservation = $st->fetch() ?: null;
    }
    aqv_site_post('sale-ticket-status', [
        'consumer' => $importOrder['consumer'] ?: 'vale-visitor',
        'order_code' => $importOrder['order_code'],
        'ticket_code' => $importTicket['ticket_code'],
        'state' => $state,
        'external_reservation_id' => $reservation ? (string)$reservation['id'] : null,
        'hcp_visitor_id' => $reservation['hcp_visitor_id'] ?? null,
        'hcp_reference' => $reservation['hcp_reference'] ?? null,
        'message' => $message,
        'details' => $report,
    ]);
}

function aqv_process_ticket(int $ticketId): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT t.*,o.order_code,o.claim_token,o.consumer,o.payload_json FROM acquavale_import_tickets t JOIN acquavale_import_orders o ON o.id=t.import_order_id WHERE t.id=?');
    $st->execute([$ticketId]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('Importação não encontrada.');
    if (in_array($row['state'], ['confirmed','acked'], true)) return ['state'=>$row['state'],'ticket_code'=>$row['ticket_code']];

    $payload = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)) throw new RuntimeException('Payload local inválido.');
    $ticket = aqv_payload_ticket($payload, (string)$row['ticket_code']);

    try {
        $pdo->prepare("UPDATE acquavale_import_tickets SET state='processing',attempt_count=attempt_count+1,last_attempt_at=NOW(),last_error=NULL,updated_at=NOW() WHERE id=?")->execute([$ticketId]);
        aqv_callback_ticket($row, $row, 'syncing', null, 'Vale Visitor iniciou o processamento.');

        $photoPath = (string)($row['photo_path'] ?? '');
        if ($photoPath === '') {
            $photo = aqv_download_photo((string)($ticket['photo_url'] ?? ''), (string)$row['ticket_code']);
            $photoPath = $photo['path'];
            $pdo->prepare("UPDATE acquavale_import_tickets SET photo_path=?,photo_sha256=?,photo_mime_type=?,photo_size=?,state='photo_saved',updated_at=NOW() WHERE id=?")
                ->execute([$photo['path'],$photo['sha256'],$photo['mime'],$photo['size'],$ticketId]);
        }

        $reservationId = (int)($row['local_reservation_id'] ?? 0);
        if ($reservationId < 1) {
            $reservationId = aqv_create_local_reservation($ticket, $ticket, (string)$row['order_code'], $photoPath);
            $pdo->prepare("UPDATE acquavale_import_tickets SET local_reservation_id=?,state='local_created',updated_at=NOW() WHERE id=?")->execute([$reservationId,$ticketId]);
        }

        $report = visitor_sync($reservationId);
        $state = (string)($report['state'] ?? 'queued');
        $mappedState = $state === 'confirmed' ? 'confirmed' : ($state === 'failed' ? 'failed' : 'syncing');
        $pdo->prepare("UPDATE acquavale_import_tickets SET state=?,hcp_delivery_report=?,confirmed_at=IF(?='confirmed',NOW(),confirmed_at),last_error=?,updated_at=NOW() WHERE id=?")
            ->execute([$mappedState,json_encode($report,JSON_UNESCAPED_UNICODE),$mappedState,$mappedState==='failed'?'HikCentral informou falha na entrega.':null,$ticketId]);
        $st = $pdo->prepare('SELECT * FROM acquavale_import_tickets WHERE id=?'); $st->execute([$ticketId]); $fresh = $st->fetch();
        aqv_callback_ticket($row, $fresh, $mappedState, $report, $mappedState==='confirmed'?'HikCentral confirmou face e credenciais.':'Aguardando confirmação do HikCentral.');
        return ['state'=>$mappedState,'ticket_code'=>$row['ticket_code'],'report'=>$report];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE acquavale_import_tickets SET state='failed',last_error=?,updated_at=NOW() WHERE id=?")->execute([mb_substr($e->getMessage(),0,2000),$ticketId]);
        try { aqv_callback_ticket($row, $row, 'failed', null, $e->getMessage()); } catch (Throwable) {}
        throw $e;
    }
}

function aqv_finalize_order(int $importOrderId): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM acquavale_import_orders WHERE id=?'); $st->execute([$importOrderId]); $order = $st->fetch();
    if (!$order) throw new RuntimeException('Pedido importado não encontrado.');

    $st = $pdo->prepare("SELECT COUNT(*) total,SUM(state='confirmed' OR state='acked') confirmed FROM acquavale_import_tickets WHERE import_order_id=?");
    $st->execute([$importOrderId]); $counts = $st->fetch();
    $total = (int)$counts['total']; $confirmed = (int)$counts['confirmed'];
    if ($total < 1 || $confirmed !== $total) return ['acked'=>false,'confirmed'=>$confirmed,'total'=>$total];

    aqv_site_post('sale-ack', [
        'consumer' => $order['consumer'] ?: 'vale-visitor',
        'order_code' => $order['order_code'],
        'claim_token' => $order['claim_token'],
        'external_reference' => 'vale-visitor:' . $importOrderId,
    ]);
    $pdo->prepare("UPDATE acquavale_import_orders SET state='acked',acked_at=NOW(),last_error=NULL,updated_at=NOW() WHERE id=?")->execute([$importOrderId]);
    $pdo->prepare("UPDATE acquavale_import_tickets SET state='acked',acked_at=NOW(),updated_at=NOW() WHERE import_order_id=? AND state='confirmed'")->execute([$importOrderId]);
    return ['acked'=>true,'confirmed'=>$confirmed,'total'=>$total];
}

function aqv_process_order(int $importOrderId): array
{
    $pdo = db();
    $lock = $pdo->prepare('SELECT GET_LOCK(?,0)'); $lock->execute(['aqv_import_' . $importOrderId]);
    if ((int)$lock->fetchColumn() !== 1) return ['ok'=>true,'locked'=>true];
    try {
        $pdo->prepare("UPDATE acquavale_import_orders SET state='processing',attempt_count=attempt_count+1,last_attempt_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$importOrderId]);
        $st = $pdo->prepare("SELECT id FROM acquavale_import_tickets WHERE import_order_id=? AND state NOT IN ('confirmed','acked') ORDER BY id");
        $st->execute([$importOrderId]);
        $results=[];
        $errors=[];
        foreach ($st->fetchAll() as $ticket) {
            try { $results[] = aqv_process_ticket((int)$ticket['id']); }
            catch (Throwable $e) {
                $errors[]=['ticket_id'=>(int)$ticket['id'],'error'=>$e->getMessage()];
                $results[]=['state'=>'failed','error'=>$e->getMessage()];
            }
        }
        $final = aqv_finalize_order($importOrderId);
        $state = !empty($final['acked']) ? 'acked' : ($errors ? 'failed' : 'processing');
        $message = $errors ? mb_substr(implode(' | ', array_column($errors, 'error')), 0, 2000) : null;
        $pdo->prepare('UPDATE acquavale_import_orders SET state=?,last_error=?,updated_at=NOW() WHERE id=?')->execute([$state,$message,$importOrderId]);
        return ['ok'=>!$errors,'tickets'=>$results,'final'=>$final,'errors'=>$errors];
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['aqv_import_' . $importOrderId]);
    }
}

function aqv_process_pending(int $limit = 20): array
{
    $limit = max(1,min(100,$limit));
    $rows = db()->query("SELECT id FROM acquavale_import_orders WHERE state IN ('received','processing','failed') ORDER BY COALESCE(last_attempt_at,'1970-01-01'),id LIMIT {$limit}")->fetchAll();
    $result=['processed'=>0,'acked'=>0,'errors'=>[]];
    foreach ($rows as $row) {
        $result['processed']++;
        try {
            $r=aqv_process_order((int)$row['id']);
            if (!empty($r['final']['acked'])) $result['acked']++;
            foreach (($r['errors'] ?? []) as $error) $result['errors'][]=['id'=>(int)$row['id']]+$error;
        }
        catch (Throwable $e) { $result['errors'][]=['id'=>(int)$row['id'],'error'=>$e->getMessage()]; }
    }
    return $result;
}

// Recupera vendas mesmo quando o webhook nao consegue chegar a rede local.
function aqv_pull_sales(int $limit = 20): array
{
    $result = ['received'=>0, 'orders'=>[]];
    for ($i = 0; $i < max(1, min(100, $limit)); $i++) {
        $response = aqv_site_post('sale-next', ['consumer'=>'vale-visitor']);
        if (!array_key_exists('sale', $response)) {
            throw new RuntimeException('A loja retornou uma resposta sem o campo sale.');
        }
        if ($response['sale'] === null) break;
        if (!is_array($response['sale'])) throw new RuntimeException('Venda retornada pela loja invalida.');
        $sale = $response['sale'];
        $payload = ['version'=>1, 'event'=>'sale.paid', 'consumer'=>'vale-visitor', 'order'=>$sale];
        $deliveryId = hash('sha256', (string)($sale['order_code'] ?? '') . "\n" . (string)($sale['claim_token'] ?? ''));
        $id = aqv_accept_payload($payload, $deliveryId);
        $result['received']++;
        $result['orders'][] = $id;
    }
    return $result;
}

function aqv_sync_sales(int $limit = 20): array
{
    $pdo = db();
    $lock = $pdo->query("SELECT GET_LOCK('aqv_sales_sync',0)");
    if ((int)$lock->fetchColumn() !== 1) {
        return ['received'=>0, 'processed'=>0, 'acked'=>0, 'errors'=>[], 'locked'=>true];
    }
    try {
        $received = 0;
        $errors = [];
        try {
            $pull = aqv_pull_sales($limit);
            $received = $pull['received'];
        } catch (Throwable $e) {
            $errors[] = ['stage'=>'receive', 'error'=>$e->getMessage()];
        }
        // Uma indisponibilidade da loja nao impede retries de vendas ja salvas.
        $result = aqv_process_pending($limit);
        $result['received'] = $received;
        $result['errors'] = array_merge($errors, $result['errors']);
        return $result;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('aqv_sales_sync')");
    }
}
