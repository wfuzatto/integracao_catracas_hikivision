# Vale Visitor

Aplicativo PHP/MySQL do XAMPP para reservas e credenciais do Visitor do HikCentral.

## Envio para as catracas

1. Cadastre nome, foto, grupo, segmento (access level) e validade do ingresso.
2. Use **Enviar ao HikCentral** ou **Sincronizar segmento e credenciais** no detalhe.
3. O conector cria a reserva, registra a visita pela OpenAPI, associa o visitante ao nível selecionado e solicita a aplicação somente nas portas desse nível.
4. A tela mostra o resultado por catraca, distinguindo solicitação pendente de aplicação confirmada pelo HikCentral.
5. O QR apresentado após o registro é a credencial de acesso devolvida pelo HikCentral.

O registro técnico da visita corresponde ao check-in do módulo Visitor. Não é evidência de passagem física. As datas do ingresso são preservadas e a sincronização recusa ingressos expirados.

O segmento local deve ter o mesmo nome do nível no módulo Visitor. O conector consulta os níveis do tipo Visitor (type=2) e suas portas pela API. Não envia para todas as portas do servidor. Em uma troca de segmento, remove apenas o nível anteriormente atribuído por este conector.

A sincronização reutiliza o cadastro e a visita existentes. Um bloqueio por reserva evita envios simultâneos. O resultado por catraca e os IDs de reserva e registro são guardados separadamente.

## Configuração

- Configure a OpenAPI no HikCentral e as credenciais em config.local.php.
- Autorize criação de reserva e registro de visitante, consulta de registros e níveis, associação/remoção de visitante do nível, reaplicação e consulta de resultado.
- Em instalações existentes, aplique migrations/20260920_visitor_delivery.sql. A migração já foi aplicada neste servidor.
- Para instalações novas, schema.sql contém os campos de acompanhamento de entrega.

## Checkout seguro de visitas

O status da integração (reserva enviada e credencial liberada) é separado do estado da visita no HikCentral. No detalhe há um botão de consulta somente-leitura, que atualiza o estado e o horário da última consulta sem encerrar a visita. O fluxo da visita distingue reserva registrada, check-in, checkout, auto checkout e visita atrasada.

O checkout só fica disponível quando o integrador criou o registro de check-in. Antes de enviar, o sistema consulta o estado no HikCentral e só encerra estados de check-in ou atrasados. Se `getVisitorStatus` estiver sem permissão ou retornar recurso inexistente, consulta `getVistorRegisterRecord` e exige correspondência única por ID do registro, visitante e intervalo da reserva; qualquer ambiguidade interrompe a operação sem enviar checkout. O endpoint `visitor/out` recebe somente o `appointRecordId` retornado pelo check-in. Não há limpeza global de pessoas, permissões ou catracas.

Em instalações existentes, aplique `migrations/20260922_add_visitor_checkout.sql` e `migrations/20260922_add_auto_checkout_collector.sql`. Para instalações novas, `schema.sql` já contém os campos e a configuração do coletor. Autorize `POST /artemis/api/visitor/v1/appointment/getVisitorStatus` ou, como fallback, `POST /artemis/api/visitor/v1/register/getVistorRegisterRecord`, além do endpoint de checkout `POST /artemis/api/visitor/v1/visitor/out`, para a aplicação OpenAPI. A consulta de status pode usar o registro de check-in exato quando a rota de status não estiver publicada nesta instalação.

O auto checkout é desativado por padrão. Ative-o em **Checkouts** e configure o Agendador de Tarefas do Windows para executar a cada 5 minutos, com a ação `C:\xampp\php\php.exe` e argumento `C:\xampp\htdocs\visitor\auto_checkout_collector.php`. O script só pode ser executado via CLI e processa no máximo 50 reservas expiradas por rodada.

O QR local de contingência não é uma credencial aceita automaticamente pela catraca.

## Verificação real — 20/09/2026

