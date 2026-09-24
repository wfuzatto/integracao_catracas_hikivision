<?php
require_once __DIR__ . '/acquavale.php';
$aqvPending = 0;
$aqvFailed = 0;
$aqvLastWorker = null;
try {
    $aqvPending = (int)db()->query("SELECT COUNT(*) FROM acquavale_import_orders WHERE state IN ('received','processing')")->fetchColumn();
    $aqvFailed = (int)db()->query("SELECT COUNT(*) FROM acquavale_import_orders WHERE state='failed'")->fetchColumn();
    $aqvLastWorker = db()->query('SELECT MAX(last_attempt_at) FROM acquavale_import_orders')->fetchColumn() ?: null;
} catch (Throwable) {
}
$pageTitle = 'Integração';
$mapped = count(HCP_ACCESS_LEVEL_MAP);
include __DIR__ . '/header.php';
?>
<section class="hero compact">
    <div>
        <p class="eyebrow">HikCentral Professional</p>
        <h1>Integração Visitor</h1>
        <p class="muted">Diagnóstico do conector e preparação para o envio das reservas.</p>
    </div>
</section>

<section class="card">
    <div class="integration-row">
        <span class="dot good"></span>
        <strong>HikCentral Web Client</strong>
        <span class="muted">servidor local em <?= e(HCP_BASE_URL) ?></span>
    </div>
    <div class="integration-row">
        <span class="dot <?= hcp_configured() ? 'good' : 'warn' ?>"></span>
        <strong>OpenAPI</strong>
        <span class="muted"><?= hcp_configured() ? 'credenciais configuradas' : 'AppKey/AppSecret ainda não configurados' ?></span>
    </div>
    <div class="integration-row">
        <span class="dot <?= $mapped ? 'good' : 'warn' ?>"></span>
        <strong>Access levels</strong>
        <span class="muted"><?= (int)$mapped ?> mapeado(s) no aplicativo</span>
    </div>
    <div class="integration-row">
        <span class="dot <?= aqv_configured() ? 'good' : 'warn' ?>"></span>
        <strong>AcquaVale Vendas</strong>
        <span class="muted"><?= aqv_configured() ? 'webhook, fotos e callback configurados' : 'integração ainda não configurada' ?></span>
    </div>

    <div class="integration-row">
        <span class="dot <?= hcp_configured() ? 'good' : 'warn' ?>"></span>
        <strong>HikCentral</strong>
        <span class="muted"><?= hcp_configured() ? 'configurado; conectividade validada durante a entrega' : 'OFFLINE/sem credenciais OpenAPI' ?></span>
    </div>
    <div class="integration-row">
        <span class="dot <?= $aqvLastWorker !== null ? 'good' : 'warn' ?>"></span>
        <strong>Worker AcquaVale</strong>
        <span class="muted"><?= e($aqvLastWorker ?: 'nao executado') ?> · pendentes: <?= (int)$aqvPending ?> · falhas: <?= (int)$aqvFailed ?></span>
    </div>
    <div class="integration-row">
        <span class="dot <?= auto_checkout_enabled() ? 'good' : 'warn' ?>"></span>
        <strong>Checkouts automaticos</strong>
        <span class="muted"><?= auto_checkout_enabled() ? 'ATIVO' : 'DESATIVADO' ?> · processo separado do worker AcquaVale</span>
    </div>
    <div class="notice">
        <strong>Fluxo:</strong> salvar reserva local → enviar ao HikCentral → criar reserva oficial com foto e validade → aplicar access level → receber o QR Code oficial.
    </div>
    <div class="notice">
        <strong>Importante:</strong> o login <code>admin</code> do Web Client não substitui AppKey/AppSecret. O OpenAPI precisa estar habilitado/licenciado no HikCentral; na verificação anterior o servidor respondeu código 217.
    </div>
    <p class="muted">Arquivo de configuração: <code>config.local.php</code>. Use <code>config.local.example.php</code> como modelo. Não coloque a senha do administrador nesse arquivo.</p>
    <div class="notice">
        <strong>Vendas online:</strong> o receiver persiste primeiro; o worker reutiliza <code>visitor_sync()</code>, aguarda a confirmação de face/credencial nas catracas e só então envia o ACK ao site. O checkout e o auto-checkout continuam em fluxo separado.
    </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
