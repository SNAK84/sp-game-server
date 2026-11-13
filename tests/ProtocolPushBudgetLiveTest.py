#!/usr/bin/env python3
"""Основной dev/v2: две настоящие сессии, 16 подписок, справедливость push.

--expect-single требует временный WS_PUSH_MAX_PER_PASS=1 и перезапуск основного
сервера перед тестом. Без флага проверяется штатный лимит64. Не создаёт миров,
не меняет Swoole/SQL напрямую. Только QA-имена планет, восстановление в finally.
Транспортные обрывы/timeout команд не повторяются автоматически.
"""
import argparse
import json
import time
import uuid

from websocket import WebSocketTimeoutException

from CapacitySafetyLiveTest import Client
from BackgroundSaverLiveTest import stats
from RamRuntimeLiveTest import wait_for


class PushClient(Client):
    retry_seconds = 3

    def __init__(self, login):
        self.events = []
        super().__init__(login)

    def receive(self):
        value = json.loads(self.ws.recv())
        if value.get('type') == 'resource':
            self.events.append((time.monotonic(), value))
        return value

    def call(self, kind, **fields):
        rid = str(uuid.uuid4())
        packet = json.dumps({'v': 2, 'type': kind, 'requestId': rid, 'worldId': self.world, **fields})
        self.ws.send(packet)
        deadline = time.monotonic() + self.retry_seconds
        while True:
            value = self.receive()
            if value.get('requestId') != rid:
                continue
            if kind in ('query', 'command', 'subscribe') and value.get('type') == 'error' \
                    and value.get('error', {}).get('code') == 'service.unavailable' \
                    and value['error'].get('retryable') is True and time.monotonic() < deadline:
                self.retryable_errors += 1
                time.sleep(.1)
                self.ws.send(packet)
                continue
            return value

    def drain(self):
        # websocket-client может держать уже считанный следующий frame в буфере:
        # короткий recv с timeout надёжнее проверки только select(socket).
        self.ws.settimeout(.02)
        try:
            for _ in range(200):
                try:
                    self.receive()
                except (TimeoutError, WebSocketTimeoutException):
                    break
        finally:
            self.ws.settimeout(20)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', required=True, action='store_true')
    parser.add_argument('--expect-single', action='store_true')
    parser.add_argument('--expect-budget-ms', type=int, default=10)
    args = parser.parse_args()
    clients = []
    originals = []
    subscriptions = []
    checks = 0
    latencies = []
    spans = []

    def check(value, label):
        nonlocal checks
        assert value, label
        checks += 1

    def subscribe(client, sid, name='player.planets.get'):
        value = client.call('subscribe', subscriptionId=sid, name=name, context=client.context, data={})
        check(value['type'] == 'subscribed' and value['subscriptionId'] == sid, 'Не создана подписка.')
        return value

    def settle(names, offsets):
        deadline = time.monotonic() + 6
        while True:
            complete = True
            for i, client in enumerate(clients):
                client.drain()
                seen = {}
                for at, value in client.events[offsets[i]:]:
                    check(value['subscriptionId'] in subscriptions[i], 'Push после unsubscribe или от старого соединения.')
                    check(value['name'] == 'player.planets.get', 'Неверный ресурс после замены подписки.')
                    planet = next(p for p in value['data']['value']['items'] if p['id'] == client.context['planetId'])
                    if planet['name'] == names[i]:
                        seen[value['subscriptionId']] = at
                if set(seen) != set(subscriptions[i]):
                    complete = False
            started = time.monotonic()
            check(clients[0].call('ping')['type'] == 'pong', 'Ping не обслуживается между push.')
            latencies.append(time.monotonic() - started)
            if complete:
                break
            check(time.monotonic() < deadline, 'Голодание конца очереди подписок.')
        for i, client in enumerate(clients):
            at = [t for t, v in client.events[offsets[i]:] if v['subscriptionId'] in subscriptions[i]
                  and next(p for p in v['data']['value']['items'] if p['id'] == client.context['planetId'])['name'] == names[i]]
            spans.append(max(at) - min(at))
        check(all(len(c.events) > offsets[i] for i, c in enumerate(clients)), 'Не было настоящих push.')

    try:
        before = stats()['storage']
        check(before['protocol.pushMaxPerPass'] == (1 if args.expect_single else 64), 'Неверный тестовый/штатный лимит.')
        check(before['protocol.pushBudgetMs'] == args.expect_budget_ms, 'Неверный бюджет времени.')
        for login in ('qaown_260924', 'qafor_260924'):
            client = PushClient(login)
            clients.append(client)
            originals.append(next(p['name'] for p in client.planets['items'] if p['id'] == client.context['planetId']))
            ids = [str(uuid.uuid4()) for _ in range(8)]
            subscriptions.append(ids)
            for sid in ids:
                subscribe(client, sid)
            rejected = client.call('subscribe', subscriptionId=str(uuid.uuid4()), name='player.planets.get', context=client.context, data={})
            check(rejected['type'] == 'error' and rejected['error']['code'] == 'subscription.limit', 'Обойдён лимит8.')

        for i, client in enumerate(clients):
            # Ошибка замены не должна уничтожить уже действующую подписку.
            rejected = client.call('subscribe', subscriptionId=subscriptions[i][0], name='player.planets.get',
                                   context=clients[1 - i].context, data={})
            check(rejected['type'] == 'error' and rejected['error']['code'] == 'resource.unavailable', 'Подписка на чужую базу не отклонена.')

        # Два оборота, между ними мир снова помечает онлайн-аккаунты dirty.
        for turn in range(2):
            offsets = [len(c.events) for c in clients]
            names = ['QA Push %s %s' % (i, turn) for i in range(2)]
            for i, client in enumerate(clients):
                client.command('planet.rename', {'name': names[i]})
            settle(names, offsets)

        for i, client in enumerate(clients):
            sid = subscriptions[i][-1]
            value = client.call('unsubscribe', subscriptionId=sid)
            check(value['type'] == 'unsubscribed', 'Не удалена dirty-подписка.')
            subscriptions[i].remove(sid)
            # Тот же слот/id можно использовать для другого ресурса и вернуть.
            subscribe(client, sid, 'player.status.get')
            subscribe(client, sid)
            subscriptions[i].append(sid)
            gone = subscriptions[i].pop()
            check(client.call('unsubscribe', subscriptionId=gone)['type'] == 'unsubscribed', 'Не удалена заменённая подписка.')

        offsets = [len(c.events) for c in clients]
        names = ['QA Push %s final' % i for i in range(2)]
        for i, client in enumerate(clients):
            client.command('planet.rename', {'name': names[i]})
        settle(names, offsets)
        if args.expect_single:
            check(max(spans) >= .15, 'Не наблюдалось разделения обновлений на разные проходы.')
        # Закрытие и новый grant/nonce не наследуют старые ID подписок.
        clients[0].close()
        clients[0] = PushClient('qaown_260924')
        subscriptions[0] = [str(uuid.uuid4())]
        subscribe(clients[0], subscriptions[0][0])
        offsets = [len(c.events) for c in clients]
        names = ['QA Push reconnect', 'QA Push sibling']
        for i, client in enumerate(clients):
            client.command('planet.rename', {'name': names[i]})
        settle(names, offsets)
        check(max(latencies, default=0) < 2, 'Ping блокируется массовой отправкой.')
        finished = time.time()
        observed = wait_for(lambda: (s if (s := stats()['storage'])['world.lastPassAt'] >= finished else None), 10)
        if args.expect_single or args.expect_budget_ms == 1:
            check(observed['protocol.pushBudgetHits'] > before['protocol.pushBudgetHits'], 'Не достигнут принудительный бюджет.')
        check(observed['protocol.pushOldestSeconds'] < 6, 'Неограниченный возраст очереди.')
        check(observed['storage.runtimeSqlRejected'] == 0, 'Появился SQL в authority.')
        print('PROTOCOL_PUSH_BUDGET_LIVE_OK', json.dumps({'checks': checks, 'maxPerPass': observed['protocol.pushMaxPerPass'],
              'budgetHits': observed['protocol.pushBudgetHits'] - before['protocol.pushBudgetHits'],
              'pushSent': observed['protocol.pushSent'] - before['protocol.pushSent'],
              'maxBatchSpanMs': round(max(spans) * 1000, 2),
              'maxPingMs': round(max(latencies, default=0) * 1000, 2)}), flush=True)
    finally:
        failures = []
        for i, client in enumerate(clients):
            try:
                if i < len(originals):
                    client.command('planet.rename', {'name': originals[i]})
                    assert next(p['name'] for p in client.query('player.planets.get')['items'] if p['id'] == client.context['planetId']) == originals[i]
            except Exception as error:
                failures.append(str(error))
            finally:
                client.close()
        assert not failures, 'Не восстановлены QA-имена: ' + '; '.join(failures)


if __name__ == '__main__':
    main()
