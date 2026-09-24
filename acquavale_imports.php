<?php
require_once __DIR__ . '/acquavale.php';
$pageTitle='Vendas online';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try {
        $id=(int)($_POST['id']??0);
        if ($id>0) $result=aqv_process_order($id); else $result=aqv_sync_sales(20);
        if (!empty($result['errors'])) {
            flash('Falha na sincronização: '.implode(' | ', array_unique(array_column($result['errors'], 'error'))), 'error');
        } elseif (!empty($result['locked'])) {
            flash('Uma sincronização já está em andamento. Aguarde e atualize esta página.');
        } else {
            flash(!empty($result['final']['acked']) || !empty($result['acked']) ? 'Sincronização concluída e ACK enviado.' : 'Sincronização executada. Consulte os estados abaixo.');
        }
    } catch(Throwable $e) { flash($e->getMessage(),'error'); }
    redirect('acquavale_imports.php');
}
$orders=db()->query("SELECT o.*,(SELECT COUNT(*) FROM acquavale_import_tickets t WHERE t.import_order_id=o.id) ticket_count,(SELECT SUM(t.state IN ('confirmed','acked')) FROM acquavale_import_tickets t WHERE t.import_order_id=o.id) confirmed_count FROM acquavale_import_orders o ORDER BY o.id DESC LIMIT 200")->fetchAll();
include __DIR__.'/header.php';
?>
<section class="hero"><div><p class="eyebrow">AcquaVale Park</p><h1>Vendas online</h1><p class="muted">Pedidos recebidos do site, persistência local e entrega ao HikCentral.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button class="button primary" type="submit">Sincronizar agora</button></form></section>
<div class="card table-wrap"><table><thead><tr><th>Pedido</th><th>Reserva</th><th>Ingressos</th><th>Estado</th><th>Última tentativa</th><th>Erro</th><th></th></tr></thead><tbody>
<?php if(!$orders):?><tr><td colspan="7" class="empty">Nenhuma venda online recebida.</td></tr><?php endif;?>
<?php foreach($orders as $o):?><tr>
<td><strong><?=e($o['order_code'])?></strong><small><?=e($o['buyer_email']??'')?></small></td>
<td><?=e($o['reservation_code']??'—')?></td>
<td><?= (int)$o['confirmed_count'] ?> / <?= (int)$o['ticket_count'] ?> confirmados</td>
<td><span class="status <?=e($o['state']==='acked'?'active':($o['state']==='failed'?'error':'pending'))?>"><?=e($o['state'])?></span></td>
<td><?=e($o['last_attempt_at']?date('d/m/Y H:i:s',strtotime($o['last_attempt_at'])):'—')?></td>
<td><small><?=e($o['last_error']??'')?></small></td>
<td><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$o['id']?>"><button class="button" type="submit">Reprocessar</button></form></td>
</tr>
<?php $ts=db()->prepare('SELECT t.*,r.hcp_visitor_id,r.hcp_reference,r.hcp_delivery_state FROM acquavale_import_tickets t LEFT JOIN reservations r ON r.id=t.local_reservation_id WHERE t.import_order_id=? ORDER BY t.id');$ts->execute([$o['id']]); foreach($ts->fetchAll() as $t):?>
<tr><td style="padding-left:34px"><strong><?=e($t['ticket_code'])?></strong><small><?=e($t['product_name']??'')?></small></td><td><?= $t['local_reservation_id'] ? '#'.(int)$t['local_reservation_id'] : '—' ?></td><td><small>HCP Visitor <?=e($t['hcp_visitor_id']??'—')?><br>Ref <?=e($t['hcp_reference']??'—')?></small></td><td><span class="status <?=e(in_array($t['state'],['confirmed','acked'],true)?'active':($t['state']==='failed'?'error':'pending'))?>"><?=e($t['state'])?></span></td><td><?=e($t['last_attempt_at']?date('d/m/Y H:i:s',strtotime($t['last_attempt_at'])):'—')?></td><td><small><?=e($t['last_error']??'')?></small></td><td><?php if($t['local_reservation_id']):?><a class="link" href="view.php?id=<?=(int)$t['local_reservation_id']?>">Abrir</a><?php endif;?></td></tr>
<?php endforeach;?>
<?php endforeach;?>
</tbody></table></div>
<?php include __DIR__.'/footer.php'; ?>
