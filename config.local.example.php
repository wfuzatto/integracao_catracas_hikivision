<?php
// Salve uma cópia como config.local.php e preencha somente após habilitar
// o OpenAPI no HikCentral. Este arquivo não deve ser publicado.
putenv('VALE_HCP_BASE_URL=https://127.0.0.1');
putenv('VALE_HCP_APP_KEY=COLE_AQUI_O_APP_KEY');
putenv('VALE_HCP_APP_SECRET=COLE_AQUI_O_APP_SECRET');
putenv('VALE_HCP_USER_ID=admin');
putenv('VALE_HCP_TLS_VERIFY=0');
// JSON com os IDs reais dos níveis no HikCentral:
// putenv('VALE_HCP_ACCESS_LEVELS={"Day use":"12345","Hóspede Vale":"12346","Hóspede Serra":"12347"}');


// AcquaVale Vendas -> Vale Visitor. Use a mesma API key do site e um
// segredo HMAC exclusivo, igual nos dois lados. Não publique estes valores.
putenv('VALE_AQV_WEB_BASE_URL=https://SEU-DOMINIO/sites/acquavale/public_html');
putenv('VALE_AQV_API_KEY=API_KEY_DO_ACQUAVALE_VENDAS');
putenv('VALE_AQV_SHARED_SECRET=SEGREDO_HMAC_LONGO_E_ALEATORIO');
putenv('VALE_AQV_WEB_TLS_VERIFY=1');
putenv('VALE_AQV_SIGNATURE_MAX_SKEW=300');
putenv('VALE_AQV_PROCESS_ON_RECEIVE=0');
// putenv('VALE_AQV_MAX_PHOTO_BYTES=10485760');
