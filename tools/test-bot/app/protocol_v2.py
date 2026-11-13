"""Пилот v2: общий transport и кабинет с v1, отдельный ресурсный контракт."""
from __future__ import annotations

import re
import time
import uuid
from typing import Any

from .ws_client import GameClient, GameClientError, GameSessionEndedError


class ProtocolV2Client(GameClient):
    authenticated = False
    capabilities: dict[str, Any] | None = None
    server_epoch: str | None = None
    protocol_connection: str | None = None
    identity: dict[str, Any] | None = None
    last_revision = 0

    async def connect(self) -> None:
        if self.session_ended:
            raise GameSessionEndedError('Игровая сессия завершена; нужен новый запуск бота.')
        if self.ws is not None:
            return
        self.protocol_connection = self.server_epoch = None
        self.last_revision = 0
        self.resource_revisions = {}
        self.authenticated = False
        await self._open_transport()
        response = await self._call('hello', data={'versions': [2]})
        if response.get('data', {}).get('version') != 2:
            raise GameClientError('Сервер не согласовал v2')
        self.protocol_connection = response['connectionId']
        self.server_epoch = response['serverEpoch']
        self.capabilities = response['data']['capabilities']
        if self.token:
            await self._authenticate()

    async def _call(self, kind: str, **fields: Any) -> dict[str, Any]:
        response = await self._v2_call(kind, **fields)
        expected = {'hello': ['hello'], 'auth': ['authResult'], 'query': ['queryResult'], 'ping': ['pong'],
                    'logout': ['session'], 'command': ['commandAccepted', 'commandResult'],
                    'subscribe':['subscribed'],'unsubscribe':['unsubscribed']}[kind]
        if response.get('type') not in expected:
            raise GameClientError('Неожиданный тип ответа v2')
        return response

    async def _authenticate(self) -> dict[str, Any]:
        response = await self._call('auth', data={'gameToken': self.token})
        self.identity = response['data']
        if self.identity.get('state') != 'authenticated':
            raise GameClientError('Не подтверждена авторизация v2')
        self.authenticated = True
        return response

    async def login(self, login: str, password: str) -> dict[str, Any]:
        if self.authenticated:
            # Повторный login не должен создать новый grant при уже авторизованном WS.
            raise GameClientError('auth.already_authenticated')
        await self.acquire_game_grant(login, password)
        if self.ws is None:
            await self.connect()
        response = await self._authenticate() if not self.authenticated else {'data': self.identity}
        self.authenticated_login = login
        await self._log('login_ok', protocol=2)
        return response

    async def request(self, *args, **kwargs):
        raise GameClientError('v2 не использует mode/action; используйте query/read_planets')

    async def query(self, name: str, context: dict[str, Any] | None = None, data: dict[str, Any] | None = None) -> dict[str, Any]:
        if not self.authenticated:
            raise GameClientError('Сначала авторизуйте соединение v2')
        if name not in (self.capabilities or {}).get('queries', []):
            raise GameClientError('Сервер не объявил этот запрос')
        return await self._call('query', name=name, context=context or {}, data=data or {})

    def prepare_command(self, name: str, context: dict[str, Any], data: dict[str, Any]) -> dict[str, Any]:
        if not self.authenticated or name not in (self.capabilities or {}).get('commands', []):
            raise GameClientError('Команда не объявлена авторизованным сервером')
        return {'requestId': str(uuid.uuid4()), 'name': name, 'context': dict(context), 'data': dict(data),
                'serverEpoch': self.server_epoch, 'issuedAt': time.time()+self._clock_offset}

    async def submit_command(self, command: dict[str, Any]) -> dict[str, Any]:
        if not self.authenticated:
            raise GameClientError('Сначала авторизуйте соединение v2')
        if command.get('serverEpoch') != self.server_epoch:
            raise GameClientError('command.stale_epoch')
        return await self._call('command', **command)

    async def command_status(self, command_id: str) -> dict[str, Any]:
        response = await self.query('command.status.get', data={'commandId': command_id})
        return response['data']

    async def read_planets(self, planet_id: str | None = None) -> dict[str, Any]:
        response = await self.query('player.planets.get', {} if planet_id is None else {'planetId': planet_id})
        snapshot = response['data']
        if snapshot.get('full') is not True or snapshot.get('resource') != 'player.planets' or not re.fullmatch(r'[1-9][0-9]*', snapshot.get('revision', '')):
            raise GameClientError('Некорректный снимок v2')
        revision = int(snapshot['revision'])
        key = str(sorted(snapshot['context'].items()))
        if revision < self.resource_revisions.get(key, 0):
            raise GameClientError('Устаревшая revision снимка v2')
        self.last_revision = revision
        self.resource_revisions[key] = revision
        return snapshot

    async def ping(self):
        return await self._call('ping')

    async def logout(self):
        return await self._call('logout')

    async def close(self) -> None:
        self.authenticated = False
        await super().close()
