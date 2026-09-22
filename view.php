<?php
require_once __DIR__ . '/bootstrap.php';
ensure_visitor_flow_column();
ensure_visitor_checkout_columns();
$st = db()->prepare(
    'SELECT r.*, g.name AS group_name,
            COALESCE((SELECT GROUP_CONCAT(al.name ORDER BY al.name SEPARATOR " + ") FROM reservation_access_levels ral JOIN access_levels al ON al.id=ral.access_level_id WHERE ral.reservation_id=r.id), a.name) AS access_name
     FROM reservations r
     LEFT JOIN guest_groups g ON g.id = r.group_id
     LEFT JOIN access_levels a ON a.id = r.access_level_id
     WHERE r.id = ?'
);
$st->execute([(int)($_GET['id'] ?? 0)]);
$r = $st->fetch();
if (!$r) {
    http_response_code(404);
    exit('Reserva não encontrada.');
}
$pageTitle = 'Reserva #' . $r['id'];
include __DIR__ . '/header.php';
?>
<section class="hero compact">
    <div>
        <p class="eyebrow">Reserva #<?= (int)$r['id'] ?></p>
        <h1><?= e($r['first_name'] . ' ' . $r['last_name']) ?></h1>
        <p class="muted">Integração: <span class="status <?= status_class($r['status']) ?>"><?= e(status_label($r['status'])) ?></span></p>
    </div>
    <div class="actions inline-actions"><a class="button" href="edit.php?id=<?= (int)$r['id'] ?>">Editar cadastro</a><a class="button" href="index.php">Voltar</a></div>
</section>

<div class="detail-grid">
    <section class="card">
        <h2>Dados da reserva</h2>
        <dl>
            <dt>Grupo</dt><dd><?= e($r['group_name']) ?></dd>
            <dt>Access levels</dt><dd><?= e($r['access_name']) ?></dd>
            <dt>Fluxo solicitado</dt><dd><?= e(visitor_flow_label($r['visitor_flow_status'] ?? 'REGISTERED')) ?></dd>
            <dt>Estado da visita no HikCentral</dt><dd><span class="status <?= visitor_state_class($r['hcp_visit_state'] ?? null) ?>"><?= e(visitor_state_label($r['hcp_visit_state'] ?? null, $r)) ?></span><?= !empty($r['hcp_visit_status_checked_at']) ? ' · consultado em ' . e(date('d/m/Y H:i', strtotime($r['hcp_visit_status_checked_at']))) : '' ?></dd>
            <dt>Entrada</dt><dd><?= e(date('d/m/Y H:i', strtotime($r['entry_at']))) ?></dd>
            <dt>Saída</dt><dd><?= e(date('d/m/Y H:i', strtotime($r['exit_at']))) ?></dd>
            <dt>Documento</dt><dd><?= e(trim(($r['document_type'] ?? '') . ' ' . ($r['document_number'] ?? ''))) ?></dd>
            <dt>E-mail / telefone</dt><dd><?= e(trim(($r['email'] ?? '') . ' · ' . ($r['phone'] ?? ''))) ?></dd>
            <?php if (($r['source_system'] ?? '') === 'acquavale_vendas'): ?>
            <dt>Pedido AcquaVale</dt><dd><?= e((string)($r['source_order_code'] ?? '')) ?> · Ticket <?= e((string)($r['source_ticket_code'] ?? '')) ?></dd>
            <?php endif; ?>
        </dl>

        <?php if ($r['status'] !== 'SENT' && $r['status'] !== 'ACTIVE'): ?>
        <form action="send.php" method="post" class="actions">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="button primary" type="submit">Enviar ao HikCentral</button>
        </form>
        <?php endif; ?>

        <?php if (($r['status'] === 'SENT' || $r['status'] === 'ACTIVE') && !empty($r['hcp_reference']) && !empty($r['hcp_visitor_id'])): ?>
        <form action="update_access.php" method="post" class="actions">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="button primary" type="submit">Sincronizar segmento e credenciais</button>
        </form>
        <?php endif; ?>

        <?php if (!empty($r['hcp_reference']) && !empty($r['hcp_visitor_id'])): ?>
        <form action="visit_status.php" method="post" class="actions">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="button" type="submit">Atualizar status no HikCentral</button>
        </form>
        <?php if (!empty($r['hcp_registration_id'])): ?>
        <form action="checkout.php" method="post" class="actions" onsubmit="return confirm('Consultar o status atual no HikCentral e fazer checkout se a visita estiver em check-in ou atrasada?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="button primary" type="submit">Fazer checkout da visita</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($r['hcp_checkout_state'])): ?>
            <div class="notice">
                <strong>Última tentativa de checkout:</strong>
                <?= e($r['hcp_checkout_state']) ?>
                <?php if (!empty($r['hcp_checkout_at'])): ?> em <?= e(date('d/m/Y H:i:s', strtotime($r['hcp_checkout_at']))) ?><?php endif; ?>
                <?php $checkoutReport = json_decode((string)($r['hcp_checkout_report'] ?? ''), true); ?>
                <?php if (!empty($checkoutReport['visitorStatus'])): ?> — status HikCentral: <?= e((string)$checkoutReport['visitorStatus']) ?><?php endif; ?>
                <?php if (($checkoutReport['statusSource'] ?? '') === 'registration_record'): ?> — conferido pelo registro de check-in (status <?= e((string)($checkoutReport['registrationRecordStatus'] ?? '—')) ?>)<?php endif; ?>
                <?php if (!empty($checkoutReport['error'])): ?><br><?= e($checkoutReport['error']) ?><?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($r['hcp_last_error'])): ?>
            <div class="notice error-box"><strong>Último retorno:</strong> <?= e($r['hcp_last_error']) ?></div>
        <?php endif; ?>
        <div class="notice">
            <strong>HikCentral:</strong>
            <?php if ($r['status'] === 'SENT' || $r['status'] === 'ACTIVE'): ?>
                <?php if (!empty($r['hcp_registration_id'])): ?>
                    O registro de check-in foi criado pelo integrador; o estado atual da visita é consultado separadamente acima.
                <?php else: ?>
                    <?= !empty($r['hcp_reference']) ? 'reserva cadastrada; o check-in fica pendente até a chegada do visitante. As credenciais são sincronizadas sem registrar entrada.' : 'reserva ainda não cadastrada no HikCentral.' ?>
                <?php endif; ?>
                <?= $r['hcp_appoint_code'] ? ' Código da reserva: ' . e($r['hcp_appoint_code']) . '.' : '' ?>
            <?php else: ?>
                ainda não enviada. O envio exige OpenAPI autorizado e o mapeamento do access level.
            <?php endif; ?>
        </div>
    </section>

    <section class="card qr-card">
        <?php if (!empty($r['hcp_qr_image_path']) && is_file(__DIR__ . '/' . $r['hcp_qr_image_path'])): ?>
            <h2>QR Code oficial do HikCentral</h2>
            <img class="official-qr" src="<?= e($r['hcp_qr_image_path']) ?>" alt="QR Code oficial">
            <p class="muted">QR da reserva emitido pelo HikCentral. O check-in deve ocorrer somente quando o visitante chegar.</p>
        <?php else: ?>
            <h2>QR Code local de contingência</h2>
            <div id="qrcode"></div>
            <code><?= e($r['qr_payload']) ?></code>
            <p class="muted">Este QR local só será aceito se for previamente cadastrado como credencial no HikCentral. Após o envio, prefira o QR oficial.</p>
        <?php endif; ?>
        <button class="button" onclick="window.print()">Imprimir</button>
    </section>
