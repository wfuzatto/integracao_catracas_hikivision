<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/acquavale.php';

$watch = in_array('--watch', $argv ?? [], true);
do {
try {
    if (!aqv_configured()) throw new RuntimeException('Integração AcquaVale não configurada.');
    $result=aqv_sync_sales(50);
    $result['pending_by_state'] = db()->query("SELECT state,COUNT(*) AS total FROM acquavale_import_orders WHERE state<>'acked' GROUP BY state")->fetchAll();
    $exitCode=empty($result['errors']) ? 0 : 1;
} catch (Throwable $e) {
    $result=['processed'=>0,'acked'=>0,'received'=>0,'errors'=>[['error'=>$e->getMessage()]]];
    $exitCode=1;
}
$result['checked_at'] = date(DATE_ATOM);
$result['mode'] = $watch ? 'watch' : 'once';
$json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
$logDir=__DIR__.'/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir,0750,true);
@file_put_contents($logDir.'/acquavale-worker-last.json',$json.PHP_EOL,LOCK_EX);
echo $json.PHP_EOL;
if ($watch) sleep(5);
} while ($watch);
exit($exitCode);
