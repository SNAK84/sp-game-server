#!/usr/bin/env python3
"""Текущий dev: задержка снимка блокировкой только строки QA-флота.

Не останавливает MySQL и не меняет игровые данные прямым SQL. Проверяет
rollback/повтор saver, непрерывность tick и запросов. Временные имена QA-планеты
и флота задаются обычными командами и восстанавливаются в finally. Строка флота
гарантированно dirty из-за переименования, в отличие от уже полных ресурсов.
"""
import argparse
import json
from pathlib import Path
import select
import subprocess
import time

from CapacitySafetyLiveTest import Client, rows


def sql(code, data):
    prelude = 'require "vendor/autoload.php"; SPGame\\Core\\Environment::init(".env"); '
    prelude += '$x=json_decode(stream_get_contents(STDIN),true); $db=SPGame\\Core\\Database::independent(); '
    result = subprocess.run(['docker', 'exec', '-i', 'swoole_app', 'php', '-r', prelude + code],
                            input=json.dumps(data), text=True, capture_output=True, check=True, timeout=10)
    return json.loads(result.stdout)


def stats():
    current = Path('/opt/sp-game/logs/game.log')
    # Частые большие DTO вытесняют статистику из хвоста128KiB. Читаем
    # ограниченно текущий и два последних архива, не всю историю логов.
    archives = sorted(current.parent.glob('game.log.[0-9]*'), reverse=True)[:2]
    for path in [current, *archives]:
        try:
            with path.open('rb') as log:
                log.seek(0, 2)
                log.seek(max(0, log.tell() - 2097152))
                lines = log.read().decode('utf-8', errors='replace').splitlines()
        except FileNotFoundError:
            continue  # Ротация между поиском и open.
        for line in reversed(lines):
            if 'Starting SP-Game WebSocket Server' in line:
                raise AssertionError('Нет статистики после последнего старта.')
            if '[INFO] Server statistics {' in line:
                try:
                    value = json.loads(line[line.index('{'):])
                except json.JSONDecodeError:
                    continue  # Незаконченная строка при параллельной записи.
                if 'storage' in value:
                    return value
    raise AssertionError('Нет свежей статистики нового saver.')


def read_name(planet_id):
    return sql('echo json_encode($db->fetchOne("SELECT name FROM planets WHERE id=?",[$x["id"]])["name"]);', {'id': planet_id})


def read_fleet_name(fleet_id):
    return sql('echo json_encode($db->fetchOne("SELECT name FROM fleets WHERE id=?",[$x["id"]])["name"]);', {'id': fleet_id})


