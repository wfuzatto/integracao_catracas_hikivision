<?php
declare(strict_types=1);
require_once __DIR__ . '/hcp.php';
ensure_visitor_flow_column();

function visitor_reservation(int $id): array {
    $st = db()->prepare('SELECT r.*, g.name group_name, a.name access_name FROM reservations r LEFT JOIN guest_groups g ON g.id=r.group_id LEFT JOIN access_levels a ON a.id=r.access_level_id WHERE r.id=?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new RuntimeException('Reserva nao encontrada.');
    $levels = db()->prepare('SELECT a.id,a.name FROM reservation_access_levels ra JOIN access_levels a ON a.id=ra.access_level_id WHERE ra.reservation_id=? ORDER BY a.name');
    $levels->execute([$id]);
    $selected = $levels->fetchAll();
    if (!$selected && !empty($r['access_level_id'])) $selected = [['id'=>$r['access_level_id'],'name'=>$r['access_name']]];
    if (!$selected) throw new RuntimeException('A reserva precisa ter pelo menos um access level.');
    $r['access_names'] = array_values(array_unique(array_column($selected, 'name')));
    $r['access_name'] = implode(' + ', $r['access_names']);
    return $r;
}

function visitor_combined_level(HcpOpenApiClient $client, array $r): array {
    $levels = [];
    $elements = [];
    foreach ($r['access_names'] as $name) {
        $level = $client->getVisitorLevel((string)$name);
        $levels[(string)$level['privilegeGroupId']] = $level;
        foreach ($level['ElementList'] as $entry) {
            $elements[(string)$entry['Element']['ID']] = $entry;
        }
    }
    if (!$levels || !$elements) throw new RuntimeException('Os access levels devem existir no HikCentral e ter catracas vinculadas.');
    return [
        'privilegeGroupId'=>implode(',', array_keys($levels)),
        'privilegeGroupName'=>implode(' + ', $r['access_names']),
        'ElementList'=>array_values($elements),
        'SelectedLevels'=>array_values($levels),
    ];
}

function visitor_assert_no_document_conflict(array $r): void {
    $document = hcp_certificate_no($r['document_number'] ?? '');
    if ($document === '') return;
    $st = db()->prepare(
        'SELECT id,first_name,last_name,entry_at,exit_at,status
         FROM reservations
         WHERE id<>?
           AND document_number IS NOT NULL
           AND REPLACE(REPLACE(REPLACE(document_number,".",""),"-","")," ","")=?
           AND status<>"CANCELLED"
           AND entry_at < ?
           AND exit_at > ?
         ORDER BY id DESC
         LIMIT 1'
    );
    $st->execute([(int)$r['id'], $document, $r['exit_at'], $r['entry_at']]);
    $conflict = $st->fetch();
    if ($conflict) {
        throw new RuntimeException(
            'Documento ja usado na reserva #' . $conflict['id'] . ' (' .
            trim($conflict['first_name'] . ' ' . $conflict['last_name']) .
            ') com periodo sobreposto. Use outro CPF/documento ou finalize/cancele a reserva anterior.'
        );
    }
}

function visitor_save_platform_result(int $id, array $data): void {
    $record = (string)($data['appointRecordId'] ?? '');
    $visitor = (string)($data['visitorId'] ?? '');
    if ($record === '' || $visitor === '') throw new RuntimeException('HikCentral nao retornou os identificadores da visita.');
    $image = $data['qrCodeImage'] ?? $data['qRCodeImage'] ?? '';
    $qr = $image !== '' ? hcp_save_qr_image($image, $id) : null;
    db()->prepare("UPDATE reservations SET hcp_reference=?, hcp_visitor_id=?, hcp_qr_image_path=COALESCE(?,hcp_qr_image_path), status='SENT' WHERE id=?")
        ->execute([$record, $visitor, $qr, $id]);
    if (isset($data['appointCode'])) {
        db()->prepare('UPDATE reservations SET hcp_appoint_code=? WHERE id=?')->execute([(string)$data['appointCode'], $id]);
    }
}

function visitor_save_registration_result(int $id, array $data): void {
    $record = (string)($data['appointRecordId'] ?? $data['recordId'] ?? '');
    $visitor = (string)($data['visitorId'] ?? '');
    if ($record === '' || $visitor === '') throw new RuntimeException('HikCentral nao retornou os identificadores do check-in.');
    $image = $data['qrCodeImage'] ?? $data['qRCodeImage'] ?? '';
    $qr = $image !== '' ? hcp_save_qr_image($image, $id) : null;
    db()->prepare("UPDATE reservations SET hcp_registration_id=?, hcp_visitor_id=?, hcp_qr_image_path=COALESCE(?,hcp_qr_image_path), status='SENT' WHERE id=?")
        ->execute([$record, $visitor, $qr, $id]);
}

function visitor_delivery_report(HcpOpenApiClient $client, array $r, array $level): array {
    $data = $client->request('/artemis/api/visitor/v1/person/ID/elementDownloadDetail', ['id'=>(string)$r['hcp_visitor_id']])['data'] ?? [];
    $details = is_array($data['ElementDetailList'] ?? null) ? ($data['ElementDetailList']['ElementDetail'] ?? []) : [];
    $indexed = [];
    foreach ($details as $detail) $indexed[(string)($detail['elementID'] ?? $detail['ID'] ?? '')] = $detail;
    $doors = [];
    foreach ($level['ElementList'] as $entry) {
        $element = $entry['Element'];
        $id = (string)$element['ID'];
        $detail = $indexed[$id] ?? [];
        $status = $detail['ElementStatus'][0]['elementStatus'] ?? null;
        $certs = $detail['CertificateStatusList']['CertificateStatus'] ?? [];
        $faceOk = false; $credentialOk = false; $failed = $status !== null && (int)$status === 2; $allOk = $certs !== [];
        $safeCerts = [];
        foreach ($certs as $cert) {
            $type = (int)($cert['type'] ?? -1);
            $cs = (int)($cert['status'] ?? -1);
            if ($type===2 && $cs===0) $faceOk=true;
            if (in_array($type,[0,4],true) && $cs===0) $credentialOk=true;
            if ($cs===2) $failed=true;
            if ($cs!==0) $allOk=false;
            $safeCerts[]=['type'=>$type,'status'=>$cs];
        }
        $confirmed = $status !== null && (int)$status===0 && $allOk && $credentialOk && (empty($r['photo_path']) || $faceOk);
        $doors[]=['id'=>$id,'name'=>$element['BaseInfo']['Name'] ?? $id,
            'state'=>$confirmed?'confirmed':($failed?'failed':'queued'),
            'face'=>$faceOk,'credential'=>$credentialOk,'certificates'=>$safeCerts];
    }
    $states=array_column($doors,'state');
    $state=!in_array('queued',$states,true)&&!in_array('failed',$states,true)?'confirmed':(in_array('failed',$states,true)?'failed':'queued');
    $report=['state'=>$state,'segment'=>$level['privilegeGroupName'],'levelId'=>(string)$level['privilegeGroupId'],
        'source'=>'HikCentral','checkedAt'=>date(DATE_ATOM),'doors'=>$doors];
    $tz=new DateTimeZone('America/Sao_Paulo');
    $now=new DateTimeImmutable('now',$tz);
    $active=$state==='confirmed' && $now>=new DateTimeImmutable($r['entry_at'],$tz) && $now<=new DateTimeImmutable($r['exit_at'],$tz);
    db()->prepare('UPDATE reservations SET hcp_delivery_state=?,hcp_delivery_report=?,hcp_delivery_verified_at=?,status=?,hcp_last_error=? WHERE id=?')
        ->execute([$state,json_encode($report,JSON_UNESCAPED_UNICODE),$state==='confirmed'?$now->format('Y-m-d H:i:s'):null,
            $active?'ACTIVE':'SENT',$state==='failed'?'HikCentral informou falha na entrega. Consulte as catracas abaixo.':null,$r['id']]);
    return $report;
}

function visitor_refresh_delivery(int $id): array {
    $r=visitor_reservation($id);
    if (empty($r['hcp_reference']) || empty($r['hcp_visitor_id'])) throw new RuntimeException('A reserva ainda nao foi cadastrada no HikCentral.');
    $client=new HcpOpenApiClient();
    return visitor_delivery_report($client,$r,visitor_combined_level($client,$r));
}

function visitor_status_snapshot(HcpOpenApiClient $client, array $reservation): array {
    $statusSource = 'appointment_status';
    $registrationRecordStatus = null;
    try {
        $response = $client->getVisitorStatus((string)$reservation['hcp_visitor_id']);
    } catch (HcpOpenApiException $e) {
        $message = $e->getMessage();
        $canFallback = stripos($message, 'UnAuthorized API') !== false
            || stripos($message, 'request resource does not exist') !== false
            || preg_match('/\\b128\\b/', $message);
        if (!$canFallback) throw $e;
        try {
            $response = $client->getRegistrationRecordStatus($reservation);
            $statusSource = 'registration_record';
            $registrationRecordStatus = $response['registrationRecordStatus'] ?? null;
        } catch (Throwable $fallbackError) {
            throw new RuntimeException(
                'A consulta getVisitorStatus foi negada ou nao existe nesta instalacao, e nao foi possivel confirmar o registro exato pelo endpoint getVistorRegisterRecord: ' . $fallbackError->getMessage() . ' Nenhum checkout foi enviado.',
                0,
                $fallbackError
            );
        }
    }
    return [
        'visitorStatus' => isset($response['data']['visitorStatus']) ? (string)$response['data']['visitorStatus'] : null,
        'statusSource' => $statusSource,
        'registrationRecordStatus' => $registrationRecordStatus,
    ];
}

function visitor_status_state(?string $visitorStatus): string {
    return [
        '-1'=>'unknown','0'=>'reserved','1'=>'expired','2'=>'visited','3'=>'checked_in',
        '4'=>'checked_out','5'=>'auto_checked_out','6'=>'self_checked_out','7'=>'overdue',
        '8'=>'pending_approval','9'=>'reservation_failed',
    ][$visitorStatus ?? ''] ?? 'unknown';
}

function visitor_refresh_status(int $id): array {
    ensure_visitor_checkout_columns();
    $lockName = 'visitor_checkout_' . $id;
    $lock = db()->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Esta visita está sendo processada; consulte o status novamente em instantes.');
    try {
        $reservation = visitor_reservation($id);
        if (empty($reservation['hcp_reference']) || empty($reservation['hcp_visitor_id'])) {
            throw new RuntimeException('Consulta disponível somente para reservas vinculadas pelo integrador.');
        }
        $snapshot = visitor_status_snapshot(new HcpOpenApiClient(), $reservation);
        $state = visitor_status_state($snapshot['visitorStatus']);
        $checkedAt = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        db()->prepare('UPDATE reservations SET hcp_visit_state=?,hcp_visit_status_checked_at=? WHERE id=?')
            ->execute([$state, $checkedAt, $id]);
        return [
            'message' => 'Status consultado no HikCentral: ' . visitor_state_label($state) . '.',
            'result' => $snapshot + ['visitState'=>$state, 'checkedAt'=>$checkedAt],
        ];
    } finally {
        db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function visitor_checkout(int $id, bool $requireExpired = false): array {
    ensure_visitor_checkout_columns();
    $lockName = 'visitor_checkout_' . $id;
    $lock = db()->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('O checkout desta reserva ja esta sendo processado.');

    try {
        $r = visitor_reservation($id);
        if ($requireExpired && new DateTimeImmutable($r['exit_at'], new DateTimeZone('America/Sao_Paulo')) > new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'))) {
            throw new RuntimeException('A reserva ainda nao atingiu o horario de saida; nenhum checkout foi enviado.');
        }
        if (empty($r['hcp_reference']) || empty($r['hcp_visitor_id']) || empty($r['hcp_registration_id'])) {
            throw new RuntimeException('Checkout permitido somente para visitas com reserva e check-in registrados por este integrador.');
        }

        $client = new HcpOpenApiClient();
        $snapshot = visitor_status_snapshot($client, $r);
        $statusSource = $snapshot['statusSource'];
        $registrationRecordStatus = $snapshot['registrationRecordStatus'];
        $visitorStatus = $snapshot['visitorStatus'];
        $visitState = visitor_status_state($visitorStatus);
        $attemptedAt = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        db()->prepare('UPDATE reservations SET hcp_visit_state=?,hcp_visit_status_checked_at=? WHERE id=?')
            ->execute([$visitState, $attemptedAt, $id]);
        if (in_array($visitorStatus, ['3', '7'], true)) {
            $checkoutResponse = $client->checkoutVisitor((string)$r['hcp_registration_id']);
            $visitState = 'checked_out';
            $result = [
                'state' => 'checked_out',
                'visitorStatus' => (string)$visitorStatus,
                'visitState' => $visitState,
                'statusSource' => $statusSource,
                'registrationRecordStatus' => $registrationRecordStatus,
                'response' => [
                    'code' => (string)($checkoutResponse['code'] ?? ''),
                    'msg' => (string)($checkoutResponse['msg'] ?? ''),
                ],
            ];
            $message = 'Checkout realizado no HikCentral.';
            db()->prepare('UPDATE reservations SET hcp_visit_state=?,hcp_visit_status_checked_at=? WHERE id=?')
                ->execute([$visitState, $attemptedAt, $id]);
        } else {
            $closedStatuses = ['4', '5', '6'];
            $state = in_array($visitorStatus, $closedStatuses, true) ? 'already_closed' : 'not_checked_in';
            $result = [
                'state' => $state,
                'visitorStatus' => $visitorStatus,
                'visitState' => $visitState,
                'statusSource' => $statusSource,
                'registrationRecordStatus' => $registrationRecordStatus,
            ];
            $message = $state === 'already_closed'
                ? 'O HikCentral ja informa que esta visita foi encerrada; nenhum checkout foi enviado.'
                : 'O HikCentral nao confirmou check-in ativo; nenhum checkout foi enviado.';
        }

        db()->prepare('UPDATE reservations SET hcp_checkout_state=?,hcp_checkout_report=?,hcp_checkout_at=? WHERE id=?')
            ->execute([$result['state'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $attemptedAt, $id]);
        return ['message' => $message, 'result' => $result];
    } catch (Throwable $e) {
        $result = ['state' => 'failed', 'error' => $e->getMessage()];
        $attemptedAt = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        db()->prepare('UPDATE reservations SET hcp_checkout_state=?,hcp_checkout_report=?,hcp_checkout_at=? WHERE id=?')
            ->execute(['failed', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $attemptedAt, $id]);
        throw $e;
    } finally {
        db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function visitor_checkout_candidates(int $limit = 50): array {
    ensure_visitor_checkout_columns();
    $limit = max(1, min(50, $limit));
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $nowValue = $now->format('Y-m-d H:i:s');
    $st = db()->prepare(
        "SELECT id FROM reservations
         WHERE status<>'CANCELLED'
           AND hcp_reference IS NOT NULL AND hcp_reference<>''
           AND hcp_visitor_id IS NOT NULL AND hcp_visitor_id<>''
           AND hcp_registration_id IS NOT NULL AND hcp_registration_id<>''
           AND exit_at<=?
           AND (hcp_checkout_state IS NULL OR hcp_checkout_state NOT IN ('checked_out','already_closed'))
           AND (hcp_checkout_at IS NULL OR hcp_checkout_at<=DATE_SUB(?,INTERVAL 15 MINUTE))
         ORDER BY exit_at ASC,id ASC
         LIMIT {$limit}"
    );
    $st->execute([$nowValue, $nowValue]);
    return array_map('intval', array_column($st->fetchAll(), 'id'));
}

function visitor_sync(int $id): array {
    $lock=db()->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute(['visitor_delivery_'.$id]);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Esta reserva ja esta sendo sincronizada.');
    try {
        $r=visitor_reservation($id);
        if ($r['status']==='CANCELLED') throw new RuntimeException('Reserva cancelada.');
        visitor_assert_no_document_conflict($r);
        $tz=new DateTimeZone('America/Sao_Paulo');
        if (new DateTimeImmutable('now',$tz)>new DateTimeImmutable($r['exit_at'],$tz)) throw new RuntimeException('O ingresso expirou. A validade nao foi alterada.');
        $client=new HcpOpenApiClient();
        $level=visitor_combined_level($client,$r);
        $doors=array_map(fn($e)=>(string)$e['Element']['ID'],$level['ElementList']);
        if (empty($r['hcp_reference']) && empty($r['hcp_visitor_id'])) {
            $response=$client->createReservation($r,(string)$r['group_name'],$r['access_names']);
            visitor_save_platform_result($id,$response['data']??[]);
            $r=visitor_reservation($id);
        }
        if (empty($r['hcp_reference']) || empty($r['hcp_visitor_id'])) throw new RuntimeException('Identificadores incompletos no cadastro. Nao foi criada outra reserva.');
        if (($r['visitor_flow_status'] ?? 'REGISTERED') === 'CHECKED_IN' && empty($r['hcp_registration_id'])) {
            $response=$client->registerReservation($r,(string)$r['group_name'],$r['access_names'],(string)$r['hcp_reference'],(string)$r['hcp_visitor_id']);
            visitor_save_registration_result($id,$response['data']??[]);
        }
        // A reserva v2 já cadastra o visitante e mantém o estado Reserved.
        // registerment é o endpoint de check-in e não deve ser chamado aqui.
        $r=visitor_reservation($id);
        $levelIds=array_map(fn($selected)=>(string)$selected['privilegeGroupId'],$level['SelectedLevels']);
        $oldLevelIds=array_filter(explode(',',(string)($r['hcp_assigned_level_id']??'')));
        foreach (array_diff($oldLevelIds,$levelIds) as $oldLevelId) {
            $client->request('/artemis/api/acs/v1/privilege/group/single/deletePersons',[
                'privilegeGroupId'=>$oldLevelId,'type'=>2,'list'=>[['id'=>(string)$r['hcp_visitor_id']]],
            ]);
        }
        foreach ($levelIds as $levelId) {
            $client->request('/artemis/api/acs/v1/privilege/group/single/addPersons',[
                'privilegeGroupId'=>$levelId,'type'=>2,'list'=>[['id'=>(string)$r['hcp_visitor_id']]],
            ]);
        }
        db()->prepare("UPDATE reservations SET hcp_assigned_level_id=?,hcp_delivery_state='queued' WHERE id=?")->execute([implode(',',$levelIds),$id]);
        $client->reapplyVisitorAccess((string)$r['hcp_visitor_id'],$doors);
        return visitor_delivery_report($client,$r,$level);
    } catch (Throwable $e) {
        db()->prepare("UPDATE reservations SET hcp_last_error=?,hcp_delivery_state='failed' WHERE id=?")->execute([$e->getMessage(),$id]);
        throw $e;
    } finally {
        db()->prepare('SELECT RELEASE_LOCK(?)')->execute(['visitor_delivery_'.$id]);
    }
}
