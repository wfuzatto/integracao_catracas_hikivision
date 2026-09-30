<?php
declare(strict_types=1);

// Pure interpretation: a successful API call alone never confirms delivery.
function visitor_parse_delivery(array $data, array $reservation, array $level): array
{
    $details = $data['ElementDetailList']['ElementDetail'] ?? [];
    $indexed = [];
    foreach ($details as $detail) $indexed[(string)($detail['elementID'] ?? $detail['ID'] ?? '')] = $detail;
    $doors = [];
    foreach ($level['ElementList'] ?? [] as $entry) {
        $element = $entry['Element'];
        $id = (string)$element['ID'];
        $detail = $indexed[$id] ?? [];
        $status = $detail['ElementStatus'][0]['elementStatus'] ?? null;
        $certs = $detail['CertificateStatusList']['CertificateStatus'] ?? [];
        $face = 'queued'; $credential = 'queued';
        $failed = $status !== null && (int)$status === 2;
        $allOk = $certs !== [];
        $safeCerts = []; $errors = [];
        foreach ($certs as $cert) {
            $type = (int)($cert['type'] ?? -1);
            $cs = (int)($cert['status'] ?? -1);
            $state = $cs === 0 ? 'confirmed' : ($cs === 2 ? 'failed' : 'queued');
            if ($type === 2 && $face !== 'failed') $face = $state;
            if (in_array($type, [0,4], true) && $credential !== 'failed') $credential = $state;
            if ($cs === 2) {
                $failed = true;
                $errors[] = ($type === 2 ? 'Foto facial' : 'Credencial tipo ' . $type) . ' recusada';
            }
            if ($cs !== 0) $allOk = false;
            // Keep only diagnostic fields, never credential values or face data.
            $diagnostic = array_intersect_key($cert, array_flip(['error Code','error Msg','errorCode','errorMsg','errorMessage','errorDescription','ErrorCode','ErrorDescription']));
            $safeCerts[] = ['type'=>$type,'status'=>$cs] + $diagnostic;
        }
        $confirmed = $status !== null && (int)$status === 0 && $allOk
            && $credential === 'confirmed' && (empty($reservation['photo_path']) || $face === 'confirmed');
        $doors[] = ['id'=>$id,'name'=>$element['BaseInfo']['Name'] ?? $id,
            'state'=>$confirmed ? 'confirmed' : ($failed ? 'failed' : 'queued'),
            'face'=>$face === 'confirmed','credential'=>$credential === 'confirmed',
            'faceState'=>$face,'credentialState'=>$credential,'certificates'=>$safeCerts,
            'message'=>implode('; ', array_unique($errors)),
            'diagnostic'=>array_intersect_key($detail['ElementStatus'][0] ?? [], array_flip(['element Error Code','element Error Msg','errorCode','errorMsg','errorMessage','errorDescription','ErrorCode','ErrorDescription']))];
    }
    $states = array_column($doors, 'state');
    $state = in_array('failed', $states, true) ? 'failed'
        : ($doors && !in_array('queued', $states, true) ? 'confirmed' : 'queued');
    $faceFailures = count(array_filter($doors, fn($d) => $d['faceState'] === 'failed'));
    $message = $state === 'failed'
        ? ($faceFailures ? 'Foto facial recusada em ' . $faceFailures . ' catraca(s). Verifique a foto e o diagnostico de entrega.' : 'Falha na entrega de credenciais nas catracas. Consulte o diagnostico.')
        : ($state === 'confirmed' ? 'HikCentral confirmou a entrega nas catracas.' : 'Aguardando confirmacao de todas as catracas.');
    return ['state'=>$state,'message'=>$message,'segment'=>$level['privilegeGroupName'] ?? '',
        'levelId'=>(string)($level['privilegeGroupId'] ?? ''),'source'=>'HikCentral','checkedAt'=>date(DATE_ATOM),'doors'=>$doors];
}
