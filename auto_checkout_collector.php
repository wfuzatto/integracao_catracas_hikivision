<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/delivery.php';
ensure_auto_checkout_settings();
if (!auto_checkout_enabled()) {
    echo "Coletor desativado.\n";
    exit(0);
}
$lock = db()->query("SELECT GET_LOCK('auto_checkout_collector',0)");
if ((int)$lock->fetchColumn() !== 1) {
    echo "Outra execução do coletor ainda está ativa.\n";
    exit(0);
}
$results = [];
try {
    foreach (visitor_checkout_candidates(50) as $id) {
        try {
            $result = visitor_checkout($id, true);
            $results[] = ['reservation_id' => $id, 'state' => $result['result']['state']];
        } catch (Throwable $e) {
            $results[] = ['reservation_id' => $id, 'state' => 'failed', 'message' => $e->getMessage()];
        }
    }
} finally {
    db()->query("SELECT RELEASE_LOCK('auto_checkout_collector')");
}
echo json_encode(['processed' => count($results), 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
