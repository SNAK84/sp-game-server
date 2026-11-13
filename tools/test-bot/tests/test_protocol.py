import unittest
import asyncio
import json
import tempfile
from pathlib import Path
from unittest.mock import AsyncMock, Mock

from app.ws_client import GameClient, GameSessionEndedError, make_message
from app.logging_utils import redact


class ProtocolTest(unittest.TestCase):
    def test_internal_widget_message_shape(self) -> None:
        message = make_message(
            "buildings",
            "build",
            {"id": 6},
            request_id="test-request",
            token="secret-token",
        )

        self.assertEqual(
            message,
            {
                "status": "ok",
                "requestId": "test-request",
                "token": "secret-token",
                "mode": "buildings",
                "action": "build",
                "data": {"id": 6},
            },
        )

    def test_empty_data_is_omitted_like_spa(self) -> None:
        message = make_message("overview", "ActualData", request_id="test-request")
        self.assertNotIn("data", message)


class PushProtocolTest(unittest.IsolatedAsyncioTestCase):
    async def test_cabinet_login_and_saved_grant_never_send_password_to_websocket(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'grant.json'
            first = GameClient('ws://unused.example', session_file=path)
            first.ws = object()
            first.request = AsyncMock(return_value={'status': 'ok'})
            first._authenticate_v2 = AsyncMock()
            first._cabinet_request = Mock(side_effect=[
                {'accessToken': 'a' * 64}, {'worldId': first.world_id, 'gameToken': 'b' * 128},
            ])
            await first.login('qa@example.invalid', 'private-password')
            first.request.assert_awaited_once_with('overview', 'reconnect')
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            second = GameClient('ws://unused.example', session_file=path)
            second.ws = object()
            second.request = AsyncMock(return_value={'status': 'ok'})
            second._authenticate_v2 = AsyncMock()
            second._cabinet_request = Mock(side_effect=AssertionError('Unexpected new cabinet login'))
            await second.login('qa@example.invalid', 'private-password')
            self.assertEqual(second.token, first.token)
            second._cabinet_request.assert_not_called()

    async def test_replaced_session_stops_pending_and_future_requests(self):
        class Socket:
            def __aiter__(self):
                async def messages():
                    yield json.dumps({'v':2,'worldId':client.world_id,'type':'session','name':'session.ended','data':{'state':'ended'}})
                return messages()
        client = GameClient('ws://unused.example')
        client.ws = Socket()
        pending = asyncio.get_running_loop().create_future()
        client._pending['test-request'] = pending
        await client._receive_loop()
        with self.assertRaises(GameSessionEndedError):
            await pending
        with self.assertRaises(GameSessionEndedError):
            await client.request('overview', 'ActualData')
        self.assertTrue(client.session_ended)
        self.assertIsNone(client.token)

    async def test_all_cabinet_credentials_are_redacted(self):
        self.assertEqual(redact({'accessToken': 'a', 'refreshToken': 'b', 'gameToken': 'c'}),
                         {'accessToken': '<redacted>', 'refreshToken': '<redacted>', 'gameToken': '<redacted>'})

    async def test_real_transport_uses_only_v2_and_no_password_or_mode_action(self):
        client=GameClient('ws://unused.example')
        client._exchange=AsyncMock(return_value={'v':2,'type':'queryResult','worldId':client.world_id,'data':{}})
        await client._v2_call('query',name='buildings.get',context={'planetId':'1'})
        packet=client._exchange.call_args.args[0]
        self.assertEqual(packet['v'],2)
        self.assertEqual(packet['name'],'buildings.get')
        self.assertFalse(set(packet)&{'mode','action','token','password'})

    async def test_wait_for_push_respects_checkpoint_and_predicate(self) -> None:
        client = GameClient("ws://unused.example")
        checkpoint = client.push_checkpoint()
        await client._record_unsolicited({"status": "ok", "mode": "overview"})
        await client._record_unsolicited({"status": "ok", "mode": "buildings"})

        sequence, message = await client.wait_for_unsolicited(
            lambda response: response.get("mode") == "buildings",
            after=checkpoint,
            timeout=0.1,
        )

        self.assertEqual(sequence, checkpoint + 2)
        self.assertEqual(message["mode"], "buildings")


if __name__ == "__main__":
    unittest.main()
