<?php
declare(strict_types=1);
require_once __DIR__ . '/acquavale.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    aqv_json_response(['ok'=>true,'service'=>'vale-visitor-acquavale-receiver','configured'=>aqv_configured(),'time'=>date(DATE_ATOM)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') aqv_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);

$raw=file_get_contents('php://input');
if (!is_string($raw) || $raw==='') aqv_json_response(['ok'=>false,'error'=>'empty_body'],400);

try {
    $deliveryId=aqv_verify_signature($raw);
    $payload=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new InvalidArgumentException('JSON inválido.');
    $importOrderId=aqv_accept_payload($payload,$deliveryId);

    // Persist first, then try one immediate pass. A scheduled worker handles retries.
    $processed=null;
    if (AQV_PROCESS_ON_RECEIVE) {
        try { $processed=aqv_process_order($importOrderId); } catch (Throwable $e) { $processed=['ok'=>false,'error'=>$e->getMessage()]; }
    }
    aqv_json_response(['ok'=>true,'accepted'=>true,'import_order_id'=>$importOrderId,'processing'=>$processed],202);
} catch (JsonException $e) {
    aqv_json_response(['ok'=>false,'error'=>'invalid_json','message'=>'JSON inválido.'],400);
} catch (InvalidArgumentException $e) {
    aqv_json_response(['ok'=>false,'error'=>'invalid_payload','message'=>$e->getMessage()],422);
} catch (Throwable $e) {
    error_log('[AcquaVale receiver] '.$e->getMessage());
    aqv_json_response(['ok'=>false,'error'=>'receiver_error','message'=>$e->getMessage()],401);
}
