<?php
require_once __DIR__ . '/delivery.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit; }
csrf_check();
try { echo json_encode(visitor_refresh_delivery((int)($_POST['id']??0)),JSON_UNESCAPED_UNICODE); }
catch(Throwable $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }

