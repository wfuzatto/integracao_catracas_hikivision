<?php
declare(strict_types=1);
require_once __DIR__ . '/acquavale.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('acquavale_imports.php');
csrf_check();
$ticketId = (int)($_POST['ticket_id'] ?? 0);
$locks = [];
try {
    visitor_ensure_photo_update_column();
    $st = db()->prepare('SELECT * FROM acquavale_import_tickets WHERE id=?');
    $st->execute([$ticketId]); $ticket = $st->fetch();
    if (!$ticket) throw new RuntimeException('Ingresso nao encontrado.');
    $acquire = static function(string $name) use (&$locks): void {
        $st = db()->prepare('SELECT GET_LOCK(?,0)'); $st->execute([$name]);
        if ((int)$st->fetchColumn() !== 1) throw new RuntimeException('Ingresso em sincronizacao. Tente novamente em instantes.');
        $locks[] = $name;
    };
    $acquire('aqv_import_' . $ticket['import_order_id']);
    $st = db()->prepare('SELECT * FROM acquavale_import_tickets WHERE id=?');
    $st->execute([$ticketId]); $ticket = $st->fetch();
    if (!empty($ticket['local_reservation_id'])) $acquire('visitor_delivery_' . (int)$ticket['local_reservation_id']);
    if (in_array($ticket['state'], ['confirmed','acked'], true)) throw new RuntimeException('Ingresso ja integrado. Solicite a alteracao ao atendimento no HikCentral.');
    if (!empty($ticket['local_reservation_id'])) {
        $r = visitor_reservation((int)$ticket['local_reservation_id']);
        if ($r['status'] === 'CANCELLED' || !empty($r['hcp_registration_id'])) throw new RuntimeException('Foto nao pode ser substituida nesta etapa da visita.');
    }
    $upload = $_FILES['photo'] ?? [];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new RuntimeException('Selecione uma foto JPG ou PNG.');
    if (($upload['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('A foto deve ter ate 5 MB.');
    $info = @getimagesize($upload['tmp_name']);
    $extension = ['image/jpeg'=>'jpg','image/png'=>'png'][$info['mime'] ?? ''] ?? null;
    if (!$extension || $info[0] < 240 || $info[1] < 240 || $info[0] * $info[1] > 24000000) throw new RuntimeException('Envie um retrato JPG ou PNG legivel, com pelo menos 240 pixels em cada lado.');
    $dir = 'storage/private/acquavale_faces/' . date('Y/m');
    if (!is_dir(__DIR__ . '/' . $dir) && !mkdir(__DIR__ . '/' . $dir, 0770, true)) throw new RuntimeException('Falha ao preparar o armazenamento.');
    $path = $dir . '/corrected-' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($upload['tmp_name'], __DIR__ . '/' . $path)) throw new RuntimeException('Falha ao salvar a foto.');
    // Validate conversion before replacing the current photograph.
    hcp_face_image_path($path);
    db()->beginTransaction();
    db()->prepare("UPDATE acquavale_import_tickets SET photo_path=?,photo_sha256=?,photo_mime_type=?,photo_size=?,state='photo_saved',last_error=NULL,hcp_delivery_report=NULL,confirmed_at=NULL,updated_at=NOW() WHERE id=?")
        ->execute([$path,hash_file('sha256',__DIR__ . '/' . $path),$info['mime'],filesize(__DIR__ . '/' . $path),$ticketId]);
    if (!empty($ticket['local_reservation_id'])) {
        db()->prepare("UPDATE reservations SET photo_path=?,hcp_photo_update_pending=1,hcp_delivery_state='queued',hcp_delivery_report=NULL,hcp_delivery_verified_at=NULL,hcp_last_error=NULL,status=IF(status='ACTIVE','SENT',status) WHERE id=?")
            ->execute([$path,(int)$ticket['local_reservation_id']]);
    }
    db()->prepare("UPDATE acquavale_import_orders SET state='received',last_error=NULL,updated_at=NOW() WHERE id=?")->execute([$ticket['import_order_id']]);
    db()->commit();
    flash('Foto corrigida salva. O integrador enviara a atualizacao e aguardara a confirmacao das catracas.');
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash($e->getMessage(), 'error');
} finally {
    foreach (array_reverse($locks) as $name) db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
}
redirect('acquavale_imports.php');