def unlock(process):
    if process is None:
        return
    if process.poll() is None:
        process.stdin.write('release\n')
        process.stdin.flush()
    process.communicate(timeout=10)
    assert process.returncode == 0, 'Не завершилось собственное SQL-соединение проверки.'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', required=True, action='store_true')
    parser.parse_args()
    client = lock = None
    original = planet_id = None
    fleet_id = original_fleet = None
    try:
        client = Client('qaown_260924')
        planet_id = int(client.context['planetId'])
        original = next(p['name'] for p in client.planets['items'] if int(p['id']) == planet_id)
        fleet = next(f for f in rows(client.query('fleets.get')['FleetList']) if f['status'] == 0)
        fleet_id, original_fleet = fleet['id'], fleet['name']
        deadline = time.monotonic() + 40
        while read_name(planet_id) != original or read_fleet_name(fleet_id) != original_fleet:
            assert time.monotonic() < deadline, 'Исходное имя ещё не сохранено.'
            time.sleep(.5)
        before = stats()
        interval = before['storage']['storage.saveIntervalSeconds']
        assert interval == 30, 'Эта проверка рассчитана на dev-интервал 30с.'
        code = r'''
        require 'vendor/autoload.php'; SPGame\Core\Environment::init('.env');
        $id=(int)trim(fgets(STDIN)); $db=SPGame\Core\Database::independent();
        $owned=$db->fetchOne("SELECT f.id FROM fleets f JOIN users u ON u.id=f.owner_id JOIN accounts a ON a.id=u.account_id WHERE f.id=? AND a.login='qaown_260924'",[$id]);
        if($owned===null)throw new LogicException('Не QA-флот.');
        $db->beginTransaction();
        try {
            if($db->fetchOne('SELECT id FROM fleets WHERE id=? FOR UPDATE',[$id])===null)throw new LogicException('Нет QA-флота.');
            echo "LOCKED\n"; fflush(STDOUT); stream_set_timeout(STDIN,55); fgets(STDIN);
        } finally { $db->rollBack(); }
        '''
        lock = subprocess.Popen(['docker', 'exec', '-i', 'swoole_app', 'php', '-r', code],
                                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        lock.stdin.write(str(fleet_id) + '\n')
        lock.stdin.flush()
        assert select.select([lock.stdout], [], [], 10)[0], 'Не подтверждена QA-блокировка.'
        assert lock.stdout.readline().strip() == 'LOCKED', 'Не взята QA-блокировка.'
        # Сначала гарантируем dirty заблокированной строки. Иначе snapshot
        # между двумя командами мог бы штатно сохранить только имя планеты.
        client.command('fleet.rename', {'FleetId': fleet_id, 'Name': 'QA Saver Fleet'})
        client.command('planet.rename', {'name': 'QA Saver Snapshot'})
        latencies = []
        started = time.monotonic()
        deadline = started + 42
        while True:
            now = time.monotonic()
            client.query('overview.get')
            latencies.append(time.monotonic() - now)
            current = stats()
            if current['storage']['storage.lastErrorAt'] > before['storage']['storage.lastErrorAt']:
                break
            assert time.monotonic() < deadline, 'Не воспроизведён timeout фонового SQL.'
            time.sleep(.35)
        assert read_name(planet_id) == original, 'Частично закоммичен оборванный snapshot.'
        assert read_fleet_name(fleet_id) == original_fleet, 'Частично записан заблокированный флот.'
        assert current['storage']['storage.pendingRows'] > 0, 'Потерян dirty backlog.'
        assert current['storage']['world.lastPassAt'] > before['storage']['world.lastPassAt'] + 3, 'Tick остановился на SQL.'
        assert max(latencies) < 2, 'Запросы игры ждут фоновый SQL.'
        print('BACKGROUND_SQL_FAILURE_OK', json.dumps({'queries': len(latencies),
              'maxQueryMs': round(max(latencies) * 1000, 2), 'pendingRows': current['storage']['storage.pendingRows'],
              'retryableErrors': client.retryable_errors,
              'snapshotLockMisses': current['storage'].get('storage.snapshotLockMisses', 0)}), flush=True)
        unlock(lock)
        lock = None
        deadline = time.monotonic() + 30
        while read_name(planet_id) != 'QA Saver Snapshot' or read_fleet_name(fleet_id) != 'QA Saver Fleet':
            assert time.monotonic() < deadline, 'Снимок не повторён после снятия блокировки.'
            time.sleep(.5)
        print('BACKGROUND_SAVE_RETRY_OK', flush=True)
    finally:
        unlock(lock)
        if client is not None:
            try:
                if original is not None:
                    client.command('planet.rename', {'name': original})
                    if original_fleet is not None:
                        client.command('fleet.rename', {'FleetId': fleet_id, 'Name': original_fleet})
                    deadline = time.monotonic() + 40
                    while read_name(planet_id) != original or (original_fleet is not None and read_fleet_name(fleet_id) != original_fleet):
                        assert time.monotonic() < deadline, 'Не сохранено восстановленное QA-имя.'
                        time.sleep(.5)
            finally:
                client.close()
    print('BACKGROUND_SAVER_LIVE_OK qaNamesRestored=1', flush=True)


if __name__ == '__main__':
    main()
