<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Game\Services\Lang;

$lang = Lang::load('ru');
foreach ([1, 108, 134, 135, 202, 221, 401] as $id) {
    $full = $lang['longDescription'][$id] ?? '';
    $short = $lang['shortDescription'][$id] ?? '';
    if (!is_string($full) || mb_strlen($full) < 200 || mb_strlen($full) <= mb_strlen($short)) {
        throw new RuntimeException("Сервер должен отдавать полное описание объекта {$id}.");
    }
}
if (($lang['tech'][134] ?? '') !== 'Флотовая логистика') {
    throw new RuntimeException('Новая технология должна получать название с сервера.');
}
echo "OK information full descriptions and research names are supplied by server Lang\n";
