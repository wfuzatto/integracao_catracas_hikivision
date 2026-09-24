<?php
require_once __DIR__ . '/bootstrap.php';
ensure_visitor_checkout_columns();
ensure_auto_checkout_settings();
$pageTitle = 'Checkouts';
$enabled = auto_checkout_enabled();
$stmt = db()->prepare(
    "SELECT id,first_name,last_name,entry_at,exit_at,hcp_checkout_state,hcp_checkout_at,hcp_visit_state,hcp_visit_status_checked_at
     FROM reservations
     WHERE status<>'CANCELLED'
       AND hcp_reference IS NOT NULL AND hcp_reference<>''
       AND hcp_visitor_id IS NOT NULL AND hcp_visitor_id<>''
       AND hcp_registration_id IS NOT NULL AND hcp_registration_id<>''
       AND (hcp_checkout_state IS NULL OR hcp_checkout_state NOT IN ('checked_out','already_closed'))
     ORDER BY exit_at ASC,id ASC
     LIMIT 200"
);
$stmt->execute();
$rows = $stmt->fetchAll();
$recent = db()->query(
    "SELECT id,first_name,last_name,exit_at,hcp_checkout_state,hcp_checkout_report,hcp_checkout_at,hcp_visit_state,hcp_visit_status_checked_at
     FROM reservations
     WHERE hcp_checkout_at IS NOT NULL
     ORDER BY hcp_checkout_at DESC,id DESC
     LIMIT 50"
)->fetchAll();
include __DIR__ . '/header.php';
?>
<section class="hero">
    <div><p class="eyebrow">Operações HikCentral</p><h1>Checkouts</h1><p class="muted">Selecione visitas vinculadas pelo integrador para consultar o status e encerrar em lote. Cada visita é validada individualmente; o coletor automático aguarda o fim da reserva.</p></div>
</section>

<section class="card">
    <h2>Coletor de auto checkout</h2>
    <p>Estado: <span class="status <?= $enabled ? 'active' : 'pending' ?>"><?= $enabled ? 'Ativado' : 'Desativado' ?></span></p>
    <p class="muted">O coletor processa até 50 visitas expiradas por execução. Ele consulta o HikCentral e só envia checkout para status confirmado como check-in. Falhas e status ainda não ativos são reavaliados após 15 minutos.</p>
    <form action="checkout_settings.php" method="post" class="actions" onsubmit="return this.elements.enabled.value !== '1' || confirm('Ativar o coletor automático? Ele encerrará visitas expiradas quando o HikCentral confirmar check-in ativo.')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
        <button class="button <?= $enabled ? '' : 'primary' ?>" type="submit"><?= $enabled ? 'Desativar coletor' : 'Ativar coletor' ?></button>
    </form>
    <div class="notice">Para execução automática no XAMPP/Windows, execute <code>scripts\install_auto_checkout_task.ps1</code> uma vez como administrador. A tarefa separada <code>ValeVisitor-AutoCheckout</code> roda a cada 5 minutos:<br><code>C:\xampp\php\php.exe <?= e(__DIR__ . DIRECTORY_SEPARATOR . 'auto_checkout_collector.php') ?></code></div>
</section>

<section class="card table-wrap" style="margin-top:20px">
    <form action="checkout_bulk.php" method="post" onsubmit="return confirm('Consultar o status no HikCentral e enviar checkout somente para as visitas que ainda estiverem em check-in? O lote manual também pode encerrar visitas antes do horário previsto de saída.')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <table><thead><tr><th><input type="checkbox" id="select-all" aria-label="Selecionar todas"></th><th>Hóspede</th><th>Entrada</th><th>Saída prevista</th><th>Estado da visita</th><th>Último checkout</th><th></th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nenhum checkout pendente entre as visitas integradas expiradas.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Selecionar reserva <?= (int)$r['id'] ?>"></td>
            <td><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><small>Reserva #<?= (int)$r['id'] ?></small></td>
            <td><?= e(date('d/m/Y H:i', strtotime($r['entry_at']))) ?></td>
            <td><?= e(date('d/m/Y H:i', strtotime($r['exit_at']))) ?></td>
            <td><span class="status <?= visitor_state_class($r['hcp_visit_state'] ?? null) ?>"><?= e(visitor_state_label($r['hcp_visit_state'] ?? null, $r)) ?></span><?= $r['hcp_visit_status_checked_at'] ? '<small>Consultado em ' . e(date('d/m/Y H:i', strtotime($r['hcp_visit_status_checked_at']))) . '</small>' : '' ?></td>
            <td><?= e($r['hcp_checkout_state'] ?: 'Ainda não consultado') ?><?= $r['hcp_checkout_at'] ? '<small>' . e(date('d/m/Y H:i', strtotime($r['hcp_checkout_at']))) . '</small>' : '' ?></td>
            <td><a class="link" href="view.php?id=<?= (int)$r['id'] ?>">Abrir</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
        <div class="actions"><button class="button primary" type="submit" <?= !$rows ? 'disabled' : '' ?>>Consultar e fazer checkout dos selecionados</button></div>
    </form>
</section>
<section class="card table-wrap" style="margin-top:20px">
    <h2 style="padding:20px 16px 0">Resultados recentes</h2>
    <table><thead><tr><th>Hóspede</th><th>Saída prevista</th><th>Estado da visita</th><th>Resultado do checkout</th><th>Status antes do checkout</th><th>Processado em</th><th></th></tr></thead><tbody>
    <?php if (!$recent): ?><tr><td colspan="7" class="empty">Ainda não há tentativas de checkout.</td></tr><?php endif; ?>
    <?php foreach ($recent as $r): $report = json_decode((string)$r['hcp_checkout_report'], true) ?: []; ?>
    <tr>
        <td><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><small>Reserva #<?= (int)$r['id'] ?></small></td>
        <td><?= e(date('d/m/Y H:i', strtotime($r['exit_at']))) ?></td>
        <td><span class="status <?= visitor_state_class($r['hcp_visit_state'] ?? null) ?>"><?= e(visitor_state_label($r['hcp_visit_state'] ?? null, $r)) ?></span><?= !empty($r['hcp_visit_status_checked_at']) ? '<small>Consultado em ' . e(date('d/m/Y H:i', strtotime($r['hcp_visit_status_checked_at']))) . '</small>' : '' ?></td>
        <td><?= e($r['hcp_checkout_state']) ?><?= !empty($report['error']) ? '<small>' . e($report['error']) . '</small>' : '' ?></td>
        <td><?= e(isset($report['visitorStatus']) ? (string)$report['visitorStatus'] : '—') ?><?php if (($report['statusSource'] ?? '') === 'registration_record'): ?><small>confirmado pelo registro (<?= e((string)($report['registrationRecordStatus'] ?? '—')) ?>)</small><?php endif; ?></td>
        <td><?= e(date('d/m/Y H:i', strtotime($r['hcp_checkout_at']))) ?></td>
        <td><a class="link" href="view.php?id=<?= (int)$r['id'] ?>">Abrir</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</section>
<script>
document.getElementById('select-all')?.addEventListener('change', function () {
    document.querySelectorAll('input[name="ids[]"]').forEach(input => input.checked = this.checked);
});
</script>
<?php include __DIR__ . '/footer.php'; ?>
