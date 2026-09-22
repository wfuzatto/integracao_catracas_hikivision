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


// AcquaVale Vendas -> Vale Visitor. O site envia apenas para este receiver;
// o HikCentral continua restrito à rede local.
define('AQV_WEB_BASE_URL', rtrim(getenv('VALE_AQV_WEB_BASE_URL') ?: '', '/'));
define('AQV_WEB_API_KEY', getenv('VALE_AQV_API_KEY') ?: '');
define('AQV_SHARED_SECRET', getenv('VALE_AQV_SHARED_SECRET') ?: '');
define('AQV_WEB_TLS_VERIFY', getenv('VALE_AQV_WEB_TLS_VERIFY') !== '0');
define('AQV_SIGNATURE_MAX_SKEW_SECONDS', max(60, (int)(getenv('VALE_AQV_SIGNATURE_MAX_SKEW') ?: 300)));
define('AQV_PROCESS_ON_RECEIVE', getenv('VALE_AQV_PROCESS_ON_RECEIVE') === '1');
define('AQV_MAX_PHOTO_BYTES', max(1024 * 1024, (int)(getenv('VALE_AQV_MAX_PHOTO_BYTES') ?: 10 * 1024 * 1024)));

date_default_timezone_set('America/Sao_Paulo');
