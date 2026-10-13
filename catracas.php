<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/hcp.php';
require_once __DIR__ . '/door_control_lib.php';

function door_control_ensure_tables(): void
{
    // Tambem disponivel em migrations/20261013_door_control.sql.
    db()->exec("CREATE TABLE IF NOT EXISTS door_control_states (
        door_index_code VARCHAR(64) NOT NULL,
        direction TINYINT UNSIGNED NOT NULL,
        free_passage TINYINT(1) NOT NULL DEFAULT 0,
        last_action_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (door_index_code, direction)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS door_control_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        door_index_code VARCHAR(64) NOT NULL,
        door_name VARCHAR(255) NOT NULL,
        direction TINYINT UNSIGNED NOT NULL,
        action VARCHAR(12) NOT NULL,
        result VARCHAR(12) NOT NULL,
        client_ip VARCHAR(45) NOT NULL,
        message VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_door_created (door_index_code, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function door_control_authorized(): bool
{
    $authorizedAt = $_SESSION['hcp_door_authorized_at'] ?? null;
    return HCP_DOOR_CONTROL_PASSWORD_HASH !== '' && is_int($authorizedAt) && (time() - $authorizedAt) < 3600;
}

function door_control_log(string $id, string $name, int $direction, string $action, string $result, string $message): void
{
    db()->prepare("INSERT INTO door_control_audit
        (door_index_code, door_name, direction, action, result, client_ip, message)
        VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$id, mb_substr($name, 0, 255), $direction, $action, $result,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45), mb_substr($message, 0, 500)]);
}

function door_control_current_rows(): array
{
    $rows = db()->query("SELECT door_index_code, direction, free_passage, last_action_at, UNIX_TIMESTAMP(last_action_at) AS last_action_ts FROM door_control_states")->fetchAll();
    $result = [];
    foreach ($rows as $row) $result[(string)$row['door_index_code']][(int)$row['direction']] = $row;
    return $result;
}

function door_control_url(string $id = '', string $direction = '0'): string
{
    return 'catracas.php?door=' . rawurlencode($id) . '&direction=' . rawurlencode($direction);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    if ($op === 'logout') {
        unset($_SESSION['hcp_door_authorized_at']);
        redirect('catracas.php');
    }
    if ($op === 'login') {
        $blockedUntil = (int)($_SESSION['hcp_door_blocked_until'] ?? 0);
        if ($blockedUntil > time()) {
            flash('Muitas tentativas. Tente novamente em um minuto.', 'error');
        } elseif (HCP_DOOR_CONTROL_PASSWORD_HASH !== '' &&
            password_verify((string)($_POST['password'] ?? ''), HCP_DOOR_CONTROL_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION['hcp_door_authorized_at'] = time();
            unset($_SESSION['hcp_door_attempts'], $_SESSION['hcp_door_blocked_until']);
            flash('Controle manual de catracas autorizado.');
        } else {
            $_SESSION['hcp_door_attempts'] = (int)($_SESSION['hcp_door_attempts'] ?? 0) + 1;
            if ($_SESSION['hcp_door_attempts'] >= 5) {
                $_SESSION['hcp_door_blocked_until'] = time() + 60;
                $_SESSION['hcp_door_attempts'] = 0;
            }
            flash('Senha incorreta ou controle manual não configurado.', 'error');
        }
        redirect('catracas.php');
    }

    if ($op !== 'control' || !door_control_authorized()) {
        http_response_code(403);
        exit('Comando não autorizado.');
    }

    $doorId = (string)($_POST['door_id'] ?? '');
    $directionValue = (string)($_POST['direction'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    try {
        $directions = door_control_directions($directionValue);
        if (!in_array($action, ['release', 'close'], true)) throw new InvalidArgumentException('Comando inválido.');
        if ($doorId === '' || strlen($doorId) > 64) throw new InvalidArgumentException('Catraca inválida.');

        $client = new HcpOpenApiClient();
        $doors = $client->listAccessDoors();
        if (!isset($doors[$doorId])) throw new InvalidArgumentException('Catraca não encontrada no HikCentral.');
        $door = $doors[$doorId];
        $name = (string)($door['doorName'] ?? $doorId);
        foreach ($directions as $direction) {
            if (door_control_status($door, $direction)['offline']) {
                throw new RuntimeException('Catraca offline; comando não enviado.');
            }
        }

        door_control_ensure_tables();
        $lockKey = 'vale-door-' . sha1($doorId);
        $st = db()->prepare('SELECT GET_LOCK(?, 5)');
        $st->execute([$lockKey]);
        if ((int)$st->fetchColumn() !== 1) throw new RuntimeException('Catraca ocupada por outro comando. Tente novamente.');
        $ok = [];
        $failed = [];
        try {
            foreach ($directions as $direction) {
                try {
                    $response = $client->controlAccessDoor($doorId, $direction, $action === 'release');
                    door_control_check_result($response, $doorId);
                    db()->prepare("INSERT INTO door_control_states (door_index_code, direction, free_passage, last_action_at)
                        VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE free_passage=VALUES(free_passage), last_action_at=NOW()")
                        ->execute([$doorId, $direction, $action === 'release' ? 1 : 0]);
                    door_control_log($doorId, $name, $direction, $action, 'success', 'Aceito pela OpenAPI');
                    $ok[] = $direction === 0 ? 'entrada' : 'saída';
                } catch (Throwable $e) {
                    $failed[] = ($direction === 0 ? 'entrada' : 'saída') . ': ' . $e->getMessage();
                    try { door_control_log($doorId, $name, $direction, $action, 'error', $e->getMessage()); }
                    catch (Throwable $logError) { error_log('Falha ao auditar comando HikCentral: ' . $logError->getMessage()); }
                }
            }
        } finally {
            db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
        }
        if ($failed) {
            flash('Resultado parcial ou falha em ' . $name . '. ' .
                ($ok ? 'Concluído: ' . implode(', ', $ok) . '. ' : '') .
                implode('; ', $failed) . '. Confira a catraca antes de sair.', 'error');
        } else {
            flash(($action === 'release' ? 'LIBERAR' : 'FECHAR') . ' aceito pelo HikCentral para ' .
                $name . ' (' . implode(' e ', $ok) . ').');
        }
    } catch (Throwable $e) {
        flash('Comando não concluído: ' . $e->getMessage(), 'error');
    }
    redirect(door_control_url($doorId, in_array($directionValue, ['0','1','both'], true) ? $directionValue : '0'));
}

$pageTitle = 'Controle de catracas';
$authorized = door_control_authorized();
$selectedDoor = (string)($_GET['door'] ?? '');
$selectedDirection = (string)($_GET['direction'] ?? '0');
if (!in_array($selectedDirection, ['0','1','both'], true)) $selectedDirection = '0';
$doors = [];
$states = [];
$error = null;
if ($authorized) {
    try {
        door_control_ensure_tables();
        $doors = (new HcpOpenApiClient())->listAccessDoors();
        $stored = door_control_current_rows();
        foreach ($doors as $id => $door) {
            $states[$id] = [
                'name' => (string)($door['doorName'] ?? $id),
                'entry' => door_control_status($door, 0, $stored[$id][0] ?? null),
                'exit' => door_control_status($door, 1, $stored[$id][1] ?? null),
            ];
        }
        if (!isset($doors[$selectedDoor])) $selectedDoor = (string)(array_key_first($doors) ?? '');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
include __DIR__ . '/header.php';
?>
<section class="hero compact">
    <div>
        <p class="eyebrow">HikCentral · Controle manual</p>
        <h1>Controle de catracas</h1>
        <p class="muted">Libere a passagem temporariamente e encerre o modo livre quando necessário.</p>
    </div>
    <a class="button" href="index.php">Voltar</a>
</section>

<?php if (HCP_DOOR_CONTROL_PASSWORD_HASH === ''): ?>
    <div class="notice error-box"><strong>Controle desabilitado.</strong> Configure
        <code>VALE_HCP_DOOR_CONTROL_PASSWORD_HASH</code> em <code>config.local.php</code> antes de utilizar.
        A senha deve ser exclusiva para operação das catracas.</div>
<?php elseif (!$authorized): ?>
    <section class="card" style="max-width:460px">
        <h2>Acesso de operador</h2>
        <p class="muted">Informe a senha do controle manual. Esta autorização dura até 1 hora.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="login">
            <label>Senha do operador <input type="password" name="password" required autocomplete="current-password"></label>
            <div class="actions"><button type="submit" class="button primary">Entrar</button></div>
        </form>
    </section>
<?php else: ?>
    <form method="post" class="actions inline-actions" style="margin-bottom:18px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="logout">
        <button type="submit" class="button">Sair do controle</button>
    </form>

    <?php if ($error !== null): ?>
        <div class="notice error-box"><strong>Não foi possível consultar as catracas:</strong> <?= e($error) ?></div>
    <?php elseif (!$doors): ?>
        <div class="notice">Nenhuma catraca encontrada no HikCentral. Verifique a permissão de consulta de pontos de acesso.</div>
    <?php else: ?>
        <section class="card door-control-card">
            <h2>Passagem livre por catraca</h2>
            <form method="post" id="door-control-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="control">
                <input type="hidden" name="action" id="door-control-action" value="release">
                <div class="grid two">
                    <label>Catraca
                        <select name="door_id" id="door-control-select" required>
                            <?php foreach ($doors as $id => $door): ?>
                                <option value="<?= e((string)$id) ?>" <?= (string)$id === $selectedDoor ? 'selected' : '' ?>>
                                    <?= e((string)($door['doorName'] ?? $id)) ?> (<?= e((string)$id) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Sentido da passagem
                        <select name="direction" id="door-control-direction">
                            <option value="0" <?= $selectedDirection === '0' ? 'selected' : '' ?>>Entrada</option>
                            <option value="1" <?= $selectedDirection === '1' ? 'selected' : '' ?>>Saída</option>
                            <option value="both" <?= $selectedDirection === 'both' ? 'selected' : '' ?>>Entrada e saída (bidirecional)</option>
                        </select>
                    </label>
                </div>
                <div class="door-control-state">
                    <span id="door-control-state-text" class="status pending">Consultando...</span>
                    <p id="door-control-detail" class="muted"></p>
                </div>
                <div class="actions door-control-actions">
                    <button type="submit" id="door-control-button" class="button primary">LIBERAR</button>
                </div>
            </form>
            <div class="notice">
                <strong>LIBERAR:</strong> mantém o sentido escolhido em passagem livre, sem exigir QR, face ou cartão.
                <strong>FECHAR:</strong> encerra a passagem livre e devolve o controle por credencial; não usa o bloqueio permanente.
                Para catracas bidirecionais, escolha <em>Entrada e saída</em>. Se um dos lados falhar,
                o sistema informará o resultado parcial para que seja fechado manualmente.
            </div>
            <p class="muted">O estado exibido vem do HikCentral, com apoio do último comando aceito.
            Confirme o efeito físico no local: um comando aceito pela API não comprova o giro da catraca.</p>
        </section>
        <script>
        (() => {
            const data = <?= json_encode($states, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const door = document.getElementById('door-control-select');
            const direction = document.getElementById('door-control-direction');
            const button = document.getElementById('door-control-button');
            const action = document.getElementById('door-control-action');
            const state = document.getElementById('door-control-state-text');
            const detail = document.getElementById('door-control-detail');
            function render() {
                const info = data[door.value];
                if (!info) {
                    button.disabled = true;
                    state.textContent = 'Catraca não encontrada';
                    return;
                }
                const selected = direction.value === 'both' ? [info.entry, info.exit] :
                    [direction.value === '1' ? info.exit : info.entry];
                const offline = selected.some(item => item.offline);
                const free = selected.some(item => item.free);
                const allFree = selected.every(item => item.free);
                action.value = free ? 'close' : 'release';
                button.textContent = free ? 'FECHAR' : 'LIBERAR';
                button.className = free ? 'button door-control-close' : 'button primary';
                button.disabled = offline;
                state.className = 'status ' + (offline ? 'error' : allFree ? 'active' : free ? 'pending' : 'pending');
                state.textContent = offline ? 'Catraca offline' :
                    allFree ? 'PASSAGEM LIVRE' : free ? 'Parcialmente liberada' : 'Controle normal';
                detail.textContent = 'Entrada: ' + (info.entry.free ? 'livre' : 'normal') +
                    ' · Saída: ' + (info.exit.known ? (info.exit.free ? 'livre' : 'normal') : 'não informada') +
                    ' · Origem: ' + selected.map(item => item.source).join(' / ');
            }
            door.addEventListener('change', render);
            direction.addEventListener('change', render);
            document.getElementById('door-control-form').addEventListener('submit', event => {
                if (button.disabled || !confirm(
                    (action.value === 'release' ? 'Ativar passagem livre' : 'Encerrar passagem livre') +
                    ' na catraca ' + data[door.value].name + ' (' +
                    direction.options[direction.selectedIndex].text + ')?'
                )) event.preventDefault();
                else {
                    button.disabled = true;
                    button.textContent = 'Enviando...';
                }
            });
            render();
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
