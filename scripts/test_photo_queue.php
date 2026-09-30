<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/acquavale.php';
visitor_ensure_photo_update_column();
$pdo=db();
if ((int)$pdo->query("SELECT GET_LOCK('aqv_sales_sync',30)")->fetchColumn()!==1) throw new RuntimeException('Integrador ocupado.');
$code='TEST-PHOTO-'.strtoupper(bin2hex(random_bytes(6)));
$orderId=0; $reservationId=0; $files=[];
$assert=static function(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    // Synthetic image only tests upload/queuing. The global lock prevents any
    // external synchronization until all isolated test rows have been removed.
    $chunk=static fn(string $type,string $data): string => pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',240,240,8,2,0,0,0))
        .$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\xff\xff\xff",240),240))).$chunk('IEND','');
    $fixture=dirname(__DIR__).'/storage/private/'.$code.'.png';
    file_put_contents($fixture,$png); $files[]=$fixture;
    $ticket=['ticket_code'=>$code,'valid_from'=>'2099-01-01','valid_to'=>'2099-01-02',
        'first_name'=>'Teste','last_name'=>'Foto','document_type'=>'RG','document_number'=>$code];
    $payload=['event'=>'sale.paid','consumer'=>'vale-visitor','order'=>['order_code'=>$code,'claim_token'=>str_repeat('c',64),'tickets'=>[$ticket]]];
    $orderId=aqv_accept_payload($payload,$code);
    $reservationId=aqv_create_local_reservation($ticket,$ticket,$code,'test-original.jpg');
    $pdo->prepare("UPDATE reservations SET status='SENT',hcp_reference=?,hcp_visitor_id=? WHERE id=?")->execute([$code,$code,$reservationId]);
    $pdo->prepare("UPDATE acquavale_import_tickets SET local_reservation_id=?,state='failed' WHERE import_order_id=?")->execute([$reservationId,$orderId]);
    $st=$pdo->prepare('SELECT id FROM acquavale_import_tickets WHERE import_order_id=?');$st->execute([$orderId]);$ticketId=(int)$st->fetchColumn();
    $base='http://127.0.0.1:37080/visitor/';
    $ch=curl_init($base.'acquavale_imports.php');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>'']);
    $html=(string)curl_exec($ch);
    $assert((bool)preg_match('/name="csrf" value="([^"]+)"/',$html,$match),'Token CSRF ausente.');
    curl_setopt_array($ch,[CURLOPT_URL=>$base.'replace_online_photo.php',CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>['csrf'=>$match[1],'ticket_id'=>(string)$ticketId,'photo'=>new CURLFile($fixture,'image/png','test.png')]]);
    curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $r=visitor_reservation($reservationId);
    if ($r['photo_path']!=='test-original.jpg') {
        $files[]=dirname(__DIR__).'/'.$r['photo_path'];
        $files[]=hcp_face_image_path($r['photo_path']);
    }
    $assert($status===302,'Upload nao redirecionou.');
    $assert((int)$r['hcp_photo_update_pending']===1 && is_file(dirname(__DIR__).'/'.$r['photo_path']),'Foto nao ficou salva e pendente.');
    $assert($r['hcp_reference']===$code && $r['hcp_visitor_id']===$code && empty($r['hcp_registration_id']),'Upload alterou identidade ou realizou check-in.');
    $assert($r['entry_at']==='2099-01-01 00:00:00' && $r['exit_at']==='2099-01-02 23:59:59','Upload alterou validade.');
    $st=$pdo->prepare('SELECT state,photo_path FROM acquavale_import_tickets WHERE id=?');$st->execute([$ticketId]);$t=$st->fetch();
    $assert($t['state']==='photo_saved' && $t['photo_path']===$r['photo_path'],'Fila e reserva ficaram divergentes.');
    echo "OK: upload HTTP, conversao JPEG, fila duravel, mesmos identificadores/datas e nenhum check-in ou envio externo.\n";
} finally {
    if ($orderId) $pdo->prepare('DELETE FROM acquavale_import_orders WHERE id=? AND order_code=?')->execute([$orderId,$code]);
    if ($reservationId) $pdo->prepare('DELETE FROM reservations WHERE id=? AND source_ticket_code=?')->execute([$reservationId,$code]);
    foreach (array_unique($files) as $file) if (is_file($file)) unlink($file);
    $pdo->query("SELECT RELEASE_LOCK('aqv_sales_sync')");
}
