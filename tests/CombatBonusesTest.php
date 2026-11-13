<?php

declare(strict_types=1);

// Реальные InformationPage / Factors / боевой адаптер, in-memory repositories, без MySQL.
require __DIR__ . '/InformationPageTest.php';

use SPGame\Game\Repositories\{Factors, Vars};
use SPGame\Game\Services\{FleetAttackService, FleetCombatService, FleetExperienceService};

$account = informationFixture();
foreach ([109 => ['military_tech','Attack',5], 110 => ['defence_tech','Defensive',7], 111 => ['shield_tech','Shield',3]] as $id=>$definition) {
    [$name,$key,$level] = $definition;
    Vars::$resource[$id] = $name;
    Vars::$reslist['tech'][] = $id;
    Vars::$reslist['bonus'][] = $id;
    Vars::$attributes[$id] = ['factor'=>2,'max'=>100];
    Vars::$pricelist[$id] = [901=>100,902=>100];
    Vars::$bonus[$id] = array_fill_keys(Vars::$bonusList,[0,0]);
    Vars::$bonus[$id][$key] = [0.1,0];
    $account['Techs'][$name] = $level;
}
$before = [$account['Techs'],$account['Builds'],$account['Ships'],$account['Defenses'],$account['Resources']];
$groupMethod = new ReflectionMethod(FleetAttackService::class,'group');
foreach ([202=>'fleet',401=>'defense'] as $id=>$kind) {
    $info = informationSnapshot($id,$account);
    $group = $groupMethod->invoke(null,$id,10,$kind,$account,1,0.0);
    foreach (['attack'=>['Attack','attack',1.5], 'shield'=>['Shield','shield',1.3], 'structure'=>['Defensive','hull',1.7]] as $stat=>$mapping) {
        [$key,$field,$factor] = $mapping;
        $expected = $info['stats'][$stat]['base'] * $factor;
        transportAssert(abs(($info['stats'][$stat]['current'] ?? -1)-$expected)<1e-8,'Инфо не применило бонус '.$stat);
        transportAssert(abs($group[$field]-$expected/($stat==='structure'?10:1))<1e-8,'Бой и Инфо расходятся: '.$stat);
        transportAssert(abs(Factors::getFactor($key,$account)-($factor-1))<1e-8,'Factors изменил контракт прибавки.');
    }
}
echo "OK combat bonuses: ships and defense use the same Factors in Info and battle\n";

foreach ([109=>'Attack',110=>'Defensive',111=>'Shield'] as $id=>$key) {
    $info = informationSnapshot($id,$account);
    transportAssert($info['rows'][0]['level']===$account['Techs'][Vars::$resource[$id]],'Таблица технологии начинается не с текущего уровня.');
    transportAssert(abs(($info['rows'][0]['effects']['bonus:'.$key] ?? -1)-$info['current']*10)<1e-8,
        'Нет корректной таблицы боевого бонуса по уровням.');
}
echo "OK combat technology Info shows configured bonus per level\n";

Vars::$bonus[113]['Attack'] = [0.04,0]; // Ещё один настроенный источник: уровень 3 даёт +12%.
$info = informationSnapshot(202,$account);
$experience = 100.0;
$bonus = FleetExperienceService::bonuses($experience/10);
$group = $groupMethod->invoke(null,202,10,'fleet',$account,1,$experience);
transportAssert(abs($info['stats']['attack']['current']-5*1.62)<1e-8,'Дополнительные источники не складываются.');
transportAssert(abs($group['attack']-5*(1.62+$bonus['attack']))<1e-8,'Опыт применён повторно или умножен на уже усиленную атаку.');
$state = FleetCombatService::startBattle(['a'=>$group],['d'=>$groupMethod->invoke(null,401,2,'defense',$account,0,0.0)]);
transportAssert($state['a']['a']['attack']===$group['attack'] && $state['a']['a']['shield']===$group['shield'],
    'Боевой калькулятор повторно начисляет бонусы адаптера.');
echo "OK extra bonuses add up; XP applies once and does not enter generic Info\n";

Vars::$bonus[109]['Attack'] = [0.2,0];
$info = informationSnapshot(202,$account);
$group = $groupMethod->invoke(null,202,10,'fleet',$account,1,0.0);
transportAssert(abs($info['stats']['attack']['current']-5*2.12)<1e-8 && abs($group['attack']-5*2.12)<1e-8,
    'Настроенный коэффициент подменяется хардкодом или технология учитывается дважды.');
foreach ([109=>'military_tech',110=>'defence_tech',111=>'shield_tech',113=>'energy_tech'] as $id=>$name) $account['Techs'][$name]=0;
$zero = informationSnapshot(202,$account);
foreach (['structure','attack','shield'] as $key) transportAssert($zero['stats'][$key]['base']===$zero['stats'][$key]['current'],'Нулевые технологии дают бонус.');
echo "OK configured coefficient is authoritative; zero levels preserve base stats\n";

// Изменялись только уровни тестового сценария, просмотр не меняет игровые блоки.
transportAssert(array_slice($before,1)===[$account['Builds'],$account['Ships'],$account['Defenses'],$account['Resources']],
    'Просмотр Инфо или подготовка характеристик меняет gameplay state.');
