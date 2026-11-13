#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Запускать только после отдельного разрешения владельца и остановки main. */
if (!in_array('--execute', $argv, true)) {
    fwrite(STDERR, "Требуется явный --execute после согласования ротации.\n"); exit(64);
}
umask(0077);
function runPrivate(array $command, string $input = ''): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Не запущена операция ротации.');
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Операция ротации не подтверждена; секреты не выводятся.');
    return $output;
}
if (trim(runPrivate(['docker', 'inspect', 'swoole_app', '--format', '{{.State.Running}}'])) !== 'false') {
    throw new LogicException('Сначала штатно остановите основной игровой процесс.');
}
$path = dirname(__DIR__) . '/.secrets/cabinet-world.json';
$configuration = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
if (($configuration['worldId'] ?? '') !== 'sp-game-dev' || !preg_match('/^[a-f0-9]{64}$/D', $configuration['serviceKey'] ?? '')) {
    throw new LogicException('Не подтверждён точный мир ротации.');
}
$oldHash = hash('sha256', $configuration['serviceKey']);
$configuration['serviceKey'] = bin2hex(random_bytes(32));
$newHash = hash('sha256', $configuration['serviceKey']);
$temporary = $path . '.pending-' . bin2hex(random_bytes(8));
$bytes = json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Не подготовлен закрытый файл конфигурации.');
$update = <<<'PHP'
require 'src/bootstrap.php';
$x=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
if($x['worldId']!=='sp-game-dev')throw new LogicException('Другой мир.');
$db=(new SPGame\Cabinet\Core\Database(SPGame\Cabinet\Core\Config::fromEnvironment()))->connect();
$q=$db->prepare('UPDATE game_worlds SET service_key_hash=? WHERE id=? AND service_key_hash=?');
$q->execute([$x['after'],$x['worldId'],$x['before']]);
if($q->rowCount()!==1)throw new RuntimeException('CAS регистрации мира не прошёл.');
echo json_encode(['updated'=>true]);
PHP;
$command = ['docker', 'exec', '-i', 'sp_game_cabinet', 'php', '-r', $update];
runPrivate($command, json_encode(['worldId' => $configuration['worldId'], 'before' => $oldHash, 'after' => $newHash], JSON_THROW_ON_ERROR));
if (!rename($temporary, $path)) {
    runPrivate($command, json_encode(['worldId' => $configuration['worldId'], 'before' => $newHash, 'after' => $oldHash], JSON_THROW_ON_ERROR));
    throw new RuntimeException('Файл не опубликован; регистрация возвращена к прежнему состоянию.');
}
echo json_encode(['worldId' => $configuration['worldId'], 'rotated' => true,
    'configurationPermissions' => decoct(fileperms($path) & 0777)], JSON_THROW_ON_ERROR), PHP_EOL;
