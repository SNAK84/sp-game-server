#!/usr/bin/env python3
"""B076: подготовленные основным bootstrap QA-доклады, обычный v2 и restart.

Не создаёт мир/таблицы, не пишет SQL. После теста штатный bootstrap cleanup
удаляет только ID из fixture; произвольные доклады/игроки не затрагиваются.
"""
import argparse
import json
from pathlib import Path
import time

from BackgroundSaverLiveTest import sql, stats
from CapacitySafetyLiveTest import Client, grant
from PlanetClockLiveTest import manage, running


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.add_argument('--fixture', type=Path, required=True)
    args = parser.parse_args()
    fixture = json.loads(args.fixture.read_text())
    assert fixture['marker'] == 'B076'
    grants = {9: grant('qaown_260924'), 10: grant('qafor_260924')}
    clients = {}
    checks = 0

    def check(value, message):
        nonlocal checks
        assert value, message
        checks += 1

    def connect_clients():
        for owner, login in [(9, 'qaown_260924'), (10, 'qafor_260924')]:
            clients[owner] = Client(login, world_grant=grants[owner])

    def verify_pages():
        for owner, pages in fixture['pages'].items():
            for page, expected in pages.items():
                actual = clients[int(owner)].query('combat.reports.get', {'page': int(page)})
                # Действующий v2 ResourceService удаляет верхний legacy page;
                # положение страницы задаётся запросом. Контракт не меняем.
                expected = {key: value for key, value in expected.items() if key != 'page'}
                check(actual == expected, f'Не совпала личная страница {owner}/{page}.')

    try:
        connect_clients()
        verify_pages()
        c = clients[9]
        target = fixture['ids'][150]
        original = c.query('combat.reports.get', {'page': 1})
        check(not c.query('combat.report.get', {'id': target})['savedByMe'], 'Чужая отметка стала личной.')
        check(clients[10].query('combat.report.get', {'id': target})['savedByMe'], 'Нет исходной чужой отметки.')
        c.command('combat.report.save', {'id': target})
        saved = c.query('combat.reports.get', {'page': 1})
        check(saved['reports'][0]['id'] == target, 'Новая закладка не первая.')
        check(c.query('combat.report.get', {'id': target})['savedByMe'], 'Не сохранена личная отметка.')
        c.command('combat.report.save', {'id': target})
        check(c.query('combat.reports.get', {'page': 1}) == saved, 'Повтор save меняет страницу.')
        c.command('combat.report.unsave', {'id': target})
        c.command('combat.report.unsave', {'id': target})
        check(c.query('combat.reports.get', {'page': 1}) == original, 'Unsave не вернул исходную страницу.')
        check(not c.query('combat.report.get', {'id': target})['savedByMe'], 'Не снята личная отметка.')
        check(clients[10].query('combat.report.get', {'id': target})['savedByMe'], 'Unsave снял чужую отметку.')
        verify_pages()

        # Независимое QA-чтение persistence, не SQL из игрового runtime.
        code = r'''
        $marks=implode(',',array_fill(0,count($x['ids']),'?'));
        echo json_encode([
            'reports'=>(int)$db->fetchOne('SELECT COUNT(*) n FROM battle_reports WHERE id IN ('.$marks.')',$x['ids'])['n'],
            'saved'=>$db->fetchAll('SELECT user_id,COUNT(*) n FROM battle_report_saves WHERE report_id IN ('.$marks.') GROUP BY user_id ORDER BY user_id',$x['ids']),
            'counter'=>(int)$db->fetchOne('SELECT SUM(saved_count) n FROM battle_reports WHERE id IN ('.$marks.')',$x['ids'])['n']]);
        '''
        deadline = time.monotonic() + 50
        while True:
            persisted = sql(code, {'ids': fixture['ids']})
            if persisted == {'reports': 192, 'saved': [{'user_id': 9, 'n': 70}, {'user_id': 10, 'n': 192}], 'counter': 262}:
                break
            assert time.monotonic() < deadline, ('Не сохранён общий периодический снимок.', persisted)
            time.sleep(.5)
        checks += 1
        print('BOOKMARKS_PERIODIC_SNAPSHOT_OK', flush=True)
        for client in clients.values():
            client.close()
        clients.clear()
        manage('stop')
        manage('start')
        connect_clients()
        verify_pages()
        check(not clients[9].query('combat.report.get', {'id': target})['savedByMe'], 'После restart вернулась снятая отметка.')
        check(clients[10].query('combat.report.get', {'id': target})['savedByMe'], 'После restart потеряна чужая отметка.')
        g = stats()['storage']
        check(g['storage.runtimeSqlRejected'] == g['storage.lastErrorAt'] == 0, 'Ошибка RAM-runtime/saver.')
        print('BATTLE_REPORT_BOOKMARKS_LIVE_OK', json.dumps({
            'checks': checks, 'nativeChecks': fixture['checks'],
            'oldGlobalSaveReads': fixture['oldSaveReads'], 'newGlobalSaveReads': fixture['newSaveReads'],
            'pageReportReads': fixture['newReportReads'], 'restart': True,
        }), flush=True)
    finally:
        if not running():
            manage('start')
        for client in clients.values():
            client.close()


if __name__ == '__main__':
    main()
