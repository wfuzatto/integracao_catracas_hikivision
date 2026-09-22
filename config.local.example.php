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
