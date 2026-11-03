"""Расчётная проверка предложения опыта, не тест боевого production pipeline.

Из MySQL читаются только характеристики Vars; игровые данные не меняются.
"""
import json
import math
import random
import subprocess
from dataclasses import dataclass, replace

php = r"""<?php
require 'tests/bootstrap.php';
SPGame\Core\Environment::init();
echo json_encode(SPGame\Core\Database::getInstance()->fetchAll(
    'SELECT elementID AS id, cost901 + cost902 AS value, attack, defend AS shield FROM vars WHERE class IN (200,400)'
));
"""
units = {int(row['id']): row for row in json.loads(subprocess.check_output(
    ['docker', 'exec', '-i', '-w', '/var/www/html', 'swoole_app', 'php'], input=php, text=True
))}


@dataclass(frozen=True)
class Group:
    unit: int
    n: int
    xp: float = 0.0  # Средний опыт; в production хранится сумма n * xp.
    eligible: bool = True

    @property
    def cost(self):
        return float(units[self.unit]['value'])

    @property
    def value(self):
        return self.n * self.cost


def skill(xp):
    xp = min(1000.0, max(0.0, xp))
    return 1.1 * xp / (100.0 + xp)


def stats(group, tech=0):
    data = units[group.unit]
    b = skill(group.xp) if group.eligible else 0.0
    k = 1.0 + tech * .1
    attack = float(data['attack']) if group.unit not in (407, 408, 409) else 0.0
    return (group.n * attack * (k + .10 * b),
            group.value / 10 * (k + .10 * b),
            group.n * float(data['shield']) * (k + .05 * b))


def power(groups):
    totals = [sum(v) for v in zip(*(stats(g) for g in groups))]
    return math.sqrt(totals[0] * (totals[1] + totals[2])) if totals else 0.0


def fleet_xp(groups):
    value = sum(g.value for g in groups)
    return sum(g.value * g.xp for g in groups) / value if value else 0.0


def award(own, enemy, destroyed, survivors, shares=None):
    value = sum(g.value for g in own)
    enemy_value = sum(g.value for g in enemy)
    assert 0 <= destroyed <= enemy_value + 1e-6
    assert len(own) == len(survivors)
    own_power = power(own)
    risk = min(1.0, power(enemy) / own_power) ** 2 if own_power > 0 else 0.0
    budget = 20.0 * min(destroyed, value) * risk
    if shares is None:
        attacks = [stats(g)[0] for g in own]
        total_attack = sum(attacks)
        shares = [a / total_attack if total_attack else 0.0 for a in attacks]
    assert sum(shares) <= 1 + 1e-9 and min(shares, default=0) >= 0
    result = []
    for g, n, share in zip(own, survivors, shares):
        assert 0 <= n <= g.n and g.n > 0 and g.cost > 0 and 0 <= g.xp <= 1000
        weight = .8 * share + .2 * g.value / value
        gain = min(20.0, budget * weight / g.value) if g.eligible else 0.0
        result.append((n * min(1000, g.xp + gain), gain))
    earned_value = sum(g.cost * (total - n * g.xp)
                       for g, n, (total, _) in zip(own, survivors, result))
    assert earned_value <= budget + 1e-5
    return result, budget


checks = 0


def check(condition, label):
    global checks
    assert condition, label
    checks += 1
    print('OK', label)


check(skill(0) == 0 and math.isclose(skill(1000), 1), 'нулевой опыт и верхняя граница')
check(all(skill(e) < skill(e + 1) for e in range(1000)), 'монотонность бонуса')
check(all(skill(e + 1) - skill(e) > skill(e + 2) - skill(e + 1)
          for e in range(999)), 'убывающая отдача от опыта')
rookie = Group(204, 100)
veteran = replace(rookie, xp=1000)
for tech in (0, 10, 20):
    a, h, s = stats(rookie, tech)
    va, vh, vs = stats(veteran, tech)
    check(all(math.isclose(v, expected) for v, expected in zip(
        (va/a, vh/h, vs/s), (1 + .1/(1+.1*tech), 1 + .1/(1+.1*tech), 1 + .05/(1+.1*tech))
    )), 'ограниченный бонус при технологиях ' + str(tech))

