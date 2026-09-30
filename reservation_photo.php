<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare("SELECT photo_path FROM reservations WHERE id=? AND source_system='acquavale_vendas'");
$stmt->execute([$id]);
$reservation = $stmt->fetch();
$file = $reservation ? reservation_face_photo_file($reservation['photo_path'] ?? null) : null;
if ($file === null) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline; filename="visitor-photo.' . match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    default => 'webp',
} . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($file);
