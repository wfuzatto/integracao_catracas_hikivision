<?php
require_once __DIR__ . '/bootstrap.php';
ensure_visitor_flow_column();
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM reservations WHERE id=?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) { http_response_code(404); exit('Reserva nao encontrada.'); }
if (!empty($r['hcp_reference']) || !empty($r['hcp_visitor_id']) || in_array($r['status'], ['SENT','ACTIVE'], true)) {
    flash('Esta reserva ja foi enviada ao HikCentral. Para alterar dados sensiveis, finalize/cancele e crie uma nova reserva.', 'error');
    redirect('view.php?id=' . $id);
}
$groups = db()->query('SELECT id,name FROM guest_groups WHERE active=1 ORDER BY name')->fetchAll();
$levels = db()->query('SELECT id,name FROM access_levels WHERE active=1 ORDER BY name')->fetchAll();
$selected = db()->prepare('SELECT access_level_id FROM reservation_access_levels WHERE reservation_id=?');
$selected->execute([$id]);
$selectedIds = array_map('intval', array_column($selected->fetchAll(), 'access_level_id'));
if (!$selectedIds && !empty($r['access_level_id'])) $selectedIds = [(int)$r['access_level_id']];
$pageTitle = 'Editar reserva #' . $id;
include __DIR__ . '/header.php';
?>
<section class="hero compact"><div><p class="eyebrow">Reserva #<?= (int)$id ?></p><h1>Editar cadastro</h1><p class="muted">Ajuste os dados antes de enviar ao HikCentral.</p></div><a class="button" href="view.php?id=<?= (int)$id ?>">Voltar</a></section>
<?php if (!empty($r['hcp_last_error'])): ?><div class="notice error-box"><strong>Corrigir pendencia:</strong> <?= e($r['hcp_last_error']) ?></div><?php endif; ?>
<form class="card form" action="update.php" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="id" value="<?= (int)$id ?>">
<h2>Dados pessoais</h2><div class="grid two"><label>Nome *<input name="first_name" required maxlength="120" value="<?= e($r['first_name']) ?>"></label><label>Sobrenome *<input name="last_name" required maxlength="120" value="<?= e($r['last_name']) ?>"></label><label>E-mail<input type="email" name="email" maxlength="190" value="<?= e($r['email']) ?>"></label><label>Telefone<input name="phone" maxlength="40" value="<?= e($r['phone']) ?>"></label><label>Grupo *<select name="group_id" required><option value="">Selecione</option><?php foreach($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)$r['group_id']===(int)$g['id']?'selected':'' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></label><fieldset class="access-levels"><legend>Access levels * <small>Selecione todos os sentidos incluidos no ingresso.</small></legend><?php foreach($levels as $a): ?><label class="check-option"><input type="checkbox" name="access_level_ids[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $selectedIds, true)?'checked':'' ?>><span><?= e($a['name']) ?></span></label><?php endforeach; ?></fieldset><label>Tipo de documento<select name="document_type"><option value="">Nao informado</option><?php foreach(['CPF','RG','CNH','OUTRO'] as $doc): ?><option value="<?= $doc ?>" <?= ($r['document_type'] ?? '')===$doc?'selected':'' ?>><?= $doc ?></option><?php endforeach; ?></select></label><label>CPF / numero do documento<input name="document_number" maxlength="80" value="<?= e($r['document_number']) ?>" placeholder="Digite um CPF/documento diferente"><small>Este campo nao pode repetir documento de reserva ativa no mesmo periodo.</small></label><label>Sexo<select name="gender"><option value="">Nao informado</option><option value="F" <?= ($r['gender'] ?? '')==='F'?'selected':'' ?>>Feminino</option><option value="M" <?= ($r['gender'] ?? '')==='M'?'selected':'' ?>>Masculino</option><option value="N" <?= ($r['gender'] ?? '')==='N'?'selected':'' ?>>Nao informado</option></select></label><label class="photo">Trocar foto facial<input type="file" name="photo" accept="image/jpeg,image/png"><small>Opcional. Se enviar nova foto, JPG/PNG ate 5 MB.</small></label></div>
<h2>Validade do ingresso</h2><div class="grid two"><label>Data/hora de entrada *<input type="datetime-local" name="entry_at" required value="<?= e(dt_input($r['entry_at'])) ?>"></label><label>Data/hora de saida *<input type="datetime-local" name="exit_at" required value="<?= e(dt_input($r['exit_at'])) ?>"></label></div>
<h2>Status no HikCentral</h2>
<div class="grid two"><label>Status de envio<select name="visitor_flow_status"><option value="REGISTERED" <?= ($r['visitor_flow_status'] ?? 'REGISTERED') !== 'CHECKED_IN' ? 'selected' : '' ?>>Apenas cadastrado / reserva</option><option value="CHECKED_IN" <?= ($r['visitor_flow_status'] ?? 'REGISTERED') === 'CHECKED_IN' ? 'selected' : '' ?>>Check-in imediato</option></select><small>Use check-in imediato quando a visita ja deve ficar ativa nas catracas.</small></label></div>
<div class="actions"><a class="button" href="view.php?id=<?= (int)$id ?>">Cancelar</a><button class="button primary" type="submit">Salvar alteracoes</button></div></form>
<?php include __DIR__ . '/footer.php'; ?>
