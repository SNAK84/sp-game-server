#!/usr/bin/env python3
"""B077: основной dev, обычный v2, только два QA и SELECT диагностика.

Отрицательные запросы к игроку B046 не исправляют его владельца и не создают
identity. При свободной паре QA проверяется обычная дружба request/accept/remove.
"""
import argparse
import json
import subprocess
import time

from BackgroundSaverLiveTest import stats
from CapacitySafetyLiveTest import Client, rows


def orphan_state():
    code = r'''
require 'vendor/autoload.php'; SPGame\Core\Environment::init('.env');
$db=SPGame\Core\Database::independent();
echo json_encode([
    'missingAccounts'=>$db->fetchAll('SELECT id FROM accounts WHERE id IN (0,9,2147483646)'),
    'phantomPlayers'=>$db->fetchAll('SELECT id FROM users WHERE account_id IN (0,-1,2147483646)'),
    'orphan'=>$db->fetchOne('SELECT id,account_id,name,main_planet,current_planet FROM users WHERE id=2'),
    'planet'=>$db->fetchOne('SELECT id,owner_id,fields,planet_type FROM planets WHERE id=12'),
    'orphanMessages'=>(int)$db->fetchOne('SELECT COUNT(*) n FROM messages WHERE to_id=2')['n'],
    'qaOrphanPair'=>$db->fetchAll("SELECT f.id,f.status FROM friends f JOIN users u ON u.id=f.user_high
        JOIN accounts a ON a.id=u.account_id WHERE f.user_low=2 AND a.login='qaown_260924'")
]);
'''
    return json.loads(subprocess.run(['docker', 'exec', '-i', 'swoole_app', 'php', '-r', code],
                                    check=True, text=True, capture_output=True, timeout=15).stdout)


def peer_row(client, user_id):
    return next((r for r in rows(client.query('friends.get')['Friends']) if str(r['userId']) == str(user_id)), None)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true', required=True)
    parser.parse_args()
    before = orphan_state()
    assert not before['missingAccounts'] and not before['phantomPlayers']
    assert before['orphan']['account_id'] == 9 and before['planet']['owner_id'] == 2
    owner = peer = None
    fixture_id = None
    checks = 0
    positive = False
    try:
        owner, peer = Client('qaown_260924'), Client('qafor_260924')
        owner_page, peer_page = owner.query('friends.get'), peer.query('friends.get')
        actor_id, peer_id = owner_page['userId'], peer_page['userId']
        for target in ('2', '2147483646'):
            owner.command('friend.request', {'id': target, 'text': 'B077 unavailable identity'}, 'rejected')
            assert owner.query('friends.get') == owner_page
            assert peer.query('friends.get') == peer_page
            checks += 3
        # Не меняем уже существующую дружбу/заявку QA; свободную пару возвращаем в none.
        if peer_row(owner, peer_id) is None and peer_row(peer, actor_id) is None:
            owner.command('friend.request', {'id': str(peer_id), 'text': 'B077 normal QA friendship'})
            outgoing, incoming = peer_row(owner, peer_id), peer_row(peer, actor_id)
            fixture_id = outgoing['id']
            assert not outgoing['accepted'] and not outgoing['incoming']
            assert not incoming['accepted'] and incoming['incoming']
            peer.command('friend.accept', {'id': str(fixture_id)})
            assert peer_row(owner, peer_id)['accepted'] and peer_row(peer, actor_id)['accepted']
            owner.command('friend.remove', {'id': str(fixture_id)})
            fixture_id = None
            assert peer_row(owner, peer_id) is None and peer_row(peer, actor_id) is None
            checks += 4
            positive = True
    finally:
        try:
            if fixture_id is not None and owner is not None:
                owner.command('friend.remove', {'id': str(fixture_id)})
        finally:
            if peer is not None:
                peer.close()
            if owner is not None:
                owner.close()
    completed_at = time.time()
    deadline = time.monotonic() + 100
    while stats()['storage']['storage.lastSavedAt'] <= completed_at:
        if time.monotonic() > deadline:
            raise TimeoutError('Нет нового штатного снимка после проверки.')
        time.sleep(1)
    assert orphan_state() == before, 'Изменены identity/планета/уведомления/связь исходного B046.'
    checks += 1
    print('LIVE_ACCOUNT_INTEGRITY_OK', json.dumps({'checks': checks, 'qaFriendshipRoundTrip': positive,
                                                 'orphanUnchanged': True, 'noPhantomsAfterSnapshot': True}))


if __name__ == '__main__':
    main()
