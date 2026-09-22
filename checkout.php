<?php
require_once __DIR__ . '/delivery.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

csrf_check();
$id = (int)($_POST['id'] ?? 0);

try {
    $result = visitor_checkout($id);
    flash($result['message']);
} catch (Throwable $e) {
    flash($e->getMessage(), 'error');
}

redirect('view.php?id=' . $id);
