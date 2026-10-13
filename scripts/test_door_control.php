<?php
declare(strict_types=1);
require __DIR__ . '/../door_control_lib.php';
function ok(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$door = ['doorState'=>0,'doorOutState'=>1];
ok(door_control_status($door, 0)['free'], 'Entrada liberada');
ok(!door_control_status($door, 1)['free'], 'Saida normal');
ok(door_control_status(['doorState'=>4], 0)['offline'], 'Offline');
ok(door_control_status(['doorState'=>1], 0, ['free_passage'=>1,'last_action_at'=>date('Y-m-d H:i:s')])['free'], 'Comando recente');
ok(door_control_status(['doorState'=>1], 0, ['free_passage'=>1,'last_action_at'=>'2020-01-01 00:00:00'])['free'] === false, 'Consulta HikCentral prevalece');
ok(door_control_directions('both') === [0,1], 'Ambos sentidos');
ok(door_control_status(['doorState'=>1], 0, ['free_passage'=>1,'last_action_ts'=>time()])['free'], 'Timestamp UTC do banco');
door_control_check_result(['data'=>[[['doorIndexCode'=>'abc','controlResultCode'=>0]]]], 'abc');
try {
    door_control_check_result(['data'=>[['doorIndexCode'=>'abc','controlResultCode'=>15,'controlResultDesc'=>'Falhou']]], 'abc');
    throw new RuntimeException('Deveria falhar resultado individual');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'Falhou'), 'Resultado individual');
}
echo "Door control: OK\n";
