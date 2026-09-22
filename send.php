<?php
require_once __DIR__ . '/delivery.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
csrf_check();
$id=(int)($_POST['id']??0);
try {
    $report=visitor_sync($id);
    flash($report['state']==='confirmed'
        ? 'HikCentral confirmou as credenciais nas '.count($report['doors']).' catracas do segmento '.$report['segment'].'.'
        : 'Reserva cadastrada no HikCentral. Aguardando confirmacao de entrega nas catracas selecionadas.');
} catch(Throwable $e) { flash($e->getMessage(),'error'); }
redirect('view.php?id='.$id);