O cadastro existente de Wesley foi registrado e associado ao segmento ENTRADA ACQUAVALE.
Consultas ISAPI diretas confirmaram a mesma pessoa nas controladoras 192.168.104.12, .13, .14 e .15, com uma face, uma credencial e validade de 20/09/2026 08:00 até 23:59.
A repetição do envio manteve um único registro de visita no HikCentral.
Não foi realizado comando de abertura nem teste presencial de passagem.


## Integração com AcquaVale Vendas

Esta versão recebe automaticamente as vendas pagas do `acquavale_vendas` sem alterar o fluxo atual de checkout do Visitor.

Fluxo:

1. O site confirma o pagamento e envia um webhook HMAC para `acquavale_receive.php`.
2. O Vale Visitor persiste pedido e tickets e responde rapidamente; o receiver não espera o HikCentral.
3. `source_ticket_code` é a chave idempotente: reenvios/reinícios não criam outro visitante.
4. A foto é baixada por HTTPS com Bearer API key e salva em `storage/private/acquavale_faces/`; não é BLOB no MySQL.
5. O ticket cria uma reserva local `REGISTERED` no grupo `Day use`, com `ENTRADA ACQUAVALE` + `SAIDA ACQUAVALE` e validade do ingresso.
6. `acquavale_worker.php` reutiliza o `visitor_sync()` desta versão, incluindo a criação no HikCentral, face, access levels, reaplicação e o double check por catraca.
7. Somente `hcp_delivery_state=confirmed` gera `sale-ticket-status=confirmed` no site.
8. Quando todos os tickets do pedido estão confirmados, o worker envia `sale-ack`.
9. O checkout manual/automático existente permanece separado. Vendas online entram como `REGISTERED`; nenhum check-in é forçado durante a importação.

Configuração privada em `config.local.php`:

```php
putenv('VALE_AQV_WEB_BASE_URL=https://SEU-DOMINIO/sites/acquavale/public_html');
putenv('VALE_AQV_API_KEY=API_KEY_DO_ACQUAVALE_VENDAS');
putenv('VALE_AQV_SHARED_SECRET=SEGREDO_HMAC_IGUAL_NOS_DOIS_SISTEMAS');
putenv('VALE_AQV_WEB_TLS_VERIFY=1');
putenv('VALE_AQV_SIGNATURE_MAX_SKEW=300');
putenv('VALE_AQV_PROCESS_ON_RECEIVE=0');
```

Aplique `migrations/20260922_acquavale_sales_bridge.sql` em instalações existentes. Para retries automáticos no Windows, execute:

```powershell
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\visitor\scripts\install_acquavale_task.ps1
```

A tarefa `ValeVisitor-AcquaValeSync` roda a cada minuto e pode coexistir com o coletor de auto-checkout, que tem responsabilidade diferente. O instalador usa o usuário atual, sem exigir administrador; esse usuário precisa estar conectado ao Windows. O processo roda com a janela oculta. O resultado da última execução fica em `storage/logs/acquavale-worker-last.json` e os erros PHP em `storage/logs/acquavale-worker-last-error.log`.

O worker e o botão **Sincronizar agora** também consultam `sale-next` na loja configurada antes de processar os pedidos locais. Isso recupera vendas quando o webhook não chega ao servidor, sem exigir uma conexão de entrada pela internet. Configure a URL e a API key da **loja de produção**, não da cópia local. Pedidos já marcados como `claimed` ficam disponíveis após o prazo de claim da loja (normalmente 10 minutos). Reenvios usam o mesmo código de ingresso e não criam outra reserva. A falha na consulta da loja é exibida na tela e não impede o processamento de pedidos já recebidos.

Teste de regressão local, sem chamadas à loja ou ao HikCentral: `C:\xampp\php\php.exe C:\xampp\htdocs\visitor\scripts\test_acquavale_import.php`. O teste cria registros identificados por um código único e os remove ao terminar.

### Firewall

Não exponha o HikCentral. Publique somente `acquavale_receive.php` em HTTPS no Vale Visitor. Recomenda-se TCP 443; se a WAN 443 estiver ocupada, use por exemplo TCP 8443 externo com NAT para TCP 443 interno. Restrinja as demais páginas à LAN sempre que possível.
