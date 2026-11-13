"""P002: живой основной dev, только QA; меняет и восстанавливает имя одного флота.

Внутри sp_game_qa_bot: python /tmp/checkpoint_live.py start
Затем docker restart sp_game_qa_bot; verify получает JSON start через stdin.
ambiguity проверяет потерянный ответ с реальным применением RenameFleet.
Не создаёт второй игровой мир, не изменяет корабли/ресурсы и не печатает secrets.
"""
import argparse
import asyncio
import json
import os
import sys
import tempfile
import time
import uuid
from datetime import datetime
from pathlib import Path
from unittest.mock import patch
from urllib.request import Request, urlopen

from app.config import Settings
from app.runner import RunManager
from app.ws_client import GameClient, GameClientError


def api(path, payload=None):
    request = Request("http://127.0.0.1:8095" + path,
                      data=None if payload is None else json.dumps(payload).encode(),
                      headers={"X-Bot-Token": os.environ["BOT_API_TOKEN"],
                               "Content-Type": "application/json"})
    with urlopen(request, timeout=5) as response:
        return json.load(response)


async def connect():
    assert os.environ["SP_GAME_LOGIN"] == "qaown_260924", "requires QA owner"
    client = GameClient(os.environ["GAME_WS_URL"])
    try:
        await client.connect()
        await client.login(os.environ["SP_GAME_LOGIN"], os.environ["SP_GAME_PASSWORD"])
    except BaseException:
        await client.close()
        raise
    return client


async def fleet_snapshot(client):
    response = await client.request("fleets", "ActualData")
    assert response.get("status") == "ok"
    return response["data"]["Page"]


async def wait_name(client, fleet_id, name):
    for _ in range(100):
        page = await fleet_snapshot(client)
        fleet = page["FleetList"].get(str(fleet_id))
        if fleet and fleet["name"] == name:
            return fleet
        await asyncio.sleep(0.1)
    raise AssertionError("fleet name did not reach authoritative snapshot")


async def wait_for(predicate):
    for _ in range(200):
        result = predicate()
        if result:
            return result
        await asyncio.sleep(0.05)
    raise AssertionError("runner did not reach checkpoint")


def rename(fleet_id, name):
    return {"command": "request", "mode": "fleets", "action": "RenameFleet",
            "data": {"FleetId": fleet_id, "Name": name}, "expectStatus": "ok"}


async def prepare():
    assert api("/health")["activeRuns"] == 0, "QA service has an active run"
    client = await connect()
    try:
        page = await fleet_snapshot(client)
        fleet = next(f for f in page["FleetList"].values() if f["home_site_id"] == 0)
        return {"fleetId": fleet["id"], "original": fleet["name"],
                "planetId": page["planet_id"],
                "invariants": {key: fleet[key] for key in ("ships", "fuel", "resources", "experience")}}
    finally:
        await client.close()


async def start():
    meta = await prepare()
    marker = "QA P002 " + uuid.uuid4().hex[:8]
    run = api("/runs", {"scenario": "p002-live-restart", "duration": 180, "repeat": False,
                       "steps": [
                           {"command": "planetselect", "planetId": meta["planetId"]},
                           rename(meta["fleetId"], marker),
                           {"command": "wait", "seconds": 25},
                           {"command": "page", "mode": "fleets"},
                           rename(meta["fleetId"], meta["original"]),
                           {"command": "wait", "seconds": 1},
                           {"command": "page", "mode": "fleets"},
                       ]})

    def waiting():
        current = api("/runs/" + run["id"])
        if current["status"] == "waiting_for_instruction":
            raise AssertionError("run unexpectedly requires operator")
        checkpoint = current["checkpoint"]
        return checkpoint if checkpoint.get("index") == 2 and checkpoint.get("pending") else None

    checkpoint = await wait_for(waiting)
    return {**meta, "runId": run["id"], "marker": marker, "waitUntil": checkpoint["waitUntil"]}


