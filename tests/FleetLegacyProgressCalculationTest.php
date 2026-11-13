<?php
declare(strict_types=1);

/** Только чистый расчёт на массивах: не создаёт игровой мир, SQL или Swoole Table. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Game\Services\FleetControlService as Control;

$reference = require __DIR__ . '/FleetLegacyProgressReference.php';
$method = new ReflectionMethod(Control::class, 'legacyProgress');
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('B074: ' . $label);
    ++$checks;
};
$row = static fn(int $id, int $type, array $params, float $end = 0): array => [
    'id' => $id, 'fleet_id' => 37, 'command_type' => $type, 'execute_time' => $end,
    'executed' => 1, 'params' => json_encode($params, JSON_THROW_ON_ERROR), 'create_time' => 1.0,
];
$check = static function (array $rows, string $label) use ($reference, $method, $assert): array {
    $before = hash('sha256', serialize($rows));
    $actual = $method->invoke(null, $rows);
    $assert($actual === $reference($rows), $label . ': точные значения и порядок maps');
    $assert(hash('sha256', serialize($rows)) === $before, $label . ': input read-only');
    return $actual;
};
$a = ['type'=>2,'galaxy'=>1,'system'=>6,'distance'=>2000,'angle'=>85];
$b = $a; $b['angle'] = 95;
$move = static fn(int $id, array $target, array $extra = [], float $start = 1, float $end = 9): array =>
    $row($id, 10, $extra + ['cancelled'=>true,'startedAt'=>$start,'target'=>$target], $end);
$hold = static fn(int $id, float $time): array => $row($id, 43, ['hold'=>['startedAt'=>$time]]);
$next = static fn(int $id, array $target, array $extra = []): array => $row($id, 10, $extra + ['target'=>$target]);

$check([], 'empty');
$check([$next(1, $a), $hold(2, 5), $next(3, $a)], 'modern');
$last = $check([$move(1,$a),$hold(2,5),$hold(3,7),$next(4,$a)], 'последний hold уточняет ту же MOVE');
$assert($last === [[4=>.25],[1=>.75]], 'не первое, а последнее уточнение');
$check([$move(1,$a),$hold(2,5),$move(3,$a,[],5,9),$hold(4,7),$next(5,$a)], 'цепочка наследуемых весов');
$assert($check([$move(1,$a),$move(2,$b),$hold(3,5),$next(4,$a)], 'ближайший source с иной целью') === [[],[]], 'не искать более старый source');
$assert($check([$move(1,$a),$hold(2,5),$next(3,$b),$next(4,$a)], 'первый следующий MOVE с иной целью') === [[],[]], 'не искать более поздний next');
$check([$move(1,$a),$hold(2,1),$hold(3,9),$next(4,$a)], 'включённые границы интервала');
$check([$move(1,$a),$hold(2,8),$hold(3,2),$next(4,$a)], 'немонотонные hold clocks');
$check([$move(1,$a),$hold(2,0),$hold(3,-1),$hold(4,5)], 'нет следующего MOVE');
$check([$move(1,$a,['progressCompleted'=>0]),$move(2,$a,['progressCompleted'=>null]),$hold(3,5),$next(4,$a)], 'isset metadata');
$check([$move(1,$a),$hold(2,5),$next(3,$a,['progressWeight'=>.8])], 'явный вес next не переписывается');
$check([$move(1,$a,[],1.000000000000001,1.000000000000003),$hold(2,1.000000000000002),$next(3,$a)], 'точные float coordinates');
$ordered = array_reverse($a, true);
$check([$move(1,$ordered),$hold(2,5),$next(3,$a)], 'strict target identity/order');

mt_srand(74013);
for ($sample = 0; $sample < 2500; ++$sample) {
    $rows = []; $length = mt_rand(0, 90);
    for ($i = 0; $i < $length; ++$i) {
        $id = ($sample % 7 === 0 ? 9007199254740993 : 1) + $i;
        $kind = mt_rand(0, 9);
        if ($kind < 5) {
            $start = [-1.0,0.0,1.0,2.0,3.25,7.0][mt_rand(0,5)];
            $end = $start + [-1.0,0.0,.25,1.0,10.0][mt_rand(0,4)];
            $p = ['target'=>[$a,$b,$ordered,null][mt_rand(0,3)], 'startedAt'=>$start, 'cancelled'=>mt_rand(0,3)>0];
            foreach (['progressWeight','progressDone','progressSpan','progressCompleted'] as $key) {
                if (mt_rand(0,3) === 0) $p[$key] = [null,-.1,0,.25,1.0,2.0][mt_rand(0,5)];
            }
            $rows[] = $row($id, [10,11,12][mt_rand(0,2)], $p, $end);
        } elseif ($kind < 9) {
            $rows[] = $hold($id, [-1.0,0.0,.5,1.0,1.25,2.0,3.25,7.0,8.0,20.0][mt_rand(0,9)]);
        } else $rows[] = $row($id,32,[]);
    }
    $check($rows, 'seeded-'.$sample);
}
for ($sample=0;$sample<100;++$sample) {
    $rows=[];
    for ($i=0;$i<40;++$i) {
        $start=mt_rand(1,5000)/1000;
        $rows[]=$move($i+1,mt_rand(0,1)?$a:$b,[],$start,$start+mt_rand(1,6000)/1000);
    }
    $times=[];
    for ($i=0;$i<80;++$i) $times[]=.125+$i/10;
    shuffle($times);
    foreach ($times as $i=>$time) $rows[]=$hold(41+$i,$time);
    $rows[]=$next(121,$a);
    $check($rows,'unique-time-coordinates-'.$sample);
}

$measure = static function (callable $operation): array {
    memory_reset_peak_usage(); $before = memory_get_usage(); $start = hrtime(true);
    $value = $operation();
    return [$value, (hrtime(true)-$start)/1e6, max(0,memory_get_peak_usage()-$before)];
};
$samples = [];
foreach ([[256,false],[1024,false],[2048,false],[1024,true]] as [$n,$distinct]) {
    $rows = [];
    for ($i=0;$i<$n;++$i) $rows[] = $move($i+1,$a,[],1,9);
    for ($i=0;$i<$n;++$i) $rows[] = $hold($n+$i+1,$distinct ? .125+20*$i/$n : [5.0,20.0,7.0,1.0,9.0][$i%5]);
    $rows[] = $next(2*$n+1,$a);
    [$expected,$oldMs,$oldPeak] = $measure(static function () use ($reference,$rows,&$visits): array { return $reference($rows,$visits); });
    [$actual,$newMs,$newPeak] = $measure(static fn()=>$method->invoke(null,$rows));
    $assert($actual === $expected, 'benchmark-equivalence-'.$n);
    $samples[] = ['rows'=>count($rows),'distinctTimes'=>$distinct,'oldVisits'=>$visits,'oldMs'=>round($oldMs,3),
        'newMs'=>round($newMs,3),'oldPeakBytes'=>$oldPeak,'newPeakBytes'=>$newPeak];
}
echo 'FLEET_LEGACY_PROGRESS_CALCULATION_OK ' . json_encode(['checks'=>$checks,'samples'=>$samples],JSON_THROW_ON_ERROR) . PHP_EOL;
