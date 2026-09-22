<?php
declare(strict_types=1);

const APP_NAME = 'Vale Visitor';
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'visitor_app';
const DB_USER = 'root';
const DB_PASS = '';

// O acesso de administrador do HikCentral não é usado pelo sistema.
// Para integração oficial, configure uma aplicação OpenAPI no HikCentral.
// Opcional: crie config.local.php a partir do exemplo para não guardar segredos
// no arquivo principal da aplicação.
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) require_once $localConfig;
define('HCP_BASE_URL', getenv('VALE_HCP_BASE_URL') ?: 'https://127.0.0.1');
define('HCP_APP_KEY', getenv('VALE_HCP_APP_KEY') ?: '');
define('HCP_APP_SECRET', getenv('VALE_HCP_APP_SECRET') ?: '');
define('HCP_USER_ID', getenv('VALE_HCP_USER_ID') ?: 'admin');
define('HCP_TLS_VERIFY', getenv('VALE_HCP_TLS_VERIFY') === '1');

// Preencha os IDs reais dos access levels depois de criá-los no HikCentral.
// Exemplo: ['Day use' => '12345', 'Hóspede Vale' => '12346']
define('HCP_ACCESS_LEVEL_MAP', json_decode(getenv('VALE_HCP_ACCESS_LEVELS') ?: '[]', true) ?: []);

date_default_timezone_set('America/Sao_Paulo');