same = [Group(204, 100)]
value = same[0].value
check(award(same, same, value, [100])[0][0] == (2000.0, 20.0), 'не более 20 опыта за бой')
check(award(same, same, 0, [100])[0][0] == (0.0, 0.0), 'нет опыта без реальных потерь противника')
check(award(same, [Group(210, 400)], 400000, [100])[0][0] == (0.0, 0.0), 'уничтожение безоружных зондов не даёт опыта')
check(award(same, same, value, [0])[0][0][0] == 0.0, 'уничтоженные корабли не оставляют опыт')
before = [Group(204, 100, 100)]
partial = award(before, same, value*.5, [40])[0][0]
full = award(before, same, value*.5, [100])[0][0]
check(math.isclose(partial[0]/40, full[0]/100), 'потери не концентрируют опыт у выживших')
check(math.isclose(award([veteran], same, value, [100])[0][0][0], 100000), 'предел 1000 опыта на корабль')
split = [Group(204, 40, 100), Group(204, 60, 100)]
check(math.isclose(sum(x[0] for x in award(split, same, value*.5, [40,60])[0]), full[0]),
      'разделение одинаковых групп не умножает награду')
check(math.isclose(sum(g.n*g.xp for g in split), before[0].n*before[0].xp), 'сохранение опыта при переносе между флотами')
check(math.isclose((40*100+60*0)/100, 40), 'новые корабли разбавляют средний опыт')
check(math.isclose(100*100-20*100, 80*100), 'перенос на планету уничтожает только опыт перенесённой части')
check(fleet_xp([Group(204, 0)]) == 0, 'пустой флот не делит на ноль')
mixed = [Group(214, 1), Group(210, 1000, 1000)]
check(math.isclose(fleet_xp(mixed), 100), 'зонды не перевешивают дорогой корабль по одному количеству')
with_planet = [Group(204, 10), Group(204, 90, eligible=False)]
planet_result = award(with_planet, same, value*.5, [10,90])[0]
check(math.isclose(planet_result[0][1], 10),
      'доля планетарных кораблей не перераспределяется во флот')
check(planet_result[1] == (0.0, 0.0), 'корабли вне флота не получают опыт')
death_result = award([Group(204,50), Group(204,50)], same, value*.5, [50,0])[0]
check(death_result == [(500.0,10.0),(0.0,10.0)], 'доля погибшей группы не передаётся выжившей')

rng = random.Random(20261016)
for _ in range(5000):
    own = [Group(rng.choice([202,204,207,210,214]), rng.randint(1,1000), rng.uniform(0,1000))
           for _ in range(rng.randint(1,6))]
    enemy = [Group(rng.choice([202,204,207,210,214]), rng.randint(1,1000))
             for _ in range(rng.randint(1,6))]
    survivors = [rng.randint(0,g.n) for g in own]
    out, _ = award(own, enemy, rng.random()*sum(g.value for g in enemy), survivors)
    assert all(0 <= gain <= 20 and 0 <= total <= 1000*n+1e-6
               for n,(total,gain) in zip(survivors,out))
check(True, '5000 смешанных составов: конечность, лимиты, бюджет и потери')

print('\nТаблица: опыт | атака/корпус, % базы | щит, % базы')
for e in (0,25,75,150,300,600,1000):
    print(e, round(10*skill(e),4), round(5*skill(e),4))
print('\nПримеры награды на выживший лёгкий истребитель, без технологий/опыта:')
for ratio in (1,2,3,10):
    own=[Group(204,100*ratio)]
    gain=award(own,[Group(204,100)],400000,[own[0].n])[0][0][1]
    print('Превосходство',ratio,'к 1:',round(gain,6))
for unit in (202,210):
    enemy=[Group(unit,100)]
    gain=award(same,enemy,enemy[0].value,[100])[0][0][1]
    print('Уничтожение 100 кораблей типа',unit,':',round(gain,6))
print('\nПроверок:',checks)
