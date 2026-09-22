<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function csrf_check(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Sessão expirada. Volte e tente novamente.'); } }
function redirect(string $path): never { header('Location: ' . $path); exit; }
function flash(?string $message = null, string $type = 'success'): ?array {
    if ($message !== null) { $_SESSION['flash'] = ['message' => $message, 'type' => $type]; return null; }
    $value = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $value;
}
function dt_db(string $value): string {
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $value) ?: DateTime::createFromFormat('Y-m-d H:i', $value);
    if (!$d) throw new InvalidArgumentException('Data inválida.');
    return $d->format('Y-m-d H:i:s');
}
function dt_input(?string $value): string { return $value ? date('Y-m-d\TH:i', strtotime($value)) : ''; }
function iso8601(string $value): string { return (new DateTime($value, new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i:sP'); }
function status_label(string $status): string { return ['PENDING'=>'Pendente','SENT'=>'Enviado ao HikCentral','ACTIVE'=>'Credencial ativa','ERROR'=>'Erro na integração','CANCELLED'=>'Cancelado'][$status] ?? $status; }
function status_class(string $status): string { return strtolower($status); }
function visitor_flow_label(?string $status): string { return ($status ?? 'REGISTERED') === 'CHECKED_IN' ? 'Check-in imediato' : 'Apenas cadastrado'; }
function visitor_state_label(?string $state, array $reservation = []): string {
    $labels = [
        'reserved'=>'Reserva registrada','expired'=>'Reserva expirada','visited'=>'Visita registrada',
        'checked_in'=>'Check-in realizado','checked_out'=>'Checkout realizado',
        'auto_checked_out'=>'Auto checkout','self_checked_out'=>'Checkout no autoatendimento',
        'overdue'=>'Sem checkout após o horário','pending_approval'=>'Aguardando aprovação',
        'reservation_failed'=>'Reserva recusada','unknown'=>'Status indefinido no HikCentral',
    ];
    if (isset($labels[$state ?? ''])) return $labels[$state];
    if (($reservation['status'] ?? '') === 'CANCELLED') return 'Reserva cancelada';
    if (in_array($reservation['hcp_checkout_state'] ?? '', ['checked_out','already_closed'], true)) return 'Checkout encerrado';
    if (!empty($reservation['hcp_registration_id'])) return 'Check-in registrado — status a consultar';
    if (!empty($reservation['hcp_reference'])) return 'Reserva registrada — aguardando check-in';
    if (($reservation['status'] ?? '') === 'PENDING') return 'Não enviada ao HikCentral';
    return 'Status HikCentral não consultado';
}
function visitor_state_class(?string $state): string {
    return match ($state) {
        'checked_in','overdue'=>'active',
        'checked_out','auto_checked_out','self_checked_out','visited'=>'success',
        'reservation_failed'=>'error',
        default=>'pending',
    };
}
function ensure_visitor_flow_column(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $st = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND COLUMN_NAME='visitor_flow_status'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        db()->exec("ALTER TABLE reservations ADD COLUMN visitor_flow_status ENUM('REGISTERED','CHECKED_IN') NOT NULL DEFAULT 'REGISTERED' AFTER status");
    }
}

function ensure_visitor_checkout_columns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $columns = [
        'hcp_checkout_state' => "VARCHAR(24) NULL AFTER hcp_delivery_verified_at",
        'hcp_checkout_report' => "LONGTEXT NULL AFTER hcp_checkout_state",
        'hcp_checkout_at' => "DATETIME NULL AFTER hcp_checkout_report",
        'hcp_visit_state' => "VARCHAR(32) NULL AFTER hcp_checkout_at",
        'hcp_visit_status_checked_at' => "DATETIME NULL AFTER hcp_visit_state",
    ];
    foreach ($columns as $name => $definition) {
        $st = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND COLUMN_NAME=?");
        $st->execute([$name]);
        if ((int)$st->fetchColumn() === 0) {
            db()->exec("ALTER TABLE reservations ADD COLUMN {$name} {$definition}");
        }
    }
}

function ensure_auto_checkout_settings(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(64) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    db()->prepare("INSERT IGNORE INTO app_settings (setting_key,setting_value) VALUES ('auto_checkout_enabled','0')")->execute();
}

function auto_checkout_enabled(): bool {
    ensure_auto_checkout_settings();
    $st = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key='auto_checkout_enabled'");
    $st->execute();
    return $st->fetchColumn() === '1';
}

session_start();
