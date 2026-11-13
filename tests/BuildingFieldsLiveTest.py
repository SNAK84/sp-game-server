#!/usr/bin/env python3
"""B056/B058 на основном dev: лимит полей, очередь расширения и её отмена.

Только подготовленный qaown_260924; все действия через настоящий v2.
Не выдаёт ресурсы/уровни напрямую. В конце отменяет только собственную очередь.
После прогона оператор восстанавливает QA-fixture при остановленной игре.
"""
import argparse
import json
from pathlib import Path
import time

from CapacitySafetyLiveTest import Client, rows
from RamRuntimeLiveTest import wait_for


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.add_argument('--fixture', required=True)
    parser.add_argument('--expect-legacy', action='store_true')
    args = parser.parse_args()
    fixture = json.loads(Path(args.fixture).read_text())
    c = Client('qaown_260924')
    assert c.context['planetId'] == str(fixture['identity']['planet_id'])
    expected = fixture['fixture']
    checks = 0

    def page():
        return c.query('buildings.get')

    def queue():
        return sorted(rows(page()['QueueList']), key=lambda q: (q['end_time'], int(q['qid'])))

    def balances():
        return c.query('player.status.get')['topNav']['Resources']

    def cancel_all():
        # Сначала хвост: отмена головы не должна активировать оставшиеся тесты.
        for q in reversed(queue()):
            c.command('building.cancel', {'id': q['qid']})
        assert not queue()

    def check(condition, label):
        nonlocal checks
        assert condition, label
        checks += 1

    try:
        check(not queue(), 'Нужна пустая QA-очередь.')
        actual = c.query('overview.get')
        check(actual['field_used'] == expected['used'], 'Неверная подготовка занятых полей.')
        if args.expect_legacy:
            check(actual['field_current'] == expected['max'] + 5 * expected['moonFields'], 'Старый баг не воспроизведён.')
            c.command('building.upgrade', {'id': '1'})
            c.command('building.upgrade', {'id': '1'})
            check(len(queue()) == 2, 'Старый код не разрешил лишнее поле.')
            print('BUILD_FIELDS_LEGACY_REPRODUCED', json.dumps({'maxActual': actual['field_current'],
                  'maxCorrect': expected['max'], 'checks': checks}), flush=True)
            return

        check(actual['field_current'] == expected['max'], 'Терраформеру добавлены поля лунной базы.')
        check(page()['field_current'] == expected['max'], 'Разные поля в обзоре и пустой очереди.')
        c.command('building.upgrade', {'id': '1'})
        check(10 < queue()[0]['end_time'] - time.time() < 120, 'Нужен QA-таймер шахты 10–120с.')
        c.command('building.upgrade', {'id': '1'}, 'rejected')
        check(len(queue()) == 1, 'Принята постройка без свободного поля.')
        c.command('building.dismantle', {'id': '1'})
        c.command('building.upgrade', {'id': '1'})
        check(len(queue()) == 3, 'Демонтаж в очереди не освобождает поле в прогнозе.')
        check(c.query('overview.get')['field_current'] == expected['max'], 'Обычная очередь изменила фактическую вместимость.')
        cancel_all()
        check(page()['BuildList']['1']['level'] == 25, 'Отмена очереди изменила уровень шахты.')
        print('BUILD_FIELDS_BOUNDARY_AND_DESTROY_OK', flush=True)

        c.command('building.upgrade', {'id': '33'})
        started = queue()[0]
        check(20 < started['end_time'] - time.time() < 120, 'Нужен QA-таймер терраформера 20–120с.')
        for _ in range(expected['terraformerFields']):
            c.command('building.upgrade', {'id': '1'})
        c.command('building.upgrade', {'id': '1'}, 'rejected')
        planned = page()
        check(len(queue()) == 1 + expected['terraformerFields'], 'Неверная вместимость очереди расширения.')
        check(planned['field_current'] == expected['max'] + expected['terraformerFields'], 'Очередь терраформера учтена не один раз.')
        check(planned['field_used'] == planned['field_current'], 'Не заполнены все будущие поля.')
        check(c.query('overview.get')['field_current'] == expected['max'], 'Незавершённое расширение уже добавило реальные поля.')
        print('BUILD_FIELDS_EXPANSION_PROJECTED_ONCE_OK', flush=True)

        c.command('building.cancel', {'id': started['qid']})
        first = next(q for q in queue() if q['status'] == 'active')
        check(first['id'] == '1', 'После отмены не активирована допустимая шахта.')
        # Отмены возвращают лишь неизрасходованную долю цены. Поэтому сравниваем
        # баланс ПОСЛЕ отмены расширения и оплаты единственной допустимой шахты,
        # а не весь прогон с исходными80M. Склады QA выше лимита: дохода нет.
        paid = balances()
        check(all(paid[k]['count'] > paid[k]['max'] for k in ('901', '902', '903')), 'Для проверки списания нужны заполненные QA-склады.')
        # Одно реальное свободное поле ещё есть. После его заполнения остаток
        # зависимой очереди обязан удалиться ДО оплаты/строительства.
        wait_for(lambda: not queue(), max(20, first['end_time'] - time.time() + 12))
        final = page()
        check(final['BuildList']['1']['level'] == 26, 'Построена лишняя шахта после отмены расширения.')
        check(final['BuildList']['33']['level'] == 5, 'Отменённый терраформер изменил уровень.')
        check(final['field_used'] == final['field_current'] == expected['max'], 'Заполненная планета вышла за лимит.')
        after = balances()
        for k in ('901', '902', '903'):
            check(abs(after[k]['count'] - paid[k]['count']) < .0001, 'Списан ресурс за недопустимый хвост очереди: ' + k)
        c.command('building.upgrade', {'id': '1'}, 'rejected')
        print('BUILD_FIELDS_CANCEL_EXPANSION_ACTIVATION_OK', flush=True)

        c.command('building.dismantle', {'id': '33'})
        demolition = page()
        check(demolition['field_current'] == expected['max'] - expected['terraformerFields'], 'Демонтаж не уменьшает прогноз вместимости.')
        c.command('building.upgrade', {'id': '1'}, 'rejected')
        check(len(queue()) == 1, 'Новая постройка использует поля демонтируемого терраформера.')
        cancel_all()
        check(page()['field_current'] == expected['max'], 'Отмена демонтажа не вернула прогноз.')
        print('BUILD_FIELDS_LIVE_OK', json.dumps({'checks': checks, 'retryableErrors': c.retryable_errors}), flush=True)
    finally:
        try:
            cancel_all()
        finally:
            c.close()


if __name__ == '__main__':
    main()
