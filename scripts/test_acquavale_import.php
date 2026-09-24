<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/acquavale.php';

// Testa persistencia e idempotencia; nao chama a loja nem o HikCentral.
$pdo = db();
if ((int)$pdo->query("SELECT GET_LOCK('aqv_sales_sync',30)")->fetchColumn() !== 1) {
    throw new RuntimeException('A sincronizacao esta ocupada.');
}
$code = 'TEST-' . strtoupper(bin2hex(random_bytes(8)));
$orderId = 0;
$reservationId = 0;
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
try {
    $ticket = ['ticket_code'=>$code, 'valid_from'=>'2099-01-01', 'valid_to'=>'2099-01-02',
        'first_name'=>'Teste', 'last_name'=>'Integracao', 'document_type'=>'RG', 'document_number'=>$code];
    $payload = ['event'=>'sale.paid', 'consumer'=>'vale-visitor', 'order'=>[
        'order_code'=>$code, 'claim_token'=>str_repeat('a', 64), 'tickets'=>[$ticket],
    ]];
    $orderId = aqv_accept_payload($payload, $code . '-1');
    $assert(aqv_accept_payload($payload, $code . '-1') === $orderId, 'Reenvio duplicou pedido.');
    $payload['order']['claim_token'] = str_repeat('b', 64);
    $assert(aqv_accept_payload($payload, $code . '-2') === $orderId, 'Novo claim duplicou pedido.');
    $st = $pdo->prepare('SELECT claim_token FROM acquavale_import_orders WHERE id=?');
    $st->execute([$orderId]);
    $assert($st->fetchColumn() === str_repeat('b', 64), 'Novo claim nao foi salvo.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM acquavale_import_tickets WHERE import_order_id=?');
    $st->execute([$orderId]);
    $assert((int)$st->fetchColumn() === 1, 'Reenvio duplicou ingresso.');

    $reservationId = aqv_create_local_reservation($ticket, $ticket, $code, 'test-only.jpg');
    $assert(aqv_create_local_reservation($ticket, $ticket, $code, 'test-only.jpg') === $reservationId,
        'Reenvio duplicou reserva.');
    $st = $pdo->prepare('SELECT visitor_flow_status,entry_at,exit_at FROM reservations WHERE id=?');
    $st->execute([$reservationId]);
    $row = $st->fetch();
    $assert($row['visitor_flow_status'] === 'REGISTERED', 'Importacao forcou check-in.');
    $assert($row['entry_at'] === '2099-01-01 00:00:00' && $row['exit_at'] === '2099-01-02 23:59:59',
        'Validade do ingresso foi alterada.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM reservation_access_levels WHERE reservation_id=?');
    $st->execute([$reservationId]);
    $assert((int)$st->fetchColumn() === 2, 'Niveis de entrada e saida incompletos.');
    echo "OK: reenvio, renovacao de claim, reserva unica, validade e niveis de acesso.\n";
} finally {
    if ($orderId) $pdo->prepare('DELETE FROM acquavale_import_orders WHERE id=? AND order_code=?')->execute([$orderId, $code]);
    if ($reservationId) $pdo->prepare('DELETE FROM reservations WHERE id=? AND source_ticket_code=?')->execute([$reservationId, $code]);
    $pdo->query("SELECT RELEASE_LOCK('aqv_sales_sync')");
}
