<?php
declare(strict_types=1);
require dirname(__DIR__) . '/delivery_report.php';
$assert = static function(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$r = ['photo_path'=>'face.jpg'];
$level = ['privilegeGroupId'=>'1','privilegeGroupName'=>'Entrada',
    'ElementList'=>[['Element'=>['ID'=>'3','BaseInfo'=>['Name'=>'Catraca']]]]];
$detail = ['elementID'=>'3','ElementStatus'=>[['elementStatus'=>0]],
    'CertificateStatusList'=>['CertificateStatus'=>[['type'=>0,'status'=>0],['type'=>2,'status'=>0]]]];
$parse = static fn(array $doors, array $l = []) => visitor_parse_delivery(['ElementDetailList'=>['ElementDetail'=>$doors]], $r, $l ?: $level);
$assert($parse([$detail])['state']==='confirmed','Face e credencial aceitas devem confirmar.');
$assert($parse([])['state']==='queued','Resposta vazia nao confirma.');
$assert(visitor_parse_delivery([], $r, [])['state']==='queued','Sem catracas nao confirma.');
$missingFace=$detail;
array_pop($missingFace['CertificateStatusList']['CertificateStatus']);
$assert($parse([$missingFace])['state']==='queued','Credencial sem face nao confirma.');
$rejected=$detail;
$rejected['ElementStatus'][0]['elementStatus']=2;
$rejected['CertificateStatusList']['CertificateStatus'][1]=['type'=>2,'status'=>2,'error Msg'=>'Unknow Error(53, 1610637371)'];
$report=$parse([$rejected]);
$assert($report['state']==='failed' && $report['doors'][0]['faceState']==='failed','Foto recusada deve falhar.');
$assert($report['doors'][0]['credential']===true,'Recusa facial nao deve esconder credencial aceita.');
$assert($report['doors'][0]['certificates'][1]['error Msg']==='Unknow Error(53, 1610637371)','Codigo original deve ser preservado.');
$two=$level;
$two['ElementList'][]=['Element'=>['ID'=>'14']];
$assert($parse([$detail],$two)['state']==='queued','Todas as catracas esperadas devem responder.');
echo "OK: confirmacao completa, resposta vazia, face ausente/recusada, erro original e catraca pendente.\n";
