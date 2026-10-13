<?php
declare(strict_types=1);

/**
 * Interpretacao dos estados da OpenAPI: 0 = permanece aberta, 1 = fechada
 * (acesso com credencial), 2 = abertura temporaria, 3 = permanece fechada,
 * 4 = dispositivo offline. Em catracas, doorOutState corresponde a saida.
 */
function door_control_status(array $door, int $direction, ?array $stored = null, ?int $now = null): array
{
    if ($direction !== 0 && $direction !== 1) throw new InvalidArgumentException('Sentido invalido.');
    $key = $direction === 0 ? 'doorState' : 'doorOutState';
    $hasLive = isset($door[$key]) && is_numeric($door[$key]) && in_array((int)$door[$key], [0, 1, 2, 3, 4], true);
    $live = $hasLive ? (int)$door[$key] : null;
    // UNIX_TIMESTAMP() evita divergencia entre timezone do MySQL e do PHP.
    $changedAt = isset($stored['last_action_ts']) ? (int)$stored['last_action_ts']
        : (isset($stored['last_action_at']) ? (strtotime((string)$stored['last_action_at']) ?: 0) : 0);
    $age = ($now ?? time()) - $changedAt;
    $recentStored = $stored !== null && $changedAt > 0 && $age >= 0 && $age < 20;
    $free = $recentStored ? (bool)$stored['free_passage']
        : ($hasLive && $live !== 4 ? $live === 0 : (bool)($stored['free_passage'] ?? false));

    return [
        'free' => $free,
        'offline' => $live === 4 || ($direction === 1 && isset($door['doorState']) && (int)$door['doorState'] === 4),
        'source' => $recentStored ? 'comando recente' : ($hasLive ? 'HikCentral' : ($stored !== null ? 'ultimo comando' : 'indefinido')),
        'known' => $hasLive || $stored !== null,
    ];
}

/** HikCentral pode devolver data=[{...}] ou data=[[{...}]] e por vezes omiti-lo. */
function door_control_check_result(array $response, string $doorId): void
{
    $data = $response['data'] ?? null;
    if ($data === null || $data === '' || $data === []) return;
    $queue = [$data];
    $found = false;
    while ($queue) {
        $item = array_pop($queue);
        if (!is_array($item)) continue;
        if (array_key_exists('controlResultCode', $item)) {
            if ((string)($item['doorIndexCode'] ?? '') !== $doorId) continue;
            $found = true;
            if ((string)$item['controlResultCode'] !== '0') {
                throw new RuntimeException('HikCentral recusou o comando: ' . (string)($item['controlResultDesc'] ?? $item['controlResultCode']));
            }
        } else {
            foreach ($item as $child) if (is_array($child)) $queue[] = $child;
        }
    }
    if (!$found) throw new RuntimeException('HikCentral nao confirmou o resultado individual da catraca.');
}

function door_control_directions(string $value): array
{
    return match ($value) {
        '0' => [0],
        '1' => [1],
        'both' => [0, 1],
        default => throw new InvalidArgumentException('Selecione o sentido de passagem.'),
    };
}
