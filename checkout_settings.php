<?php
require_once __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_check();
ensure_auto_checkout_settings();
$enabled = ($_POST['enabled'] ?? '') === '1' ? '1' : '0';
db()->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='auto_checkout_enabled'")->execute([$enabled]);
flash($enabled === '1' ? 'Coletor de auto checkout ativado.' : 'Coletor de auto checkout desativado.');
redirect('checkouts.php');
