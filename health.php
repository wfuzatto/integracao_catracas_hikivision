<?php
require_once __DIR__ . '/hcp.php';
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

    <div class="notice">
        <strong>Fluxo:</strong> salvar reserva local → enviar ao HikCentral → criar reserva oficial com foto e validade → aplicar access level → receber o QR Code oficial.
    </div>
    <div class="notice">
        <strong>Importante:</strong> o login <code>admin</code> do Web Client não substitui AppKey/AppSecret. O OpenAPI precisa estar habilitado/licenciado no HikCentral; na verificação anterior o servidor respondeu código 217.
    </div>
    <p class="muted">Arquivo de configuração: <code>config.local.php</code>. Use <code>config.local.example.php</code> como modelo. Não coloque a senha do administrador nesse arquivo.</p>
</section>
<?php include __DIR__ . '/footer.php'; ?>
