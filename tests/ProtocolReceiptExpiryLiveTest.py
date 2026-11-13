#!/usr/bin/env python3
"""Основной dev: истечение внешнего payload, без restart и повторения действия.

Две заведомо недопустимые заявки самому QA, по500 символов, образуют настоящий
внешний payload. SIGSTOP authority на125с даёт истечь штатному TTL120с.
Затем lookup/повтор исходного пакета должны сохранить unknown в той же epoch.
Никаких SQL-записей, отправки заявок другим игрокам или ускорения таймеров.
"""
import argparse
import json
import math
import os
import signal
import time
import uuid

from CapacitySafetyLiveTest import Client
from BackgroundSaverLiveTest import sql, stats
from RamQueueRestartLiveTest import authority_pid
from RamRuntimeLiveTest import wait_for
from ProtocolReceiptsLiveTest import send, status


def state(ids, planet):
    return sql('''echo json_encode([
      'receipts'=>$db->fetchAll('SELECT request_id,state,outcome FROM protocol_commands WHERE player_id=(SELECT owner_id FROM planets WHERE id=?) AND request_id IN (?,?)',[$x['planet'],...$x['ids']]),
      'pending'=>(int)$db->fetchOne('SELECT COUNT(*) n FROM player_queue_restart')['n'],
      'friends'=>(int)$db->fetchOne('SELECT COUNT(*) n FROM friends')['n']]);''', {'ids': ids, 'planet': planet})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.parse_args()
    assert stats()['authorized'] == 0, 'Нужен свободный dev: authority будет приостановлена на125с.'
    c = Client('qaown_260924')
    paused = False
    checks = 0
    try:
        planet = int(c.context['planetId'])
        player = sql('echo json_encode($db->fetchOne("SELECT owner_id FROM planets WHERE id=?",[$x["planet"]])["owner_id"]);', {'planet': planet})
        packets = [{'v': 2, 'type': 'command', 'worldId': c.world, 'requestId': str(uuid.uuid4()),
                    'name': 'friend.request', 'context': c.context, 'data': {'id': str(player), 'text': 'x' * 500},
                    'serverEpoch': c.epoch, 'issuedAt': time.time()} for _ in range(2)]
        ids = [p['requestId'] for p in packets]
        before = state(ids, planet)
        pid = authority_pid()
        last = stats()['storage']['world.lastPassAt']
        time.sleep(max(0, last + math.ceil(time.time() - last) + .15 - time.time()))
        for p in packets:
            r = send(c, p)
            assert r.get('type') == 'commandAccepted' and r['data']['state'] == 'accepted'; checks += 1
        os.kill(pid, signal.SIGSTOP); paused = True
        print('RECEIPT_PAYLOAD_WAIT_REAL_TTL_125_SECONDS', flush=True)
        started = time.monotonic()
        while time.monotonic() - started < 125:
            time.sleep(min(5, 125 - (time.monotonic() - started)))
        saved = state(ids, planet)
        assert len(saved['receipts']) == 2 and all(r['state'] == 'accepted' for r in saved['receipts']); checks += 1
        os.kill(pid, signal.SIGCONT); paused = False
        resumed = time.time()
        wait_for(lambda: stats()['storage']['world.lastPassAt'] > resumed, 15)
        # Удаление просроченного буфера выполняется не реже раза в5с.
        time.sleep(6)
        a = status(c, ids[0])
        assert a['state'] == 'unknown' and a['outcome']['reason'] == 'execution_unconfirmed'; checks += 1
        b = send(c, packets[1])
        assert b['type'] == 'commandResult' and b['data']['state'] == 'unknown'; checks += 1
        assert b['serverEpoch'] == packets[1]['serverEpoch']; checks += 1

        def saved_unknown():
            s = state(ids, planet)
            return s if len(s['receipts']) == 2 and s['pending'] == 0 and all(
                r['state'] == 'unknown' and json.loads(r['outcome'])['reason'] == 'execution_unconfirmed' for r in s['receipts']) else None

        saved = wait_for(saved_unknown, 45)
        assert saved['friends'] == before['friends']; checks += 1
        assert send(c, packets[0])['data']['state'] == 'unknown'; checks += 1
        assert stats()['storage']['storage.runtimeSqlRejected'] == 0; checks += 1
    finally:
        if paused: os.kill(pid, signal.SIGCONT)
        c.close()
    print('PROTOCOL_RECEIPT_EXPIRY_LIVE_OK', json.dumps({'checks': checks, 'sameEpoch': True, 'noFriendCreated': True}), flush=True)


if __name__ == '__main__':
    main()