async def verify(meta):
    for _ in range(200):
        run = api("/runs/" + meta["runId"])
        if run["status"] == "completed":
            break
        assert run["status"] not in {"waiting_for_instruction", "failed", "stopped"}
        await asyncio.sleep(0.25)
    assert run["status"] == "completed"
    path = Settings().log_dir / (meta["runId"] + ".jsonl")
    records = [json.loads(line) for line in path.read_text().splitlines()]
    sent = [r["request"] for r in records if r["event"] == "request_sent"
            and r["request"].get("action") == "RenameFleet"]
    assert [r["data"]["Name"] for r in sent] == [meta["marker"], meta["original"]]
    resumed = [datetime.fromisoformat(r["time"]).timestamp() for r in records if r["event"] == "run_resumed"]
    assert resumed
    finished = datetime.fromisoformat(run["finishedAt"]).timestamp()
    assert finished - max(meta["waitUntil"], resumed[-1]) < 10, "wait restarted instead of resuming"
    client = await connect()
    try:
        fleet = await wait_name(client, meta["fleetId"], meta["original"])
        assert {key: fleet[key] for key in meta["invariants"]} == meta["invariants"]
    finally:
        await client.close()
    return {"passed": True, "runId": run["id"], "restartResumed": True,
            "renameRequests": len(sent), "fleetId": meta["fleetId"],
            "nameRestored": True, "shipsFuelCargoExperienceUnchanged": True}


async def ambiguity():
    meta = await prepare()
    results = []
    for with_condition in (True, False):
        marker = "QA P002 " + uuid.uuid4().hex[:8]
        forwarded = []
        injected = False

        class DropReplyClient(GameClient):
            async def request(self, mode, action="", data=None, **kwargs):
                nonlocal injected
                if action == "RenameFleet":
                    forwarded.append(data["Name"])
                response = await super().request(mode, action, data, **kwargs)
                if action == "RenameFleet" and not injected:
                    injected = True
                    # Реальный сервер уже применил мутацию; runner не получит ACK.
                    for _ in range(100):
                        state = await super().request("fleets", "ActualData")
                        fleet = state["data"]["Page"]["FleetList"][str(meta["fleetId"])]
                        if fleet["name"] == marker:
                            break
                        await asyncio.sleep(0.05)
                    assert fleet["name"] == marker
                    await self.close()
                    raise GameClientError("P002 controlled response loss after real application")
                return response

        with tempfile.TemporaryDirectory(prefix="p002-live-") as directory:
            settings = Settings(log_dir=Path(directory) / "runs", state_file=Path(directory) / "state.json",
                                retry_initial_seconds=5, retry_before_question=5)
            manager = RunManager(settings)
            restored = None
            step = rename(meta["fleetId"], marker)
            if with_condition:
                step["postcondition"] = {"mode": "fleets", "path": ["data", "Page", "FleetList",
                                         str(meta["fleetId"]), "name"], "equals": marker}
            try:
                with patch("app.runner.GameClient", DropReplyClient):
                    run = await manager.create({"scenario": "p002-real-lost-reply", "duration": 120,
                                                "steps": [step, {"command": "page", "mode": "fleets"}]})
                    await wait_for(lambda: run.status in {"retrying", "waiting_for_instruction"})
                    assert run.checkpoint.get("pending")
                    await manager.shutdown()
                    restored = RunManager(settings)
                    await restored.restore()
                    recovered = restored.get(run.id)
                    if not with_condition:
                        await wait_for(lambda: recovered.status == "waiting_for_instruction")
                        await restored.resolve_question(recovered.question_id, "retry")
                        await wait_for(lambda: recovered.status == "waiting_for_instruction")
                        assert forwarded == [marker], "retry duplicated mutation"
                        await restored.resolve_question(recovered.question_id, "confirm_applied",
                                                        "authoritative fleet name verified after restart")
                    await wait_for(lambda: recovered.status == "completed")
                assert forwarded == [marker], "mutation was sent more than once"
                results.append({"postcondition": with_condition, "sentOnce": True,
                                "restoredFromDisk": True, "completed": True})
            finally:
                await manager.shutdown()
                if restored:
                    await restored.shutdown()
                client = await connect()
                try:
                    response = await client.request("fleets", "RenameFleet",
                                                    {"FleetId": meta["fleetId"], "Name": meta["original"]})
                    assert response.get("status") == "ok"
                    fleet = await wait_name(client, meta["fleetId"], meta["original"])
                    assert {key: fleet[key] for key in meta["invariants"]} == meta["invariants"]
                finally:
                    await client.close()
    return {"passed": True, "cases": results, "nameRestored": True,
            "shipsFuelCargoExperienceUnchanged": True}


async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("phase", choices=("start", "verify", "ambiguity"))
    phase = parser.parse_args().phase
    if phase == "start":
        result = await start()
    elif phase == "verify":
        result = await verify(json.load(sys.stdin))
    else:
        result = await ambiguity()
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    asyncio.run(main())
