<?php require_once __DIR__ . '/bootstrap.php'; $pageTitle = $pageTitle ?? APP_NAME; $f = flash(); ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar"><div class="brand">Vale Visitor <span>Visitor</span></div><nav><a href="index.php">Reservas</a><a href="checkouts.php">Checkouts</a><a href="new.php">Nova reserva</a><a href="groups.php">Grupos e níveis</a><a href="acquavale_imports.php">Vendas online</a><a href="health.php">Integração</a></nav></header>
<main class="container">
<?php if ($f): ?><div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endif; ?>
