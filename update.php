<?php
require_once __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
csrf_check();
ensure_visitor_flow_column();
$id = (int)($_POST['id'] ?? 0);
try {
    $current = db()->prepare('SELECT * FROM reservations WHERE id=?');
    $current->execute([$id]);
    $r = $current->fetch();
    if (!$r) throw new RuntimeException('Reserva nao encontrada.');
    if (!empty($r['hcp_reference']) || !empty($r['hcp_visitor_id']) || in_array($r['status'], ['SENT','ACTIVE'], true)) {
        throw new RuntimeException('Esta reserva ja foi enviada ao HikCentral. Para alterar dados sensiveis, finalize/cancele e crie uma nova reserva.');
    }

    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    if ($first === '' || $last === '') throw new InvalidArgumentException('Nome e sobrenome sao obrigatorios.');
    $accessIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['access_level_ids'] ?? [])), fn($levelId) => $levelId > 0)));
    if (!$accessIds) throw new InvalidArgumentException('Selecione pelo menos um access level.');
    $marks = implode(',', array_fill(0, count($accessIds), '?'));
    $check = db()->prepare("SELECT id FROM access_levels WHERE active=1 AND id IN ($marks)");
    $check->execute($accessIds);
    $validAccessIds = array_map('intval', array_column($check->fetchAll(), 'id'));
    if (count($validAccessIds) !== count($accessIds)) throw new InvalidArgumentException('Um dos access levels selecionados nao esta disponivel.');
    $entry = dt_db($_POST['entry_at'] ?? '');
    $exit = dt_db($_POST['exit_at'] ?? '');
    if (strtotime($exit) <= strtotime($entry)) throw new InvalidArgumentException('A saida deve ser posterior a entrada.');
    $documentNumber = trim($_POST['document_number'] ?? '') ?: null;
    $normalizedDocument = preg_replace('/[^0-9A-Za-z]/', '', (string)$documentNumber) ?? '';
    if ($normalizedDocument !== '') {
        $dup = db()->prepare(
            'SELECT id,first_name,last_name
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
        $dup->execute([$id, $normalizedDocument, $exit, $entry]);
        $conflict = $dup->fetch();
        if ($conflict) {
            throw new InvalidArgumentException(
                'Documento ja usado na reserva #' . $conflict['id'] . ' (' .
                trim($conflict['first_name'] . ' ' . $conflict['last_name']) .
                ') com periodo sobreposto.'
            );
        }
    }

    $photoPath = $r['photo_path'];
    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Nao foi possivel receber a nova foto.');
        if ($_FILES['photo']['size'] > 5 * 1024 * 1024) throw new InvalidArgumentException('A foto deve ter no maximo 5 MB.');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['photo']['tmp_name']);
        $ext = ['image/jpeg'=>'jpg','image/png'=>'png'][$mime] ?? null;
        if (!$ext) throw new InvalidArgumentException('A foto deve ser JPG ou PNG.');
        $dir = __DIR__ . '/uploads/faces';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $file = bin2hex(random_bytes(18)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $file)) throw new RuntimeException('Nao foi possivel salvar a foto.');
        $photoPath = 'uploads/faces/' . $file;
    }

    $flowStatus = ($_POST['visitor_flow_status'] ?? '') === 'CHECKED_IN' ? 'CHECKED_IN' : 'REGISTERED';
    db()->beginTransaction();
    $st = db()->prepare('UPDATE reservations SET first_name=?,last_name=?,email=?,phone=?,group_id=?,access_level_id=?,entry_at=?,exit_at=?,document_type=?,document_number=?,gender=?,photo_path=?,visitor_flow_status=?,hcp_last_error=NULL,hcp_delivery_state=NULL,hcp_delivery_report=NULL WHERE id=?');
    $st->execute([$first,$last,trim($_POST['email']??'')?:null,trim($_POST['phone']??'')?:null,(int)$_POST['group_id'],$validAccessIds[0],$entry,$exit,$_POST['document_type']?:null,$documentNumber,$_POST['gender']?:null,$photoPath,$flowStatus,$id]);
    db()->prepare('DELETE FROM reservation_access_levels WHERE reservation_id=?')->execute([$id]);
    $link = db()->prepare('INSERT INTO reservation_access_levels (reservation_id,access_level_id) VALUES (?,?)');
    foreach ($validAccessIds as $accessId) $link->execute([$id, $accessId]);
    db()->commit();
    flash('Reserva atualizada. Envie novamente ao HikCentral.');
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash($e->getMessage(), 'error');
    redirect('edit.php?id=' . $id);
}
redirect('view.php?id=' . $id);