</div>
<?php $delivery = json_decode($r['hcp_delivery_report'] ?? '', true); ?>
<?php if (is_array($delivery) && !empty($delivery['doors'])): ?>
<section class="card" style="margin-top:20px">
    <h2>Entrega nas catracas</h2>
    <p><?= e($delivery['segment'] ?? $r['access_name']) ?> · Confirmação do HikCentral</p>
    <table>
        <thead><tr><th>Catraca</th><th>Entrega</th><th>Face</th><th>Credencial</th></tr></thead>
        <tbody>
        <?php foreach ($delivery['doors'] as $door): ?>
            <tr>
                <td><?= e($door['name']) ?></td>
                <td><?= e(['confirmed'=>'Confirmada','queued'=>'Aguardando','failed'=>'Falhou'][$door['state']] ?? 'Aguardando') ?></td>
                <td><?= !empty($door['face']) ? 'Confirmada' : 'Pendente' ?></td>
                <td><?= !empty($door['credential']) ? 'Confirmada' : 'Pendente' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p id="delivery-feedback" class="muted">Validade limitada às datas do ingresso. A entrega não representa uma passagem física.</p>
    <button type="button" class="button" id="check-delivery">Consultar entrega</button>
</section>
<script>
(() => {
    let attempts = 0;
    let busy = false;
    async function checkDelivery(manual = false) {
        if (busy) return;
        busy = true;
        try {
            const form = new FormData();
            form.set('id', <?= json_encode((string)$r['id']) ?>);
            form.set('csrf', <?= json_encode(csrf_token()) ?>);
            const res = await fetch('delivery_status.php', {method: 'POST', body: form});
            const report = await res.json();
            if (!res.ok) throw new Error(report.error || 'Falha ao consultar a entrega.');
            if (manual || report.state !== 'queued') { location.reload(); return; }
            if (++attempts < 20) setTimeout(() => checkDelivery(), 3000);
        } catch (error) {
            document.getElementById('delivery-feedback').textContent = error.message;
        } finally { busy = false; }
    }
    document.getElementById('check-delivery').addEventListener('click', () => checkDelivery(true));
    <?php if (($r['hcp_delivery_state'] ?? '') === 'queued'): ?>
    setTimeout(() => checkDelivery(), 3000);
    <?php endif; ?>
})();
</script>
<?php endif; ?>
<?php if (empty($r['hcp_qr_image_path'])): ?>
<script src="assets/qrcode.min.js"></script>
<script>new QRCode(document.getElementById('qrcode'), {text: <?= json_encode($r['qr_payload']) ?>, width: 220, height: 220, colorDark: '#101827', colorLight: '#fff', correctLevel: QRCode.CorrectLevel.M});</script>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
