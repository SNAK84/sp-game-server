"""P002: durable-позиция и запрет слепого повтора игровых мутаций."""
import asyncio
import copy
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from app.config import Settings
from app.logging_utils import JsonlLog
from app.runner import Run, RunManager
from app.scenarios import (
    ScenarioError, checkpoint_can_resume, execute_steps, pending_step, resumable_steps,
)
from app.state_store import StateStore, StateStoreError
from app.ws_client import GameClient, GameClientError


def snapshot(name="old"):
    return {
        "status": "ok", "token": "private-token", "data": {
            "Page": {"page": "fleet", "name": name,
                     "QueueList": {"42": {"qid": 42, "id": 4}}},
            "TopNav": {"Resources": {"901": {"count": 10}}},
        },
    }


class Client:
    def __init__(self, results=()):
        self.results = list(results)
        self.calls = []
        self.selected_planet_id = None
        self.authenticated_login = ""
        self.connection_id = "fake-connection"

    def push_checkpoint(self):
        return 0

    async def connect(self):
        pass

    async def login(self, *args):
        self.authenticated_login = args[0]
        return snapshot()

    async def close(self):
        pass

    async def request(self, mode, action="", data=None):
        self.calls.append((mode, action, data))
        result = self.results.pop(0) if self.results else snapshot()
        if isinstance(result, BaseException):
            raise result
        if action == "planetselect":
            self.selected_planet_id = data["planetId"]
        return result


async def log(*args):
    pass


