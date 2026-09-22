<?php
require_once __DIR__ . '/bootstrap.php';
$pageTitle = 'Reservas';
$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$sql = 'SELECT r.*, g.name AS group_name, COALESCE((SELECT GROUP_CONCAT(al.name ORDER BY al.name SEPARATOR " + ") FROM reservation_access_levels ral JOIN access_levels al ON al.id=ral.access_level_id WHERE ral.reservation_id=r.id), a.name) AS access_name FROM reservations r LEFT JOIN guest_groups g ON g.id=r.group_id LEFT JOIN access_levels a ON a.id=r.access_level_id WHERE 1=1';
$args = [];
if ($q !== '') { $sql .= ' AND (r.first_name LIKE :q OR r.last_name LIKE :q OR r.document_number LIKE :q OR r.email LIKE :q)'; $args['q'] = '%' . $q . '%'; }
if (in_array($status, ['PENDING','SENT','ACTIVE','ERROR','CANCELLED'], true)) { $sql .= ' AND r.status=:status'; $args['status'] = $status; }
$sql .= ' ORDER BY r.entry_at DESC, r.id DESC LIMIT 200';
$stmt = db()->prepare($sql); $stmt->execute($args); $rows = $stmt->fetchAll();
include __DIR__ . '/header.php';
?>
<section class="hero"><div><p class="eyebrow">Módulo Visitor</p><h1>Reservas de hóspedes</h1><p class="muted">Cadastre o ingresso diário, prepare a face e gere o QR Code de contingência.</p></div><a class="button primary" href="new.php">+ Nova reserva</a></section>
<form class="filters" method="get"><input name="q" value="<?= e($q) ?>" placeholder="Nome, documento ou e-mail"><select name="status"><option value="">Todos os status de integração</option><?php foreach(['PENDING','SENT','ACTIVE','ERROR','CANCELLED'] as $s): ?><option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= status_label($s) ?></option><?php endforeach; ?></select><button class="button">Filtrar</button></form>
<div class="card table-wrap"><table><thead><tr><th>Hóspede</th><th>Grupo</th><th>Entrada</th><th>Saída</th><th>Access level</th><th>Integração</th><th>Visita HikCentral</th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="empty">Nenhuma reserva cadastrada.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr><td><strong><?= e($r['first_name'].' '.$r['last_name']) ?></strong><small><?= e(trim(($r['document_type'] ?? '').' '.($r['document_number'] ?? ''))) ?></small></td><td><?= e($r['group_name']) ?></td><td><?= e(date('d/m/Y H:i', strtotime($r['entry_at']))) ?></td><td><?= e(date('d/m/Y H:i', strtotime($r['exit_at']))) ?></td><td><?= e($r['access_name']) ?></td><td><span class="status <?= status_class($r['status']) ?>"><?= e(status_label($r['status'])) ?></span></td><td><span class="status <?= visitor_state_class($r['hcp_visit_state'] ?? null) ?>"><?= e(visitor_state_label($r['hcp_visit_state'] ?? null, $r)) ?></span><?= !empty($r['hcp_visit_status_checked_at']) ? '<small>Consultado em ' . e(date('d/m/Y H:i', strtotime($r['hcp_visit_status_checked_at']))) . '</small>' : '' ?></td><td><a class="link" href="view.php?id=<?= (int)$r['id'] ?>">Abrir</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php include __DIR__ . '/footer.php'; ?>
