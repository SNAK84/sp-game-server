import asyncio
import tempfile
import unittest
from pathlib import Path

from app.config import Settings
from app.logging_utils import JsonlLog
from app.runner import Run, RunManager
from app.scenarios import (
    ScenarioError,
    assert_response_expectations,
    next_energy_delta,
    prioritize_energy_recovery,
    queue_contains_element,
    queue_state_matches,
    resource_delta_since,
    resolve_variables,
    resumable_steps,
    ScenarioContext,
)
from app.state_store import StateStore


class RecordingRunManager(RunManager):
    def __init__(self, settings: Settings) -> None:
        super().__init__(settings)
        self.scheduled: list[str] = []

    def _schedule(self, run):
        self.scheduled.append(run.id)


class ReliabilityTest(unittest.IsolatedAsyncioTestCase):
    def settings(self, directory: Path) -> Settings:
        return Settings(
            log_dir=directory / "runs",
            state_file=directory / "state.json",
            max_run_seconds=3600,
        )

    def test_only_declarative_steps_are_resumable(self) -> None:
        self.assertTrue(
            resumable_steps(
                [
                    {"command": "page", "mode": "buildings"},
                    {"command": "build_targets", "targets": {"4": 8}},
                    {"command": "wait", "seconds": 1},
                ]
            )
        )
        self.assertFalse(resumable_steps([{"command": "build", "elementId": 4}]))

    def test_energy_recovery_moves_before_resource_builds(self) -> None:
        self.assertEqual(prioritize_energy_recovery([1, 4, 2], {4}), [4, 1, 2])

    def test_energy_forecast_uses_server_build_payload(self) -> None:
        self.assertEqual(next_energy_delta({"Prod": {"Next": {"911": "701.5"}}}), 701.5)
        self.assertEqual(next_energy_delta({"Prod": None}), 0.0)
        self.assertEqual(next_energy_delta({}), 0.0)
        with self.assertRaises(ScenarioError):
            next_energy_delta({"Prod": {"Next": {"911": "not-a-number"}}})

    def test_ack_confirmation_requires_matching_queue_element(self) -> None:
        self.assertTrue(queue_contains_element({"12": {"id": 4}}, 4))
        self.assertTrue(queue_contains_element([{"id": "4"}], 4))
        self.assertFalse(queue_contains_element({"12": {"id": 1}}, 4))

    def test_queue_state_uses_actual_page_payload(self) -> None:
        response = {
            "status": "ok",
            "data": {
                "Page": {
                    "page": "buildings",
                    "QueueList": {
                        "42": {"qid": 42, "id": 4, "status": "active", "level": 2}
                    },
                    "BuildList": {"4": {"id": 4, "level": 1}},
                }
            },
        }
        present, details = queue_state_matches(
            response,
            element_id=4,
            queue_state="present",
            queue_status="active",
            min_level=1,
        )
        absent, _ = queue_state_matches(
            response,
            element_id=4,
            queue_state="absent",
            queue_status=None,
            min_level=None,
        )

        self.assertTrue(present)
        self.assertEqual(details["queue"]["qid"], 42)
        self.assertFalse(absent)

    def test_scenario_variable_resolves_only_saved_value(self) -> None:
        context = ScenarioContext()
        context.variables["queueId"] = 42

        self.assertEqual(
            resolve_variables({"id": "$queueId", "items": ["$queueId"]}, context),
            {"id": 42, "items": [42]},
        )
        with self.assertRaises(ScenarioError):
            resolve_variables("$missing", context)

    def test_resource_delta_excludes_natural_production(self) -> None:
        baseline = {
            "serverTime": 100.0,
            "data": {
                "TopNav": {
                    "Resources": {
                        "901": {
                            "count": 100.0,
                            "max": 1000.0,
                            "perhour": 3600.0,
                            "time": 10.0,
                        }
                    }
                }
            },
        }
        current = {
            "serverTime": 110.0,
            "data": {
                "TopNav": {
                    "Resources": {
                        "901": {
                            "count": 80.0,
                            "max": 1000.0,
                            "perhour": 3600.0,
                            "time": 20.0,
                        }
                    }
                }
            },
        }

        # resource.time отличается от serverTime, но за 10 секунд resource
        # времени без команды накопилось бы 10, фактически стало 80:
        # чистое изменение -30, то есть списание ровно 30.
        self.assertEqual(resource_delta_since(baseline, current, "901"), -30.0)

    def test_empty_ack_accepts_php_empty_array_or_object(self) -> None:
        step = {"expectDataEmpty": True}
        assert_response_expectations({"data": []}, step, step_index=1)
        assert_response_expectations({"data": {}}, step, step_index=1)
        with self.assertRaises(ScenarioError):
            assert_response_expectations({"data": {"Page": {}}}, step, step_index=1)

    async def test_running_declarative_run_is_scheduled_after_restore(self) -> None:
        with tempfile.TemporaryDirectory() as raw_directory:
            directory = Path(raw_directory)
            settings = self.settings(directory)
            StateStore(settings.state_file).save(
                {
                    "version": 1,
                    "runs": [
                        {
                            "id": "safe-run",
                            "scenario": "target",
                            "duration": 300,
                            "repeat": False,
                            "resumable": True,
                            "steps": [{"command": "build_targets", "targets": {"4": 8}}],
                            "status": "running",
                            "deadlineAt": "2099-01-01T00:00:00+00:00",
                            "counters": {},
                        }
                    ],
                    "questions": [],
                }
            )
            manager = RecordingRunManager(settings)
            await manager.restore()
            self.assertEqual(manager.scheduled, ["safe-run"])
            self.assertEqual(manager.get("safe-run").status, "queued")

    async def test_non_idempotent_run_becomes_operator_question_after_restore(self) -> None:
        with tempfile.TemporaryDirectory() as raw_directory:
            directory = Path(raw_directory)
            settings = self.settings(directory)
            StateStore(settings.state_file).save(
                {
                    "version": 1,
                    "runs": [
                        {
                            "id": "unsafe-run",
                            "scenario": "direct-build",
                            "duration": 300,
                            "repeat": False,
                            "resumable": False,
                            "steps": [{"command": "build", "elementId": 4}],
                            "status": "running",
                            "deadlineAt": "2099-01-01T00:00:00+00:00",
                            "counters": {},
                        }
                    ],
                    "questions": [],
                }
            )
            manager = RecordingRunManager(settings)
            await manager.restore()
            run = manager.get("unsafe-run")
            self.assertEqual(run.status, "waiting_for_instruction")
            self.assertIsNotNone(run.question_id)
            self.assertEqual(
                manager.get_question(run.question_id).code,
                "restart_requires_confirmation",
            )

    async def test_stop_resolution_wakes_waiting_run(self) -> None:
        with tempfile.TemporaryDirectory() as raw_directory:
            directory = Path(raw_directory)
            settings = self.settings(directory)
            manager = RecordingRunManager(settings)
            run = Run(
                "waiting-run",
                "direct-command",
                300,
                [{"command": "build", "elementId": 4}],
                False,
                JsonlLog(settings.log_dir / "waiting-run.jsonl"),
                False,
            )
            manager.runs[run.id] = run
            question = manager._open_question(run, "test", "operator decision required")
            waiting = asyncio.create_task(
                manager._await_instruction(run, question.code, question.message, question.context)
            )
            await asyncio.sleep(0)
            await manager.resolve_question(question.id, "stop")
            self.assertEqual(await waiting, "stop")
