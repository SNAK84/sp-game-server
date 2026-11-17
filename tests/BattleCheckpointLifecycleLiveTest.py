#!/usr/bin/env python3
"""Read-only наблюдение lifecycle probe на основном dev; запуск с временным onStart-hook.

python3 -u tests/BattleCheckpointLifecycleLiveTest.py --proof .secrets/<run>/lifecycle.json
Ничего не публикует/не перезапускает, SQL только SELECT служебных ключей probe.
"""
import argparse
import json
from pathlib import Path
import time

from BackgroundSaverLiveTest import sql, stats


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--proof', required=True, type=Path)
    args = parser.parse_args()
    deadline = time.monotonic() + 180
    seen_sql = False
    phase = None
    proof = None
    while time.monotonic() < deadline:
        try:
            proof = json.loads(args.proof.read_text())
        except (FileNotFoundError, json.JSONDecodeError):
            time.sleep(.25)
            continue
        repository = proof.get('repository', 'SpaceBattles')
        table, expected_id = {'PlanetBattles': ('planet_battles', 1900700002),
                              'SpaceBattles': ('space_battles', 1900700001)}[repository]
        assert proof['id'] == expected_id, 'Ожидался только служебный ключ probe.'
        stored = sql('echo json_encode($db->fetchOne("SELECT state FROM ' + table + ' WHERE id=?",[$x["id"]]));', {'id': proof['id']})
        if stored is not None:
            assert json.loads(stored['state'])['id'] == proof['battleId'], 'SQL-ключ занят другой записью.'
            assert len(stored['state'].encode('utf-8')) == proof['checkpointBytes'], 'SQL-образ усечён или преждевременно заменён cleanup-marker.'
            assert 'cleanupRequested' not in json.loads(stored['state']), 'Компактный cleanup не должен сохраняться через UPDATE.'
            seen_sql = True
        if proof['phase'] != phase:
            phase = proof['phase']
            print(json.dumps({'phase': phase, 'seenSqlCommit': seen_sql, 'sqlPresent': stored is not None}), flush=True)
        assert phase != 'timeout', 'Штатный lifecycle не завершился за180с.'
        if phase == 'complete':
            assert seen_sql, 'Не наблюдался первый настоящий COMMIT checkpoint.'
            assert stored is None, 'После освобождения RAM в SQL остался checkpoint.'
            assert proof['sawCleanupRequested'] and proof['rowGone'] and proof['markerGone']
            assert proof['runtimeSqlWrites'] == 0
            if proof.get('lateDirty'):
                assert proof['dependencyDirtyAtCleanup'] and proof['dependencyDeleteRequested'] and proof['dependencyGone']
                dependency = sql('echo json_encode($db->fetchOne("SELECT id FROM fleet_commands WHERE id=?",[$x["id"]]));',
                                 {'id': proof['commandId']})
                assert dependency is None, 'Временная зависимая команда осталась в SQL.'
            if proof['atLimit']:
                assert proof['checkpointBytes'] == 262144 and proof['cleanupBytes'] < 128
            health = stats()['storage']
            assert health['world.authorityPid'] == proof['pid']
            # Gauge WS устанавливается только при onMessage. При отсутствии
            # клиентов 0 означает «не измерено», а не другой игровой worker.
            assert health['ws.authorityPid'] in (0, proof['pid'])
            assert all(health[k] == 0 for k in ('storage.lastErrorAt', 'storage.runtimeSqlRejected',
                                              'storage.cleanupErrors', 'storage.snapshotBudgetHits'))
            print('LIVE_BATTLE_CHECKPOINT_LIFECYCLE_OK', json.dumps({'repository': repository, 'seconds': proof['elapsedSeconds'],
                  'checkpointCommitObserved': True, 'sqlDeleted': True, 'ramAndMarkerReleased': True,
                  'checkpointBytes': proof['checkpointBytes'], 'cleanupBytes': proof['cleanupBytes'],
                  'lateDependencyDirty': proof.get('dependencyDirtyAtCleanup', False),
                  'wsAuthorityObserved': health['ws.authorityPid'] != 0,
                  'newWorld': False, 'observerSqlWrites': 0}), flush=True)
            return
        time.sleep(.5)
    raise AssertionError('Нет завершённого proof основного dev.')


if __name__ == '__main__':
    main()
