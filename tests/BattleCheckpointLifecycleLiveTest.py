#!/usr/bin/env python3
"""Read-only наблюдение lifecycle probe на основном dev; запуск с временным onStart-hook.

python3 -u tests/BattleCheckpointLifecycleLiveTest.py --proof .secrets/<run>/lifecycle.json
Ничего не публикует/не перезапускает, SQL только SELECT одного служебного ключа.
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
        assert proof['id'] == 1900700001, 'Ожидался только служебный ключ probe.'
        stored = sql('echo json_encode($db->fetchOne("SELECT state FROM space_battles WHERE id=?",[$x["id"]]));', {'id': proof['id']})
        if stored is not None:
            assert json.loads(stored['state'])['id'] == proof['battleId'], 'SQL-ключ занят другой записью.'
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
            health = stats()['storage']
            assert health['world.authorityPid'] == health['ws.authorityPid'] == proof['pid']
            assert all(health[k] == 0 for k in ('storage.lastErrorAt', 'storage.runtimeSqlRejected',
                                              'storage.cleanupErrors', 'storage.snapshotBudgetHits'))
            print('LIVE_BATTLE_CHECKPOINT_LIFECYCLE_OK', json.dumps({'seconds': proof['elapsedSeconds'],
                  'checkpointCommitObserved': True, 'sqlDeleted': True, 'ramAndMarkerReleased': True,
                  'newWorld': False, 'observerSqlWrites': 0}), flush=True)
            return
        time.sleep(.5)
    raise AssertionError('Нет завершённого proof основного dev.')


if __name__ == '__main__':
    main()
