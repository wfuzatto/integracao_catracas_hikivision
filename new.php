<?php
require_once __DIR__ . '/bootstrap.php';
ensure_visitor_flow_column();
$pageTitle = 'Nova reserva';
$groups = db()->query('SELECT id,name FROM guest_groups WHERE active=1 ORDER BY name')->fetchAll();
$levels = db()->query('SELECT id,name FROM access_levels WHERE active=1 ORDER BY name')->fetchAll();
include __DIR__ . '/header.php';
?>
<section class="hero compact"><div><p class="eyebrow">Reserva de ingresso</p><h1>Novo hóspede</h1><p class="muted">A reserva local será a origem do cadastro no módulo Visitor.</p></div></section>
<form class="card form" action="save.php" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<h2>Dados pessoais</h2><div class="grid two"><label>Nome *<input name="first_name" required maxlength="120"></label><label>Sobrenome *<input name="last_name" required maxlength="120"></label><label>E-mail<input type="email" name="email" maxlength="190"></label><label>Telefone<input name="phone" maxlength="40"></label><label>Grupo *<select name="group_id" required><option value="">Selecione</option><?php foreach($groups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?></select></label><fieldset class="access-levels"><legend>Access levels * <small>Selecione todos os sentidos incluídos no ingresso.</small></legend><?php foreach($levels as $a): ?><label class="check-option"><input type="checkbox" name="access_level_ids[]" value="<?= (int)$a['id'] ?>"><span><?= e($a['name']) ?></span></label><?php endforeach; ?></fieldset><label>Tipo de documento<select name="document_type"><option value="">Não informado</option><option>CPF</option><option>RG</option><option>CNH</option><option>OUTRO</option></select></label><label>Número do documento<input name="document_number" maxlength="80"></label><label>Sexo<select name="gender"><option value="">Não informado</option><option value="F">Feminino</option><option value="M">Masculino</option><option value="N">Não informado</option></select></label><label class="photo">Foto facial *<input type="file" name="photo" accept="image/jpeg,image/png" required><small>Use uma foto frontal, nítida, sem óculos escuros. JPG/PNG até 5 MB.</small></label></div>
<h2>Validade do ingresso</h2><div class="grid two"><label>Data/hora de entrada *<input type="datetime-local" name="entry_at" required></label><label>Data/hora de saída *<input type="datetime-local" name="exit_at" required></label></div>
<div class="notice"><strong>Como funciona:</strong> esta tela registra a reserva. Depois de salvar, use “Enviar ao HikCentral” para criar a reserva oficial com face, validade e QR Code emitido pelo HikCentral.</div>
<h2>Status no HikCentral</h2>
<div class="grid two"><label>Status de envio<select name="visitor_flow_status"><option value="REGISTERED">Apenas cadastrado / reserva</option><option value="CHECKED_IN">Check-in imediato</option></select><small>Use check-in imediato quando a visita ja deve ficar ativa nas catracas.</small></label></div>
<div class="actions"><a class="button" href="index.php">Cancelar</a><button class="button primary" type="submit">Salvar reserva</button></div></form>
<?php include __DIR__ . '/footer.php'; ?>
