"""T005: реальные методы двух клиентов, без копий сервера/игрового мира."""
import copy
import unittest
from unittest.mock import AsyncMock, patch

from app.protocol_v2 import ProtocolV2Client
from app.ws_client import GameClient, GameClientError, GameSessionEndedError


class ProtocolRetryTest(unittest.IsolatedAsyncioTestCase):
    def client(self, client_class):
        client = client_class('ws://unused.example', world_id='test-world')
        client.protocol_connection, client.server_epoch = 'connection-1', 'epoch-1'
        return client

    def response(self, client, kind='error', **fields):
        return {'v': 2, 'worldId': client.world_id, 'connectionId': client.protocol_connection,
                'serverEpoch': client.server_epoch, 'type': kind, **fields}

    def busy(self, client, **flags):
        return self.response(client, error={'code': 'service.unavailable', 'retryable': True,
                                            'fatal': False, **flags})

    async def call(self, client, kind, **fields):
        method = client._call if isinstance(client, ProtocolV2Client) else client._v2_call
        return await method(kind, **fields)

    async def test_retry_preserves_full_query_or_command_for_both_clients(self):
        for client_class in (GameClient, ProtocolV2Client):
            for kind, result in [('query', 'queryResult'), ('command', 'commandAccepted')]:
                with self.subTest(client=client_class.__name__, kind=kind):
                    client = self.client(client_class)
                    client._exchange = AsyncMock(side_effect=[self.busy(client), self.busy(client),
                                                              self.response(client, result, data={'state': 'accepted'})])
                    fields = {'requestId': 'fixed-request', 'name': 'planet.rename', 'context': {'planetId': '7'},
                              'issuedAt': 123.5, 'serverEpoch': client.server_epoch, 'data': {'name': 'QA'}}
                    with patch('app.ws_client.asyncio.sleep', new_callable=AsyncMock) as sleep:
                        response = await self.call(client, kind, **fields)
                    self.assertEqual(response['type'], result)
                    self.assertEqual(client._exchange.await_count, 3)
                    packets = [call.args[0] for call in client._exchange.await_args_list]
                    self.assertTrue(all(packet == packets[0] for packet in packets))
                    self.assertEqual(packets[0], {'v': 2, 'type': kind, 'worldId': client.world_id, **fields})
                    self.assertEqual([call.args[0] for call in sleep.await_args_list], [.05, .1])

    async def test_stops_after_six_attempts(self):
        for client_class in (GameClient, ProtocolV2Client):
            client = self.client(client_class)
            client._exchange = AsyncMock(return_value=self.busy(client))
            with patch('app.ws_client.asyncio.sleep', new_callable=AsyncMock) as sleep:
                with self.assertRaisesRegex(GameClientError, '^service.unavailable$'):
                    await self.call(client, 'query', name='buildings.get')
            self.assertEqual(client._exchange.await_count, 6)
            self.assertEqual(sleep.await_count, 5)

    async def test_retries_only_explicit_nonfatal_retryable_error(self):
        for client_class in (GameClient, ProtocolV2Client):
            for flags in [{'retryable': False}, {'retryable': 'true'}, {'fatal': True}, {'fatal': None},
                          {'code': 'resource.unavailable'}, {'code': 'command.stale_epoch'}]:
                with self.subTest(client=client_class.__name__, flags=flags):
                    client = self.client(client_class)
                    client._exchange = AsyncMock(return_value=self.busy(client, **flags))
                    with patch('app.ws_client.asyncio.sleep', new_callable=AsyncMock) as sleep:
                        with self.assertRaises(GameClientError):
                            await self.call(client, 'command')
                    self.assertEqual(client._exchange.await_count, 1)
                    sleep.assert_not_awaited()

    async def test_missing_flags_do_not_authorize_retry(self):
        for client_class in (GameClient, ProtocolV2Client):
            for field in ('retryable', 'fatal'):
                client = self.client(client_class)
                response = self.busy(client)
                del response['error'][field]
                client._exchange = AsyncMock(return_value=response)
                with self.assertRaises(GameClientError):
                    await self.call(client, 'query')
                self.assertEqual(client._exchange.await_count, 1)

    async def test_accepted_and_final_outcomes_never_resubmit(self):
        for client_class in (GameClient, ProtocolV2Client):
            for kind, state in [('commandAccepted', 'accepted'), ('commandResult', 'applied'),
                                ('commandResult', 'rejected'), ('commandResult', 'unknown')]:
                client = self.client(client_class)
                client._exchange = AsyncMock(return_value=self.response(client, kind, data={'state': state}))
                response = await self.call(client, 'command')
                self.assertEqual(response['data']['state'], state)
                self.assertEqual(client._exchange.await_count, 1)

    async def test_no_retry_after_timeout_session_end_or_transport_error(self):
        for client_class in (GameClient, ProtocolV2Client):
            for error in [GameClientError('request timeout'), GameSessionEndedError('ended'), OSError('lost ACK')]:
                client = self.client(client_class)
                client._exchange = AsyncMock(side_effect=error)
                with self.assertRaises(type(error)):
                    await self.call(client, 'command')
                self.assertEqual(client._exchange.await_count, 1)

    async def test_checks_identity_before_retrying(self):
        for client_class in (GameClient, ProtocolV2Client):
            for field, value in [('v', 1), ('worldId', 'other'), ('connectionId', 'other'), ('serverEpoch', 'other')]:
                client = self.client(client_class)
                client._exchange = AsyncMock(return_value={**self.busy(client), field: value})
                with self.assertRaises(GameClientError):
                    await self.call(client, 'query')
                self.assertEqual(client._exchange.await_count, 1)

    async def test_no_retry_across_reconnection(self):
        for client_class in (GameClient, ProtocolV2Client):
            client = self.client(client_class)
            client._exchange = AsyncMock(return_value=self.busy(client))
            async def reconnect(_delay):
                client.protocol_connection, client.server_epoch = 'new-connection', 'new-epoch'
            with patch('app.ws_client.asyncio.sleep', side_effect=reconnect):
                with self.assertRaises(GameClientError):
                    await self.call(client, 'command')
            self.assertEqual(client._exchange.await_count, 1)

    async def test_packet_is_frozen_even_if_caller_changes_source(self):
        for client_class in (GameClient, ProtocolV2Client):
            client = self.client(client_class)
            data = {'name': 'original'}
            sent = []
            async def exchange(packet):
                sent.append(copy.deepcopy(packet))
                if len(sent) == 1:
                    data['name'] = 'changed'
                    return self.busy(client)
                return self.response(client, 'commandResult', data={'state': 'applied'})
            client._exchange = exchange
            with patch('app.ws_client.asyncio.sleep', new_callable=AsyncMock):
                await self.call(client, 'command', data=data)
            self.assertEqual(sent[0], sent[1])
            self.assertEqual(sent[1]['data']['name'], 'original')

    async def test_auth_uses_same_bounded_policy_without_nested_retry(self):
        client = self.client(ProtocolV2Client)
        client._exchange = AsyncMock(side_effect=[self.busy(client),
            self.response(client, 'authResult', data={'state': 'authenticated'})])
        with patch('app.ws_client.asyncio.sleep', new_callable=AsyncMock):
            await client._authenticate()
        self.assertTrue(client.authenticated)
        self.assertEqual(client._exchange.await_count, 2)

    async def test_logout_ping_hello_are_not_implicitly_repeated(self):
        for client_class in (GameClient, ProtocolV2Client):
            for kind in ('logout', 'ping', 'hello'):
                client = self.client(client_class)
                client._exchange = AsyncMock(return_value=self.busy(client))
                with self.assertRaises(GameClientError):
                    await self.call(client, kind)
                self.assertEqual(client._exchange.await_count, 1)


if __name__ == '__main__':
    unittest.main()
