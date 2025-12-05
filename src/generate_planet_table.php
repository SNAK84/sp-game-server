<?php
// tools/generate_planet_table.php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SPGame\Game\Services\GalaxyGenerator;

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Получаем приватный static $starTypes из GalaxyGenerator через Reflection
$ref = new ReflectionClass(GalaxyGenerator::class);
$prop = $ref->getProperty('starTypes');
$prop->setAccessible(true);
$starTypes = $prop->getValue();

header('Content-Type: text/html; charset=utf-8');

$rows = [];
foreach ($starTypes as $starCode => $starInfo) {
    $maxOrbits = (int)($starInfo['max_orbits'] ?? 0);
    $minDistance = (int)($starInfo['range'][0] ?? 0);
    $maxDistance = (int)($starInfo['range'][1] ?? 0);

    if ($maxOrbits <= 0) continue;

    // Добавляем разделитель перед каждой новой звездой
    $rows[] = [
        'separator' => true,
        'star' => $starCode,
        'color' => $starInfo['color'] ?? '#ccc'
    ];

    // Распределяем орбиты
    if ($maxOrbits === 1) {
        $distances = [$minDistance];
    } else {
        $distances = [];
        $step = ($maxDistance - $minDistance) / max(1, $maxOrbits - 1);
        for ($i = 0; $i < $maxOrbits; $i++) {
            $distances[] = (int)round($minDistance + $step * $i);
        }
    }

    // Генерация планет
    foreach ($distances as $orbitIndex => $distance) {
        $orbitNum = $orbitIndex + 1;
        $planet = GalaxyGenerator::generatePlanet($starCode, $distance, false);

        $tempMin = $planet['temp_min'] ?? null;
        $tempMax = $planet['temp_max'] ?? null;
        $avgTemp = is_numeric($tempMin) && is_numeric($tempMax)
            ? round((($tempMin + $tempMax) / 2), 1)
            : null;

        $rows[] = [
            'separator' => false,
            'star' => $starCode,
            'orbit' => $orbitNum,
            'distance' => $distance,
            'planet_type' => $planet['type'] ?? '',
            'temp_min' => $tempMin,
            'temp_max' => $tempMax,
            'avg_temp' => $avgTemp,
            'size' => $planet['size'] ?? '',
            'fields' => $planet['fields'] ?? '',
            'gravity' => $planet['gravity'] ?? '',
            'atmosphere' => $planet['atmosphere'] ?? '',
            'habitability' => $planet['habitability'] ?? '',
            'image' => $planet['image'] ?? '',
        ];
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Генерация планет — таблица</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { font-family: Inter, Roboto, Arial, sans-serif; padding: 18px; background:#f7f8fb; color:#111; }
    h1 { margin: 0 0 12px 0; font-size: 20px; }
    table { border-collapse: collapse; width: 100%; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    th, td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: center; font-size: 13px; }
    th { background:#fafafa; position: sticky; top: 0; z-index: 2; }
    tr:hover td { background: #fcfdff; }
    .star-separator td {
        background: #eaeef9;
        color: #222;
        font-weight: bold;
        text-align: left;
        border-top: 2px solid #ccd3ea;
        border-bottom: 2px solid #ccd3ea;
        font-size: 14px;
        padding: 6px 12px;
    }
    .small { font-size:12px; color:#666; }
    .wrap { max-width: 100%; overflow-x: auto; padding-top: 10px; }
  </style>
</head>
<body>
  <h1>Генерация планет — таблица по типам звёзд</h1>
  <p class="small">Сгенерировано типов звёзд: <strong><?= count($starTypes) ?></strong>,
     строк (включая разделители): <strong><?= count($rows) ?></strong>.
  </p>

  <div class="wrap">
    <table>
      <thead>
        <tr>
          <th>Звезда</th>
          <th>Орбита</th>
          <th>Дистанция</th>
          <th>Тип планеты</th>
          <th>Темп мин (°C)</th>
          <th>Темп макс (°C)</th>
          <th>Сред (°C)</th>
          <th>Размер</th>
          <th>Поля</th>
          <th>Грав</th>
          <th>Атм</th>
          <th>Обитаемость</th>
          <th>Image</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php if (!empty($r['separator'])): ?>
          <tr class="star-separator">
            <td colspan="13">⭐ Тип звезды: <strong><?= htmlspecialchars($r['star']) ?></strong></td>
          </tr>
        <?php else: ?>
          <tr>
            <td><?= htmlspecialchars((string)$r['star']) ?></td>
            <td><?= htmlspecialchars((string)$r['orbit']) ?></td>
            <td><?= htmlspecialchars((string)$r['distance']) ?></td>
            <td><?= htmlspecialchars((string)$r['planet_type']) ?></td>
            <td><?= htmlspecialchars((string)$r['temp_min']) ?></td>
            <td><?= htmlspecialchars((string)$r['temp_max']) ?></td>
            <td><?= htmlspecialchars((string)$r['avg_temp']) ?></td>
            <td><?= htmlspecialchars((string)$r['size']) ?></td>
            <td><?= htmlspecialchars((string)$r['fields']) ?></td>
            <td><?= htmlspecialchars((string)$r['gravity']) ?></td>
            <td><?= htmlspecialchars((string)$r['atmosphere']) ?></td>
            <td><?= htmlspecialchars((string)$r['habitability']) ?></td>
            <td><?= htmlspecialchars((string)$r['image']) ?></td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="small" style="margin-top:12px">
    Примечание: генерация использует случайность — каждый запуск даст немного разные значения температур и размеров.
  </p>
</body>
</html>
