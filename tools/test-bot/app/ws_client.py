from __future__ import annotations

import asyncio
import copy
import json
import os
import re
import time
import uuid
import urllib.error
import urllib.request
from pathlib import Path
from collections import deque
from collections.abc import Awaitable, Callable
from typing import Any

import websockets
from websockets.asyncio.client import ClientConnection
from websockets.exceptions import WebSocketException
from .operations import resolve, ids, CATALOG


LogCallback = Callable[[str, dict[str, Any]], Awaitable[None] | None]


class GameClientError(RuntimeError):
    pass


class GameAuthenticationError(GameClientError):
    pass


class GameSessionEndedError(GameAuthenticationError):
    """Отозванную сессию нельзя автоматически заменять новым входом."""


def make_message(
    mode: str,
    action: str = "",
    data: dict[str, Any] | None = None,
    request_id: str | None = None,
    token: str | None = None,
) -> dict[str, Any]:
    """Локальное представление старых сценариев; по сети оно не отправляется."""
    message: dict[str, Any] = {
        "status": "ok",
        "requestId": request_id or str(uuid.uuid4()),
    }
    if token:
        message["token"] = token
    if mode:
        message["mode"] = mode
    if action:
        message["action"] = action
    if data:
        message["data"] = data
    return message


class GameClient:
    def __init__(
        self,
        url: str,
        request_timeout: float = 15,
        log: LogCallback | None = None,
        *,
        cabinet_url: str | None = None,
        cabinet_email: str | None = None,
        world_id: str | None = None,
        session_file: Path | None = None,
    ) -> None:
        self.url = url
        self.request_timeout = request_timeout
        self.log = log
        self.ws: ClientConnection | None = None
        self.token: str | None = None
        self.cabinet_url = (cabinet_url or os.getenv("CABINET_API_URL", "https://cabinet.sp-game.ru")).rstrip("/")
        self.cabinet_email = cabinet_email or os.getenv("SP_GAME_CABINET_EMAIL", "")
        self.world_id = world_id or os.getenv("GAME_WORLD_ID", "sp-game-dev")
        self.session_file = session_file
        self.session_ended = False
        self.last_mode: str = "overview"
        self.selected_planet_id: int | None = None
        self.authenticated_login: str | None = None
        self.connection_id: str | None = None
        self.server_time: float | None = None
        self._receiver: asyncio.Task[None] | None = None
        self._pending: dict[str, asyncio.Future[dict[str, Any]]] = {}
        self._send_lock = asyncio.Lock()
        self._pushes: deque[tuple[int, dict[str, Any]]] = deque(maxlen=256)
        self._push_sequence = 0
        self._push_condition = asyncio.Condition()
        self.authenticated = False
        self.server_epoch = None
        self.protocol_connection = None
        self.capabilities = {}
        self.identity = None
        self._status = {}
        self._subscriptions = {}
        self._view_subscription = None
        self._status_revision = 0
        self._clock_offset = 0.0

    async def _log(self, event: str, **fields: Any) -> None:
        if self.log is None:
            return
        result = self.log(event, fields)
        if asyncio.iscoroutine(result):
            await result

    async def _open_transport(self) -> None:
        if self.session_ended:
            raise GameSessionEndedError("Игровая сессия завершена; нужен новый запуск бота.")
        if self.ws is not None:
            return
        await self._log("ws_connect_start", url=self.url)
        try:
            self.ws = await websockets.connect(
                self.url,
                open_timeout=self.request_timeout,
                close_timeout=3,
                max_size=8 * 1024 * 1024,
            )
        except (OSError, WebSocketException) as exc:
            raise GameClientError(f"websocket connect failed: {exc}") from exc
        self._receiver = asyncio.create_task(self._receive_loop())
        self.connection_id = uuid.uuid4().hex
        await self._log("ws_connected")

    async def connect(self) -> None:
        if self.session_ended:
            raise GameSessionEndedError("Игровая сессия завершена; нужен новый запуск бота.")
        if self.ws is not None:
            return
        await self._open_transport()
        hello = await self._v2_call('hello', data={'versions':[2]})
        self.protocol_connection = hello['connectionId']
        self.server_epoch = hello['serverEpoch']
        self.capabilities = hello['data']['capabilities']
        if self.token:
            await self._authenticate_v2()

    async def _v2_call(self, kind: str, **fields) -> dict[str, Any]:
        # Повторяем намерение, а не создаём новую команду: UUID/issuedAt/data неизменны.
        packet = copy.deepcopy({'v': 2, 'type': kind, 'requestId': str(uuid.uuid4()), 'worldId': self.world_id, **fields})
        connection, epoch = self.protocol_connection, self.server_epoch
        delays = (.05, .1, .2, .4, .8)
        for attempt in range(len(delays) + 1):
            if (self.protocol_connection, self.server_epoch) != (connection, epoch):
                raise GameClientError('Ответ другого соединения')
            # Transport timeout/потеря ACK не доказывают отказ исполнения: их не повторяем.
            response = await self._exchange(packet)
            if response.get('v') != 2 or response.get('worldId') != self.world_id:
                raise GameClientError('Неверная версия/мир ответа')
            if connection and (response.get('connectionId') != connection or response.get('serverEpoch') != epoch):
                raise GameClientError('Ответ другого соединения')
            error = response.get('error', {})
            if (response.get('type') == 'error' and kind in ('auth', 'query', 'command', 'subscribe', 'unsubscribe')
                    and error.get('code') == 'service.unavailable' and error.get('retryable') is True
                    and error.get('fatal') is False and attempt < len(delays)):
                await asyncio.sleep(delays[attempt])
                continue
            if response.get('type') == 'error':
                raise GameClientError(str(error.get('code', 'protocol.error')))
            # commandAccepted/commandResult возвращаем сразу, без новой отправки команды.
            return response

    async def _authenticate_v2(self):
        auth=await self._v2_call('auth',data={'gameToken':self.token})
        self.identity=auth['data'];self.authenticated=True
        subscription=str(uuid.uuid4());self._subscriptions[subscription]='status'
        status=await self._v2_call('subscribe',name='player.status.get',context={},data={},subscriptionId=subscription)
        self._apply_status(status['data'])

    def _apply_status(self,snapshot):
        if int(snapshot['revision'])<self._status_revision:return
        self._status_revision=int(snapshot['revision'])
        self._status=ids(snapshot['value'])
        self.selected_planet_id=self._status['planets']['current_planet']

    def _compat_response(self,mode,action,snapshot,request_id=None):
        return {'status':'ok','requestId':request_id or str(uuid.uuid4()),'mode':mode,'action':action,
                'serverTime':snapshot.get('observedAt',self.server_time),'data':{'Page':ids(snapshot.get('value',{})),
                    'TopNav':self._status.get('topNav'),'PlanetList':self._status.get('planets')}}

    async def _query_v2(self,name,data=None):
        return await self._v2_call('query',name=name,context={'planetId':str(self.selected_planet_id)},data=data or {})

    async def _command_v2(self,name,data,planet=None):
        request_id=str(uuid.uuid4());checkpoint=self.push_checkpoint()
        response=await self._v2_call('command',requestId=request_id,name=name,context={'planetId':str(planet or self.selected_planet_id)},
                                     data=data,serverEpoch=self.server_epoch,issuedAt=time.time()+self._clock_offset)
        status=response['data']
        if status['state']=='accepted':
            status=(await self._v2_call('query',name='command.status.get',data={'commandId':request_id}))['data']
            if status['state']=='accepted':
                _,result=await self.wait_for_unsolicited(lambda p:p.get('type')=='commandResult' and p.get('requestId')==request_id,
                                                       after=checkpoint,timeout=self.request_timeout)
                status=result['data']
        if status['state']!='applied':
            raise GameClientError(status.get('outcome',{}).get('error',{}).get('message','command.unknown_outcome'))

    async def acquire_game_grant(self, login: str, password: str) -> None:
        if not login or not password:
            raise GameClientError("SP_GAME_LOGIN/SP_GAME_PASSWORD are required")
        if self.session_ended:
            raise GameSessionEndedError("Игровая сессия завершена; нужен новый запуск бота.")
        if "@" not in login and os.getenv("SP_GAME_LOGIN") and login != os.environ["SP_GAME_LOGIN"]:
            raise GameAuthenticationError("Для другого аккаунта укажите email кабинета, не прежний игровой логин.")
        email = login if "@" in login else self.cabinet_email
        if not email:
            raise GameAuthenticationError("Задайте SP_GAME_CABINET_EMAIL для входа через кабинет.")
        if self.session_file and self.session_file.exists():
            try:
                saved = json.loads(self.session_file.read_text(encoding="utf-8"))
            except (OSError, ValueError) as exc:
                raise GameAuthenticationError("Не удалось прочитать сохранённую игровую сессию.") from exc
            if saved.get("login") != login or saved.get("worldId") != self.world_id:
                raise GameAuthenticationError("Сохранённая сессия относится к другому аккаунту/миру.")
            self.token = saved.get("token")
            if not isinstance(self.token, str) or not re.fullmatch(r"[a-f0-9]{128}", self.token):
                raise GameAuthenticationError("Некорректное игровое разрешение в хранилище бота.")
        else:
            auth = await asyncio.to_thread(self._cabinet_request, "/auth/login", {
                "email": email, "password": password, "client": "native",
            })
            grant = await asyncio.to_thread(self._cabinet_request, "/game/start", {
                "worldId": self.world_id,
            }, auth["accessToken"])
            token = grant.get("gameToken")
            if grant.get("worldId") != self.world_id or not isinstance(token, str) or not re.fullmatch(r"[a-f0-9]{128}", token):
                raise GameAuthenticationError("Кабинет вернул некорректное игровое разрешение.")
            self.token = token
            if self.session_file:
                try:
                    self.session_file.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                    temporary = self.session_file.with_suffix(".tmp")
                    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
                    with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
                        json.dump({"login": login, "worldId": self.world_id, "token": token}, stream)
                        stream.flush()
                        os.fsync(stream.fileno())
                    os.replace(temporary, self.session_file)
                    directory = os.open(self.session_file.parent, os.O_RDONLY | os.O_DIRECTORY)
                    try:
                        os.fsync(directory)
                    finally:
                        os.close(directory)
                except OSError as exc:
                    raise GameAuthenticationError("Не удалось сохранить игровое разрешение; автоматический повтор входа запрещён.") from exc

    async def login(self, login: str, password: str) -> dict[str, Any]:
        await self.acquire_game_grant(login, password)
        if self.ws is None:
            await self.connect()
        if not self.authenticated:
            await self._authenticate_v2()
        response = await self.request(
            "overview", "reconnect",
        )
        if response.get("status") != "ok":
            raise GameAuthenticationError(
                f"login failed: {response.get('error', {}).get('message', 'unknown error')}"
            )
        self.authenticated_login = login
        await self._log("login_ok", response=response)
        return response

    def _cabinet_request(self, path: str, body: dict[str, Any], access: str | None = None) -> dict[str, Any]:
        if not self.cabinet_url.startswith("https://"):
            raise GameAuthenticationError("Для API кабинета необходим HTTPS.")
        headers = {"Content-Type": "application/json", "Accept": "application/json"}
        if access:
            headers["Authorization"] = "Bearer " + access
        request = urllib.request.Request(self.cabinet_url + "/api/v1" + path,
                                         data=json.dumps(body).encode(), headers=headers, method="POST")
        try:
            # Не следуем redirect с credentials и не повторяем POST при неопределённом результате.
            class NoRedirect(urllib.request.HTTPRedirectHandler):
                def redirect_request(self, req, fp, code, msg, headers, newurl):
                    return None
            with urllib.request.build_opener(NoRedirect).open(request, timeout=self.request_timeout) as response:
                envelope = json.load(response)
            if envelope.get("status") != "ok" or not isinstance(envelope.get("data"), dict):
                raise GameAuthenticationError("Некорректный ответ кабинета.")
            return envelope["data"]
        except (urllib.error.URLError, OSError, ValueError) as exc:
            raise GameAuthenticationError("Не удалось войти через кабинет; автоматический повтор входа запрещён.") from exc

    async def request(
        self,
        mode: str,
        action: str = "",
        data: dict[str, Any] | None = None,
        *,
        include_token: bool = True,
        timeout: float | None = None,
    ) -> dict[str, Any]:
        if self.session_ended:
            raise GameSessionEndedError("Игровая сессия завершена; нужен новый запуск бота.")
        if self.ws is None:
            raise GameClientError("WebSocket is not connected")

        if not self.authenticated:
            raise GameClientError('Сначала авторизуйте игровой мир')
        if mode=='logout':
            return await self._v2_call('logout')
        if mode=='connect':
            return await self._v2_call('ping')
        kind,name,payload,planet=resolve(mode,action,data)
        if mode and mode not in {"connect", "login", "logout", "lang"}:
            self.last_mode = mode
        if kind=='command':
            await self._command_v2(name,payload,planet)
            self._apply_status((await self._v2_call('query',name='player.status.get'))['data'])
            kind,name,payload,_=resolve(mode,'get' if mode=='battlereport' else '',{'id':data['id']} if mode=='battlereport' else {})
        if name in self.capabilities.get('subscriptions',[]):
            if self._view_subscription:
                self._subscriptions.pop(self._view_subscription,None)
                await self._v2_call('unsubscribe',subscriptionId=self._view_subscription)
            subscription=self._view_subscription=str(uuid.uuid4());self._subscriptions[subscription]=mode
            packet=await self._v2_call('subscribe',name=name,context={'planetId':str(self.selected_planet_id)},data=payload,subscriptionId=subscription)
        else:packet=await self._query_v2(name,payload)
        return self._compat_response(mode,action,packet['data'],packet['requestId'])

    async def _exchange(self, message: dict[str, Any], timeout: float | None = None) -> dict[str, Any]:
        if self.session_ended:
            raise GameSessionEndedError("Игровая сессия завершена; нужен новый запуск бота.")
        if self.ws is None:
            raise GameClientError("WebSocket is not connected")
        request_id = message["requestId"]
        loop = asyncio.get_running_loop()
        future: asyncio.Future[dict[str, Any]] = loop.create_future()
        self._pending[request_id] = future

        await self._log("request_sent", request=message)
        try:
            async with self._send_lock:
                await self.ws.send(json.dumps(message, ensure_ascii=False))
            response = await asyncio.wait_for(
                future,
                timeout=self.request_timeout if timeout is None else timeout,
            )
            await self._log("response_received", response=response)

            return response
        except asyncio.TimeoutError as exc:
            await self._log("request_timeout", request=message)
            raise GameClientError(f"request timeout: {request_id}") from exc
        except (OSError, WebSocketException) as exc:
            await self._log("request_transport_error", request=message, error=str(exc))
            raise GameClientError(f"websocket request failed: {exc}") from exc
        finally:
            self._pending.pop(request_id, None)

    async def _confirm_planet(self, mode: str, planet_id: int) -> None:
        """ACK ставит смену в PlayerQueue; ждём фактический server snapshot."""
        loop = asyncio.get_running_loop()
        deadline = loop.time() + self.request_timeout
        while loop.time() < deadline:
            response = await self.request(mode, "ActualData", timeout=max(0.01, deadline - loop.time()))
            data = response.get("data")
            planets = data.get("PlanetList") if isinstance(data, dict) else None
            if response.get("status") != "ok":
                raise GameClientError("planet selection snapshot failed")
            if isinstance(planets, dict) and planets.get("current_planet") == planet_id:
                self.selected_planet_id = planet_id
                return
            await asyncio.sleep(0.05)
        raise GameClientError("planet selection was not confirmed by authoritative snapshot")

    def push_checkpoint(self) -> int:
        """Возвращает границу уже полученных server push-сообщений.

        Сценарий сохраняет checkpoint непосредственно перед игровой командой,
        затем ждёт только push с большим sequence. Это не позволяет старому
        ``ActualData`` ошибочно подтвердить новую команду.
        """

        return self._push_sequence

    async def wait_for_unsolicited(
        self,
        predicate: Callable[[dict[str, Any]], bool],
        *,
        after: int,
        timeout: float,
    ) -> tuple[int, dict[str, Any]]:
        """Ждёт server push, подходящий под ``predicate``.

        Неподходящие push не удаляются: последующий шаг сценария может ожидать
        другой этап того же игрового pipeline, например завершение очереди.
        """

        loop = asyncio.get_running_loop()
        deadline = loop.time() + max(0.0, timeout)

        while True:
            async with self._push_condition:
                if self.session_ended:
                    raise GameSessionEndedError("Игровая сессия завершена; нужен новый запуск бота.")
                for sequence, message in self._pushes:
                    if sequence > after and predicate(message):
                        return sequence, message

                remaining = deadline - loop.time()
                if remaining <= 0:
                    raise GameClientError(
                        "timed out while waiting for matching unsolicited response"
                    )
                try:
                    await asyncio.wait_for(self._push_condition.wait(), timeout=remaining)
                except asyncio.TimeoutError as exc:
                    raise GameClientError(
                        "timed out while waiting for matching unsolicited response"
                    ) from exc

    async def _record_unsolicited(self, message: dict[str, Any]) -> int:
        async with self._push_condition:
            self._push_sequence += 1
            sequence = self._push_sequence
            self._pushes.append((sequence, message))
            self._push_condition.notify_all()
            return sequence

    async def _receive_loop(self) -> None:
        assert self.ws is not None
        try:
            async for raw in self.ws:
                if isinstance(raw, bytes):
                    raw = raw.decode("utf-8")
                try:
                    message = json.loads(raw)
                except json.JSONDecodeError:
                    await self._log("malformed_response", raw=raw)
                    continue

                if message.get('v')!=2 or message.get('worldId')!=self.world_id:
                    raise GameClientError('Сервер прислал неподдерживаемый протокол или другой мир')

                if "serverTime" in message:
                    self.server_time = float(message["serverTime"])
                    self._clock_offset = self.server_time-time.time()

                if message.get('v')==2 and message.get('type')=='resource':
                    if message.get('connectionId')!=self.protocol_connection or message.get('serverEpoch')!=self.server_epoch:
                        continue
                    mode=self._subscriptions.get(message.get('subscriptionId'))
                    if mode=='status':self._apply_status(message['data'])
                    elif mode:
                        await self._record_unsolicited(self._compat_response(mode,'ActualData',message['data']))
                    await self._log('resource_received',response=message)
                    continue

                v2_ended = message.get("v") == 2 and (message.get("type") == "session" or message.get("error", {}).get("fatal") is True)
                if v2_ended:
                    self.session_ended = True
                    self.token = None
                    for pending_id, pending in self._pending.items():
                        if not pending.done():
                            if (message.get("action") == "gameLoggedOut" or message.get("name") == "session.loggedOut") and pending_id == message.get("requestId"):
                                pending.set_result(message)
                            else:
                                pending.set_exception(GameSessionEndedError("Игровая сессия завершена; нужен новый запуск бота."))
                    await self._record_unsolicited(message)
                    await self._log("game_session_ended", action="gameLoggedOut" if message.get("name") == "session.loggedOut" else message.get("action", message.get("name")))
                    continue

                request_id = message.get("requestId")
                future = self._pending.get(request_id)
                if future is not None and not future.done():
                    future.set_result(message)
                else:
                    await self._record_unsolicited(message)
                    await self._log("unsolicited_response", response=message)
        except asyncio.CancelledError:
            raise
        except Exception as exc:
            await self._log("ws_receiver_error", error=str(exc), error_type=type(exc).__name__)
            for future in self._pending.values():
                if not future.done():
                    future.set_exception(GameClientError(f"receiver stopped: {exc}"))

    async def close(self) -> None:
        self.authenticated=False
        self.protocol_connection=self.server_epoch=None
        self._status_revision=0
        self._subscriptions.clear();self._view_subscription=None
        if self._receiver is not None:
            self._receiver.cancel()
            await asyncio.gather(self._receiver, return_exceptions=True)
            self._receiver = None
        if self.ws is not None:
            await self.ws.close()
            self.ws = None
        for future in self._pending.values():
            if not future.done():
                future.set_exception(GameClientError("client closed"))
        self._pending.clear()
        await self._log("ws_closed")
