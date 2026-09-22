<?php
require_once __DIR__ . '/bootstrap.php';
$pageTitle = 'Grupos e níveis';
$groups = db()->query('SELECT * FROM guest_groups ORDER BY name')->fetchAll();
$levels = db()->query('SELECT * FROM access_levels ORDER BY name')->fetchAll();
include __DIR__ . '/header.php';
?>
<section class="hero compact">
    <div>
        <p class="eyebrow">Configuração</p>
        <h1>Grupos e access levels</h1>
        <p class="muted">Os cadastros iniciais já foram criados e podem ser ampliados depois.</p>
    </div>
</section>
<div class="detail-grid">
    <section class="card"><h2>Grupos</h2><ul class="simple-list"><?php foreach ($groups as $g): ?><li><?= e($g['name']) ?></li><?php endforeach; ?></ul><form action="save_catalog.php" method="post" class="actions"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="type" value="group"><input name="name" placeholder="Novo grupo" required maxlength="120"><button class="button primary">Adicionar</button></form></section>
    <section class="card"><h2>Access levels</h2><ul class="simple-list"><?php foreach ($levels as $a): ?><li><?= e($a['name']) ?></li><?php endforeach; ?></ul><form action="save_catalog.php" method="post" class="actions"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="type" value="access"><input name="name" placeholder="Novo access level" required maxlength="120"><button class="button primary">Adicionar</button></form></section>
</div>
<?php include __DIR__ . '/footer.php'; ?>
