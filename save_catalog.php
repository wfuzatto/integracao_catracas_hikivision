<?php
require_once __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('groups.php');
csrf_check();
$type = $_POST['type'] ?? '';
$name = trim($_POST['name'] ?? '');
try {
    if (!in_array($type, ['group', 'access'], true) || $name === '') {
        throw new InvalidArgumentException('Informe um nome válido.');
    }
    $table = $type === 'group' ? 'guest_groups' : 'access_levels';
    $st = db()->prepare("INSERT INTO {$table} (name) VALUES (?)");
    $st->execute([$name]);
    flash(($type === 'group' ? 'Grupo' : 'Access level') . ' criado.');
} catch (Throwable $e) {
    flash('Não foi possível salvar: ' . $e->getMessage(), 'error');
}
redirect('groups.php');
