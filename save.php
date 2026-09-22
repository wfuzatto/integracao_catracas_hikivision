<?php
require_once __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php'); csrf_check();
ensure_visitor_flow_column();
try {
    $first = trim($_POST['first_name'] ?? ''); $last = trim($_POST['last_name'] ?? '');
    if ($first === '' || $last === '') throw new InvalidArgumentException('Nome e sobrenome são obrigatórios.');
    $accessIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['access_level_ids'] ?? [])), fn($id) => $id > 0)));
    if (!$accessIds) throw new InvalidArgumentException('Selecione pelo menos um access level.');
    $marks = implode(',', array_fill(0, count($accessIds), '?'));
    $check = db()->prepare("SELECT id FROM access_levels WHERE active=1 AND id IN ($marks)");
    $check->execute($accessIds);
    $validAccessIds = array_map('intval', array_column($check->fetchAll(), 'id'));
    if (count($validAccessIds) !== count($accessIds)) throw new InvalidArgumentException('Um dos access levels selecionados não está disponível.');
    $entry = dt_db($_POST['entry_at'] ?? ''); $exit = dt_db($_POST['exit_at'] ?? '');
    if (strtotime($exit) <= strtotime($entry)) throw new InvalidArgumentException('A saída deve ser posterior à entrada.');
    $photoPath = null;
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('A foto facial é obrigatória.');
    if ($_FILES['photo']['size'] > 5 * 1024 * 1024) throw new InvalidArgumentException('A foto deve ter no máximo 5 MB.');
    $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($_FILES['photo']['tmp_name']);
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png'][$mime] ?? null; if (!$ext) throw new InvalidArgumentException('A foto deve ser JPG ou PNG.');
    $dir = __DIR__ . '/uploads/faces'; if (!is_dir($dir)) mkdir($dir, 0775, true);
    $file = bin2hex(random_bytes(18)) . '.' . $ext; if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $file)) throw new RuntimeException('Não foi possível salvar a foto.');
    $token = bin2hex(random_bytes(24)); $payload = 'VALEVISITOR:' . $token;
    $flowStatus = ($_POST['visitor_flow_status'] ?? '') === 'CHECKED_IN' ? 'CHECKED_IN' : 'REGISTERED';
    db()->beginTransaction();
    $st = db()->prepare('INSERT INTO reservations (first_name,last_name,email,phone,group_id,access_level_id,entry_at,exit_at,document_type,document_number,gender,photo_path,qr_token,qr_payload,visitor_flow_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$first,$last,trim($_POST['email']??'')?:null,trim($_POST['phone']??'')?:null,(int)$_POST['group_id'],$validAccessIds[0],$entry,$exit,$_POST['document_type']?:null,trim($_POST['document_number']??'')?:null,$_POST['gender']?:null,'uploads/faces/'.$file,$token,$payload,$flowStatus]);
    $reservationId = (int)db()->lastInsertId();
    $link = db()->prepare('INSERT INTO reservation_access_levels (reservation_id,access_level_id) VALUES (?,?)');
    foreach ($validAccessIds as $accessId) $link->execute([$reservationId, $accessId]);
    db()->commit();
    flash('Reserva criada. Envie-a ao HikCentral pelo detalhe para gerar a credencial oficial e o QR Code da catraca.'); redirect('view.php?id=' . $reservationId);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash($e->getMessage(), 'error');
    redirect('new.php');
}
