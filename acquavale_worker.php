<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/acquavale.php';

if (!aqv_configured()) { fwrite(STDERR,"Integração AcquaVale não configurada.\n"); exit(2); }
$result=aqv_sync_sales(50);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
exit(empty($result['errors']) ? 0 : 1);
