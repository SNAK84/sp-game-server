<?php

declare(strict_types=1);

// Локальная конфигурация межсервисного доступа, не содержит игровых паролей.
$directory=dirname(__DIR__).'/.secrets';
$path=$directory.'/cabinet-world.json';
umask(0077);
if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Не создан каталог секретов.');
if (!is_file($path)) {
    $data=['enabled'=>false,'apiUrl'=>'https://cabinet.sp-game.ru','worldId'=>'sp-game-dev',
        'name'=>'Космические пираты','websocketUrl'=>'wss://server.sp-game.ru','legacySource'=>'sp-game','serviceKey'=>bin2hex(random_bytes(32))];
    $file=fopen($path,'x');
    if ($file===false) throw new RuntimeException('Конфигурация уже создаётся другим процессом.');
    fwrite($file,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    fclose($file);
}
$data=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
if (in_array('--enable',$argv,true)) {
    $data['enabled']=true;
    $temporary=$path.'.'.bin2hex(random_bytes(6));
    file_put_contents($temporary,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
    if (!rename($temporary,$path)) throw new RuntimeException('Не активирована конфигурация.');
}
if (in_array('--export',$argv,true)) {
    if (stream_isatty(STDOUT)) throw new RuntimeException('Секреты экспортируются только в закрытый pipe.');
    echo json_encode($data,JSON_THROW_ON_ERROR);
} else echo json_encode(['worldId'=>$data['worldId'],'enabled'=>$data['enabled']],JSON_THROW_ON_ERROR).PHP_EOL;
