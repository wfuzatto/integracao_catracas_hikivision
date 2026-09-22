<?php
require_once __DIR__ . '/delivery.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_check();
$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn(int $id): bool => $id > 0)));
if (!$ids) { flash('Selecione ao menos uma reserva.', 'error'); redirect('checkouts.php'); }
if (count($ids) > 50) { flash('Selecione no máximo 50 reservas por operação.', 'error'); redirect('checkouts.php'); }
$checkedOut = 0;
$skipped = 0;
$failed = 0;
foreach ($ids as $id) {
    try {
        $result = visitor_checkout($id);
        if ($result['result']['state'] === 'checked_out') $checkedOut++;
        else $skipped++;
    } catch (Throwable $e) {
        $failed++;
    }
}
flash("Lote finalizado: {$checkedOut} checkout(s), {$skipped} sem checkout por status remoto e {$failed} falha(s). Confira o resultado por reserva.", $failed ? 'error' : 'success');
redirect('checkouts.php');