class CheckpointTest(unittest.IsolatedAsyncioTestCase):
    async def execute(self, steps, checkpoint, client, save=None, repeat=False):
        return await execute_steps(
            client, steps, duration=30, repeat=repeat,
            stop_event=asyncio.Event(), log=log, counters={},
            checkpoint=checkpoint, save_checkpoint=save,
        )

    def mutation(self, **extra):
        return {"command": "request", "mode": "fleets", "action": "SendMission", **extra}

    def condition(self):
        return {"mode": "fleets", "path": ["data", "Page", "name"], "equals": "new"}

    async def test_completed_mutation_is_not_replayed_after_read_disconnect(self):
        steps = [self.mutation(), {"command": "page"}, {"command": "page", "mode": "defense"}]
        progress = {}
        first = Client([snapshot(), GameClientError("disconnect")])
        with self.assertRaises(GameClientError):
            await self.execute(steps, progress, first)
        self.assertEqual(progress["index"], 1)
        self.assertTrue(checkpoint_can_resume(steps, progress))
        resumed = Client()
        await self.execute(steps, progress, resumed)
        self.assertTrue(progress["done"])
        self.assertEqual([x[1] for x in resumed.calls], ["ActualData", "ActualData"])

    async def test_ambiguous_mutation_without_condition_is_never_replayed(self):
        steps = [self.mutation()]
        progress = {}
        with self.assertRaises(GameClientError):
            await self.execute(steps, progress, Client([GameClientError("lost ACK")]))
        self.assertFalse(checkpoint_can_resume(steps, progress))
        resumed = Client()
        with self.assertRaisesRegex(ScenarioError, "ambiguous"):
            await self.execute(steps, progress, resumed)
        self.assertEqual(resumed.calls, [])

    async def test_lost_ack_is_reconciled_by_false_then_true_postcondition(self):
        steps = [self.mutation(postcondition=self.condition()), {"command": "page"}]
        progress = {}
        with self.assertRaises(GameClientError):
            await self.execute(steps, progress, Client([snapshot(), GameClientError("lost ACK")]))
        resumed = Client([snapshot("new"), snapshot()])
        await self.execute(steps, progress, resumed)
        self.assertTrue(progress["done"])
        self.assertTrue(all(x[1] == "ActualData" for x in resumed.calls))

    async def test_unmatched_postcondition_does_not_authorize_resend(self):
        progress = {"pending": True, "postcondition": self.condition()}
        resumed = Client()
        with self.assertRaisesRegex(ScenarioError, "ambiguous"):
            await self.execute([self.mutation()], progress, resumed)
        self.assertEqual(len(resumed.calls), 1)
        self.assertEqual(resumed.calls[0][1], "ActualData")

    async def test_preexisting_postcondition_blocks_original_mutation(self):
        client = Client([snapshot("new")])
        with self.assertRaisesRegex(ScenarioError, "already matches"):
            await self.execute([self.mutation(postcondition=self.condition())], {}, client)
        self.assertEqual(len(client.calls), 1)
        self.assertEqual(client.calls[0][1], "ActualData")

    async def test_postcondition_may_not_invoke_an_action(self):
        client = Client()
        condition = {**self.condition(), "mode": "logout"}
        with self.assertRaises(ScenarioError):
            await self.execute([self.mutation(postcondition=condition)], {}, client)
        self.assertEqual(client.calls, [])

    async def test_missing_page_is_not_proof_of_successful_deletion(self):
        condition = {"mode": "fleets", "path": ["data", "Page", "gone"], "exists": False}
        client = Client([{"status": "ok", "data": {}}])
        with self.assertRaisesRegex(ScenarioError, "snapshot failed"):
            await self.execute([self.mutation()], {"pending": True, "postcondition": condition}, client)
        self.assertEqual(client.calls[0][1], "ActualData")

    async def test_exists_false_does_not_confuse_missing_with_null(self):
        condition = {"mode": "fleets", "path": ["data", "Page", "gone"], "exists": False}
        progress = {"pending": True, "postcondition": condition}
        await self.execute([self.mutation()], progress, Client())
        self.assertTrue(progress["done"])

    async def test_context_and_queue_id_survive_new_executor(self):
        steps = [
            {"command": "expect_queue_state", "elementId": 4,
             "queueState": "present", "saveQueueIdAs": "qid"},
            {"command": "page"},
            {"command": "cancel", "queueId": "$qid"},
        ]
        progress = {}
        with self.assertRaises(GameClientError):
            await self.execute(steps, progress, Client([snapshot(), GameClientError("disconnect")]))
        resumed = Client()
        context = await self.execute(steps, copy.deepcopy(progress), resumed)
        self.assertEqual(context.variables["qid"], 42)
        self.assertEqual(resumed.calls[-1][2], {"id": 42})

    async def test_nested_repeat_does_not_replay_previous_iteration(self):
        steps = [{"command": "repeat", "count": 2, "steps": [
            {"command": "repeat", "count": 2, "steps": [self.mutation(), {"command": "page"}]}]}]
        progress = {}
        first = Client([snapshot(), snapshot(), snapshot(), GameClientError("disconnect")])
        with self.assertRaises(GameClientError):
            await self.execute(steps, progress, first)
        self.assertEqual(pending_step(steps, progress)[0]["command"], "page")
        resumed = Client()
        await self.execute(steps, progress, resumed)
        # Из четырёх отправок две уже прошли до disconnect.
        self.assertEqual(sum(x[1] == "SendMission" for x in resumed.calls), 2)
        self.assertTrue(progress["done"])

    async def test_done_child_is_not_repeated_after_checkpoint_boundary_crash(self):
        steps = [{"command": "repeat", "count": 2, "steps": [self.mutation()]}]
        progress = {"child": {"index": 1, "done": True}}
        resumed = Client()
        await self.execute(steps, progress, resumed)
        self.assertEqual(sum(x[1] == "SendMission" for x in resumed.calls), 1)

    async def test_expired_wait_is_not_started_again(self):
        progress = {"pending": True, "waitUntil": time.time() - 10}
        start = time.monotonic()
        await self.execute([{"command": "wait", "seconds": 100}], progress, Client())
        self.assertLess(time.monotonic() - start, 0.5)
        self.assertTrue(progress["done"])

    async def test_pending_marker_is_durable_before_request(self):
        progress = {}
        saved = []
        client = Client()

        def save():
            saved.append(copy.deepcopy(progress))
            if progress.get("pending"):
                self.assertEqual(client.calls, [])

        await self.execute([self.mutation()], progress, client, save)
        self.assertTrue(saved[0]["pending"])
        self.assertEqual(saved[-1]["index"], 1)

    async def test_failed_precommand_checkpoint_prevents_send(self):
        client = Client()

        def fail():
            raise StateStoreError("disk unavailable")

        with self.assertRaises(StateStoreError):
            await self.execute([self.mutation()], {}, client, fail)
        self.assertEqual(client.calls, [])

    async def test_build_targets_keeps_internal_pending_build_after_lost_ack(self):
        def buildings(level):
            return {"status": "ok", "data": {
                "Page": {"BuildList": {"4": {"level": level, "Prod": {"Next": {"911": 1}}}},
                         "QueueList": {}},
                "TopNav": {"Resources": {"911": {"count": 100}}},
            }}

        steps = [{"command": "build_targets", "targets": {"4": 1}, "pollSeconds": 1}]
        progress = {}
        first = Client([buildings(0), GameClientError("accepted build, lost ACK")])
        with self.assertRaises(GameClientError):
            await self.execute(steps, progress, first)
        self.assertEqual(progress["buildPending"]["fromLevel"], 0)
        resumed = Client([buildings(0), buildings(1)])
        await self.execute(steps, progress, resumed)
        self.assertTrue(progress["done"])
        self.assertFalse(any(x[1] == "build" for x in resumed.calls))
        self.assertNotIn("buildPending", progress)

    async def test_repeat_inherits_push_boundary_on_same_connection(self):
        class PushClient(GameClient):
            async def request(self, mode, action="", data=None):
                await self._record_unsolicited({"status": "ok", "mode": "buildings"})
                return {"status": "ok"}

        client = PushClient("ws://unused")
        client.connection_id = "push-test"
        steps = [{"command": "build", "elementId": 4},
                 {"command": "repeat", "steps": [
                     {"command": "wait_for_push", "expectMode": "buildings", "timeoutSeconds": 0.1}
                 ]}]
        progress = {}
        await self.execute(steps, progress, client)
        self.assertTrue(progress["done"])

    def test_page_with_mutating_action_is_not_classified_safe(self):
        self.assertFalse(resumable_steps([{"command": "page", "action": "SendMission"}]))


class ManagerCheckpointTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        root = Path(self.directory.name)
        self.settings = Settings(log_dir=root / "runs", state_file=root / "state.json",
                                 retry_initial_seconds=0.1, retry_before_question=2)
        self.manager = RunManager(self.settings)
        self.run = Run("test", "checkpoint", 300,
                       [{"command": "request", "mode": "fleets", "action": "SendMission"}],
                       False, JsonlLog(root / "runs/test.jsonl"), False)
        self.manager.runs[self.run.id] = self.run
        self.addAsyncCleanup(self.manager.shutdown)

    async def wait_for(self, predicate):
        for _ in range(200):
            if predicate():
                return
            await asyncio.sleep(0.005)
        self.fail("runner did not reach expected state")

    async def test_plain_retry_never_replays_pending_mutation(self):
        client = Client([GameClientError("lost ACK")])
        with patch("app.runner.GameClient", return_value=client):
            self.manager._schedule(self.run)
            await self.wait_for(lambda: self.run.status == "waiting_for_instruction")
            await self.manager.resolve_question(self.run.question_id, "retry")
            await self.wait_for(lambda: len(self.manager.questions) == 2)
            self.assertEqual(len(client.calls), 1)

    async def test_operator_confirm_applied_skips_only_current_step(self):
        self.run.steps.append({"command": "page"})
        client = Client([GameClientError("lost ACK"), snapshot()])
        with patch("app.runner.GameClient", return_value=client):
            self.manager._schedule(self.run)
            await self.wait_for(lambda: self.run.status == "waiting_for_instruction")
            await self.manager.resolve_question(self.run.question_id, "confirm_applied", "verified fleet")
            await self.wait_for(lambda: self.run.status == "completed")
        self.assertEqual([x[1] for x in client.calls], ["SendMission", "ActualData"])

    async def test_operator_confirm_not_applied_retries_only_current_step(self):
        self.run.steps.insert(0, {"command": "page"})
        client = Client([snapshot(), GameClientError("not sent"), snapshot()])
        with patch("app.runner.GameClient", return_value=client):
            self.manager._schedule(self.run)
            await self.wait_for(lambda: self.run.status == "waiting_for_instruction")
            await self.manager.resolve_question(self.run.question_id, "confirm_not_applied", "checked server")
            await self.wait_for(lambda: self.run.status == "completed")
        self.assertEqual([x[1] for x in client.calls], ["ActualData", "SendMission", "SendMission"])

    async def test_confirmation_requires_evidence_instruction(self):
        self.run.checkpoint = {"pending": True}
        question = self.manager._open_question(self.run, "ambiguous", "test")
        with self.assertRaises(ValueError):
            await self.manager.resolve_question(question.id, "confirm_applied")
        self.assertEqual(question.status, "open")

    async def test_legacy_unsafe_run_cannot_be_restarted_from_zero(self):
        value = self.run.to_state()
        value.pop("checkpoint")
        restored = Run.from_state(value, self.settings)
        self.manager.runs[restored.id] = restored
        question = self.manager._open_question(restored, "legacy", "test")
        with self.assertRaisesRegex(ValueError, "legacy"):
            await self.manager.resolve_question(question.id, "retry")

    async def test_restart_after_completed_mutation_restores_position_and_planet(self):
        self.run.steps.append({"command": "page"})
        self.run.status = "running"
        self.run.checkpoint = {"index": 1, "pending": True, "session": {"planetId": 23}}
        self.manager._persist()
        restored = RunManager(self.settings)
        self.addAsyncCleanup(restored.shutdown)
        client = Client()
        with patch("app.runner.GameClient", return_value=client):
            await restored.restore()
            await self.wait_for(lambda: restored.get("test").status == "completed")
        self.assertEqual([x[1] for x in client.calls], ["planetselect", "ActualData"])
        self.assertEqual(client.calls[0][2], {"planetId": 23})

    async def test_saved_context_redacts_credentials(self):
        self.run.checkpoint = {"context": {"variables": {"snapshot": snapshot()}}}
        self.manager._persist()
        raw = self.settings.state_file.read_text()
        self.assertNotIn("private-token", raw)
        self.assertIn("<redacted>", raw)
        self.assertEqual(StateStore(self.settings.state_file).load()["runs"][0]["checkpoint"],
                         self.run.public()["checkpoint"])

    async def test_checkpoint_damage_prevents_new_runs(self):
        value = self.run.to_state()
        value["checkpoint"] = {"index": -1}
        StateStore(self.settings.state_file).save({"version": 2, "runs": [value]})
        manager = RunManager(self.settings)
        await manager.restore()
        self.assertEqual(manager.health()["status"], "degraded")
        with self.assertRaisesRegex(ValueError, "degraded"):
            await manager.create({"scenario": "smoke"})
        original = self.settings.state_file.read_bytes()
        await manager.shutdown()
        self.assertEqual(self.settings.state_file.read_bytes(), original)

    async def test_checkpoint_confirm_is_persisted_before_runner_is_awakened(self):
        self.run.checkpoint = {"pending": True}
        question = self.manager._open_question(self.run, "ambiguous", "test")
        with patch.object(self.manager._store, "save", side_effect=StateStoreError("disk")):
            with self.assertRaises(StateStoreError):
                await self.manager.resolve_question(question.id, "confirm_applied", "verified")
        self.assertFalse(self.run.resume_event.is_set())
        self.assertIsNone(self.run.task)

    def test_state_replace_is_flushed_to_disk(self):
        with patch("app.state_store.os.fsync") as fsync:
            self.manager._persist()
        self.assertEqual(fsync.call_count, 2)

    def test_expired_ambiguous_mutation_is_not_reported_completed(self):
        self.run.checkpoint = {"pending": True}
        self.manager._finish_at_deadline(self.run)
        self.assertEqual(self.run.status, "failed")

    def test_expired_incomplete_nonrepeating_scenario_is_not_completed(self):
        self.run.checkpoint = {"index": 0}
        self.manager._finish_at_deadline(self.run)
        self.assertEqual(self.run.status, "failed")

    async def test_resolution_survives_crash_before_waiting_task_wakes(self):
        self.run.checkpoint = {"pending": True}
        self.run.task = asyncio.create_task(asyncio.sleep(60))
        question = self.manager._open_question(self.run, "ambiguous", "test")
        await self.manager.resolve_question(question.id, "confirm_applied", "checked fleet")
        saved = StateStore(self.settings.state_file).load()["runs"][0]
        self.assertEqual(saved["status"], "queued")
        self.assertIsNone(saved["questionId"])
        self.assertEqual(saved["checkpoint"]["index"], 1)

    async def test_different_account_checkpoint_never_sends_default_account_commands(self):
        self.run.checkpoint = {"session": {"login": "other-account"}}
        client = Client()
        with patch("app.runner.GameClient", return_value=client):
            self.manager._schedule(self.run)
            await self.wait_for(lambda: self.run.status == "waiting_for_instruction")
        self.assertEqual(client.calls, [])

    async def test_confirm_applied_planetselect_restores_new_not_old_planet(self):
        self.run.steps = [{"command": "planetselect", "planetId": "$destination"},
                          {"command": "build", "elementId": 4}]
        self.run.checkpoint = {"pending": True, "session": {"planetId": 11},
                               "context": {"variables": {"destination": 23}}}
        question = self.manager._open_question(self.run, "ambiguous", "test")
        client = Client()
        with patch("app.runner.GameClient", return_value=client):
            await self.manager.resolve_question(question.id, "confirm_applied", "selected planet is 23")
            await self.wait_for(lambda: self.run.status == "completed")
        self.assertEqual(client.calls[0][2], {"planetId": 23})
        self.assertEqual(client.calls[1][1], "build")

    async def test_confirm_applied_alternate_login_cannot_build_as_default_account(self):
        self.run.steps = [{"command": "login", "login": "alternate", "password": "not-persisted"},
                          {"command": "build", "elementId": 4}]
        self.run.checkpoint = {"pending": True}
        question = self.manager._open_question(self.run, "ambiguous", "test")
        client = Client()
        with patch("app.runner.GameClient", return_value=client):
            await self.manager.resolve_question(question.id, "confirm_applied", "alternate account verified")
            await self.wait_for(lambda: self.run.status == "waiting_for_instruction")
        self.assertEqual(self.run.checkpoint["session"]["login"], "alternate")
        self.assertEqual(client.calls, [])

    async def test_bad_json_is_not_overwritten_during_shutdown(self):
        self.settings.state_file.write_text("{broken JSON")
        manager = RunManager(self.settings)
        await manager.restore()
        self.assertEqual(manager.health()["status"], "degraded")
        await manager.shutdown()
        self.assertEqual(self.settings.state_file.read_text(), "{broken JSON")


class PlanetSelectionTest(unittest.IsolatedAsyncioTestCase):
    async def test_acceptance_ack_does_not_select_planet_before_actual_data(self):
        client = GameClient("ws://unused")
        client.authenticated = True
        client.selected_planet_id = 11
        client.ws = object()
        client.capabilities = {'subscriptions': []}
        actions=[]
        async def applied(name,data,planet):
            self.assertEqual(client.selected_planet_id,11)
            self.assertEqual((name,planet),('planet.select','23'))
            actions.append('applied')
        async def call(kind,**fields):
            actions.append(fields['name'])
            if fields['name']=='player.status.get':
                return {'data':{'revision':'1','value':{'planets':{'current_planet':'23'},'topNav':{}}}}
            return {'requestId':'read','data':{'value':{},'observedAt':1}}
        client._command_v2=applied
        client._v2_call=call
        response = await client.request("overview", "planetselect", {"planetId": 23})
        self.assertEqual(response["status"], "ok")
        self.assertEqual(client.selected_planet_id, 23)
        self.assertEqual(actions, ['applied','player.status.get','overview.get'])


if __name__ == "__main__":
    unittest.main()
