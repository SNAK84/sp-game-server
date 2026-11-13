from __future__ import annotations

import asyncio
import uuid
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from typing import Any

from .config import Settings
from .logging_utils import JsonlLog, redact
from .scenarios import (
    ScenarioError, checkpoint_can_resume, default_steps, execute_steps,
    pending_step, resumable_steps, validate_checkpoint, session_action,
    ScenarioContext, resolve_variables,
)
from .state_store import StateStore, StateStoreError
from .ws_client import GameAuthenticationError, GameSessionEndedError, GameClient, GameClientError


ACTIVE_STATUSES = {"queued", "running", "retrying", "stopping", "waiting_for_instruction"}
TERMINAL_STATUSES = {"completed", "stopped", "failed"}


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def parse_utc(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        parsed = datetime.fromisoformat(value)
    except (TypeError, ValueError):
        return None
    if parsed.tzinfo is None:
        return parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


@dataclass
class OperatorQuestion:
    id: str
    run_id: str
    code: str
    message: str
    context: dict[str, Any]
    created_at: str = field(default_factory=utc_now)
    status: str = "open"
    resolution: str | None = None
    instruction: str | None = None
    resolved_at: str | None = None

    def public(self) -> dict[str, Any]:
        return {
            "id": self.id,
            "runId": self.run_id,
            "code": self.code,
            "message": self.message,
            "context": redact(self.context),
            "createdAt": self.created_at,
            "status": self.status,
            "resolution": self.resolution,
            "instruction": self.instruction,
            "resolvedAt": self.resolved_at,
        }

    def to_state(self) -> dict[str, Any]:
        return self.public()

    @classmethod
    def from_state(cls, value: dict[str, Any]) -> OperatorQuestion:
        context = value.get("context", {})
        if not isinstance(context, dict):
            raise ValueError("question context must be an object")
        return cls(
            id=str(value["id"]),
            run_id=str(value["runId"]),
            code=str(value.get("code", "operator_action_required")),
            message=str(value.get("message", "operator action is required")),
            context=context,
            created_at=str(value.get("createdAt") or utc_now()),
            status=str(value.get("status", "open")),
            resolution=value.get("resolution"),
            instruction=value.get("instruction"),
            resolved_at=value.get("resolvedAt"),
        )


@dataclass
class Run:
    id: str
    scenario: str
    duration: float
    steps: list[dict[str, Any]]
    repeat: bool
    log: JsonlLog
    resumable: bool
    status: str = "queued"
    started_at: str | None = None
    deadline_at: str | None = None
    finished_at: str | None = None
    error: str | None = None
    counters: dict[str, int] = field(default_factory=dict)
    retry_count: int = 0
    question_id: str | None = None
    checkpoint: dict[str, Any] = field(default_factory=dict)
    resume_on_restart: bool = True
    stop_event: asyncio.Event = field(default_factory=asyncio.Event, repr=False)
    resume_event: asyncio.Event = field(default_factory=asyncio.Event, repr=False)
    pending_resolution: str | None = field(default=None, repr=False)
    task: asyncio.Task[None] | None = field(default=None, repr=False)

    def public(self) -> dict[str, Any]:
        return {
            "id": self.id,
            "scenario": self.scenario,
            "duration": self.duration,
            "repeat": self.repeat,
            "resumable": self.resumable,
            "steps": redact(self.steps),
            "status": self.status,
            "startedAt": self.started_at,
            "deadlineAt": self.deadline_at,
            "finishedAt": self.finished_at,
            "error": self.error,
            "retryCount": self.retry_count,
            "questionId": self.question_id,
            "checkpoint": redact(self.checkpoint),
            "resumeOnRestart": self.resume_on_restart,
            "counters": self.counters,
            "logFile": str(self.log.path),
        }

    def to_state(self) -> dict[str, Any]:
        return self.public()

    @classmethod
    def from_state(cls, value: dict[str, Any], settings: Settings) -> Run:
        run_id = str(value["id"])
        steps = value.get("steps", [])
        if not isinstance(steps, list) or any(not isinstance(step, dict) for step in steps):
            raise ValueError("run steps must be an array of objects")
        checkpoint = value.get("checkpoint")
        if checkpoint is None:
            # Старый runner не знал позицию; мутации с начала не переигрываем.
            checkpoint = {} if resumable_steps(steps) else {"legacy": True}
        if not isinstance(checkpoint, dict):
            raise ValueError("run checkpoint must be an object")
        validate_checkpoint(steps, checkpoint)
        return cls(
            id=run_id,
            scenario=str(value.get("scenario", "restored")),
            duration=float(value.get("duration", 60)),
            steps=[dict(step) for step in steps],
            repeat=bool(value.get("repeat", False)),
            log=JsonlLog(settings.log_dir / f"{run_id}.jsonl"),
            resumable=bool(value.get("resumable", resumable_steps(steps))),
            status=str(value.get("status", "queued")),
            started_at=value.get("startedAt"),
            deadline_at=value.get("deadlineAt"),
            finished_at=value.get("finishedAt"),
            error=value.get("error"),
            counters=dict(value.get("counters", {})),
            retry_count=int(value.get("retryCount", 0)),
            question_id=value.get("questionId"),
            checkpoint=checkpoint,
            resume_on_restart=bool(value.get("resumeOnRestart", True)),
        )


class RunManager:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self.runs: dict[str, Run] = {}
        self.questions: dict[str, OperatorQuestion] = {}
        self._lock = asyncio.Lock()
        self._store = StateStore(settings.state_file)
        self.recovery_error: str | None = None
        self._state_unrecoverable = False
        self._shutting_down = False

    def _state_payload(self) -> dict[str, Any]:
        return {
            "version": StateStore.VERSION,
            "runs": [run.to_state() for run in self.runs.values()],
            "questions": [question.to_state() for question in self.questions.values()],
        }

    def _persist(self) -> None:
        if self._state_unrecoverable:
            raise StateStoreError("state recovery failed; original state left unchanged")
        try:
            self._store.save(self._state_payload())
        except StateStoreError as exc:
            self.recovery_error = str(exc)
            self._state_unrecoverable = True
            raise

    def _schedule(self, run: Run) -> None:
        if run.task is None or run.task.done():
            run.task = asyncio.create_task(self._execute(run))

    def _remaining_seconds(self, run: Run) -> float:
        deadline = parse_utc(run.deadline_at)
        if deadline is None:
            return run.duration
        return max(0.0, (deadline - datetime.now(timezone.utc)).total_seconds())

    def _finish_at_deadline(self, run: Run) -> None:
        uncertain = not checkpoint_can_resume(run.steps, run.checkpoint)
        unfinished = not run.repeat and not run.checkpoint.get("done")
        run.status = "failed" if uncertain or unfinished else "completed"
        if run.status == "failed":
            run.error = "deadline expired before all steps were confirmed; inspect checkpoint, do not replay"

    def _active_question(self, run_id: str) -> OperatorQuestion | None:
        for question in self.questions.values():
            if question.run_id == run_id and question.status == "open":
                return question
        return None

    def _open_question(
        self,
        run: Run,
        code: str,
        message: str,
        context: dict[str, Any] | None = None,
    ) -> OperatorQuestion:
        question = self._active_question(run.id)
        if question is None:
            question = OperatorQuestion(
                id=uuid.uuid4().hex,
                run_id=run.id,
                code=code,
                message=message,
                context=context or {},
            )
            self.questions[question.id] = question
        run.status = "waiting_for_instruction"
        run.error = message
        run.question_id = question.id
        return question

    async def restore(self) -> None:
        """Восстанавливает задачи после restart контейнера.

        Подтверждённые шаги пропускаются. Неопределённая мутация требует
        postcondition либо решения оператора, но не слепого повторного запроса.
        """

        try:
            payload = self._store.load()
        except StateStoreError as exc:
            self.recovery_error = str(exc)
            self._state_unrecoverable = True
            print(f"BOT STATE RECOVERY ERROR: {exc}", flush=True)
            return

        for raw_question in payload.get("questions", []):
            if not isinstance(raw_question, dict):
                continue
            try:
                question = OperatorQuestion.from_state(raw_question)
            except (KeyError, TypeError, ValueError):
                continue
            self.questions[question.id] = question

        for raw_run in payload.get("runs", []):
            if not isinstance(raw_run, dict):
                continue
            try:
                run = Run.from_state(raw_run, self.settings)
            except (KeyError, TypeError, ValueError) as exc:
                self.recovery_error = "a run could not be recovered; inspect durable state before creating a new run"
                self._state_unrecoverable = True
                print(f"BOT RUN RECOVERY ERROR: {exc}", flush=True)
                continue
            self.runs[run.id] = run

        if self._state_unrecoverable:
            return
        changed = False
        for run in self.runs.values():
            if run.status not in {"queued", "running", "retrying", "stopping"}:
                continue
            if self._remaining_seconds(run) <= 0:
                self._finish_at_deadline(run)
                run.finished_at = utc_now()
                changed = True
                continue
            current = pending_step(run.steps, run.checkpoint)
            can_reconcile = (current is not None and current[1].get("postcondition") is not None
                             and session_action(current[0]) not in {"login", "logout"})
            if not run.resume_on_restart or (
                not checkpoint_can_resume(run.steps, run.checkpoint) and not can_reconcile
            ):
                self._open_question(
                    run,
                    "restart_requires_confirmation",
                    "bot restarted with an uncertain command result; verify state before continuing",
                    {"scenario": run.scenario},
                )
                changed = True
                continue
            run.status = "queued"
            run.error = None
            self._schedule(run)
            changed = True

        if changed:
            self._persist()

    async def create(self, payload: dict[str, Any]) -> Run:
        if self.recovery_error:
            raise ValueError("bot state is degraded; restore durable state before creating a run")
        duration = float(payload.get("duration", 60))
        if duration <= 0 or duration > self.settings.max_run_seconds:
            raise ValueError(
                f"duration must be between 1 and {self.settings.max_run_seconds} seconds"
            )
        scenario = str(payload.get("scenario", "smoke"))
        steps = payload.get("steps")
        if steps is None:
            steps = default_steps(scenario)
        if not isinstance(steps, list) or any(not isinstance(step, dict) for step in steps):
            raise ValueError("steps must be an array of objects")
        repeat = bool(payload.get("repeat", scenario in {"queue-stress", "full"}))
        resumable = resumable_steps(steps)
        if payload.get("resumeOnRestart") is False:
            resumable = False

        run_id = uuid.uuid4().hex
        run = Run(
            run_id,
            scenario,
            duration,
            steps,
            repeat,
            JsonlLog(self.settings.log_dir / f"{run_id}.jsonl"),
            resumable,
            resume_on_restart=payload.get("resumeOnRestart") is not False,
        )
        async with self._lock:
            if any(item.status in ACTIVE_STATUSES for item in self.runs.values()):
                raise ValueError("another run is already active")
            self.runs[run_id] = run
            self._persist()
            self._schedule(run)
        return run

    async def _execute(self, run: Run) -> None:
        if run.started_at is None:
            run.started_at = utc_now()
            run.deadline_at = (
                datetime.now(timezone.utc) + timedelta(seconds=run.duration)
            ).isoformat()
            run.log.write(
                "run_started",
                run=run.public(),
                note="credentials are supplied only through environment variables",
            )
        else:
            run.log.write("run_resumed", run=run.public())

        run.status = "running"
        run.error = None
        self._persist()
        terminal = False
        try:
            while not run.stop_event.is_set():
                remaining = self._remaining_seconds(run)
                if remaining <= 0:
                    self._finish_at_deadline(run)
                    terminal = True
                    break

                client = GameClient(
                    self.settings.game_ws_url,
                    request_timeout=self.settings.request_timeout,
                    log=lambda event, fields: self._log(run, event, fields),
                    cabinet_url=self.settings.cabinet_api_url,
                    cabinet_email=self.settings.cabinet_email,
                    world_id=self.settings.game_world_id,
                    session_file=self.settings.state_file.parent / "sessions" / (run.id + ".json"),
                )
                try:
                    session = run.checkpoint.get("session", {})
                    if session.get("login", self.settings.game_login) != self.settings.game_login:
                        raise ScenarioError("checkpoint account differs from configured account; stop and use the correct bot")
                    await client.connect()
                    await client.login(self.settings.game_login, self.settings.game_password)
                    current = pending_step(run.steps, run.checkpoint)
                    pending_selection = (current is not None and current[1].get("pending")
                                         and session_action(current[0]) == "planetselect")
                    if session.get("planetId") is not None and not pending_selection:
                        selected = await client.request(
                            "overview", "planetselect", {"planetId": session["planetId"]}
                        )
                        if selected.get("status") != "ok":
                            raise ScenarioError("checkpoint planet is no longer available")

                    def save_checkpoint() -> None:
                        run.checkpoint["session"] = {
                            "planetId": client.selected_planet_id,
                            "login": client.authenticated_login,
                        }
                        self._persist()

                    await execute_steps(
                        client,
                        run.steps,
                        duration=remaining,
                        repeat=run.repeat,
                        stop_event=run.stop_event,
                        log=lambda event, fields: self._log(run, event, fields),
                        counters=run.counters,
                        checkpoint=run.checkpoint,
                        save_checkpoint=save_checkpoint,
                    )
                    if run.stop_event.is_set():
                        run.status = "stopped"
                    elif not run.checkpoint.get("done"):
                        self._finish_at_deadline(run)
                    else:
                        run.status = "completed"
                    terminal = True
                    break
                except asyncio.CancelledError:
                    raise
                except GameSessionEndedError as exc:
                    run.status = "stopped"
                    run.error = str(exc)
                    run.log.write("game_session_ended", note=str(exc))
                    terminal = True
                    break
                except GameAuthenticationError as exc:
                    outcome = await self._await_instruction(
                        run,
                        "authentication_failed",
                        str(exc),
                        {"scenario": run.scenario},
                    )
                    if outcome == "retry":
                        continue
                    run.status = "stopped"
                    terminal = True
                    break
                except (GameClientError, OSError) as exc:
                    current = pending_step(run.steps, run.checkpoint)
                    can_reconcile = (current is not None and current[1].get("postcondition") is not None
                                     and session_action(current[0]) not in {"login", "logout"})
                    if not run.resume_on_restart or (
                        not checkpoint_can_resume(run.steps, run.checkpoint) and not can_reconcile
                    ):
                        outcome = await self._await_instruction(
                            run,
                            "transport_failure_non_idempotent",
                            str(exc),
                            {"scenario": run.scenario},
                        )
                    else:
                        outcome = await self._retry_or_ask(run, exc)
                    if outcome == "retry":
                        continue
                    run.status = "stopped"
                    terminal = True
                    break
                except ScenarioError as exc:
                    outcome = await self._await_instruction(
                        run,
                        "scenario_decision_required",
                        str(exc),
                        {"scenario": run.scenario},
                    )
                    if outcome == "retry":
                        continue
                    run.status = "stopped"
                    terminal = True
                    break
                except StateStoreError as exc:
                    # Без durable-маркера нельзя выполнять следующий игровой шаг.
                    run.status = "waiting_for_instruction"
                    run.error = str(exc)
                    break
                except Exception as exc:
                    outcome = await self._await_instruction(
                        run,
                        "unexpected_bot_error",
                        str(exc),
                        {"type": type(exc).__name__, "scenario": run.scenario},
                    )
                    if outcome == "retry":
                        continue
                    run.status = "stopped"
                    terminal = True
                    break
                finally:
                    try:
                        await client.close()
                    except Exception as close_error:
                        await self._log(
                            run,
                            "client_close_error",
                            {"error": str(close_error), "errorType": type(close_error).__name__},
                        )
        except asyncio.CancelledError:
            if self._shutting_down and not run.stop_event.is_set():
                run.status = "running"
                run.error = None
                run.log.write("run_interrupted_for_restart", run=run.public())
            else:
                run.status = "stopped"
                terminal = True
            raise
        finally:
            if terminal or run.status in TERMINAL_STATUSES:
                run.finished_at = utc_now()
                terminal = True
            try:
                self._persist()
            except StateStoreError as exc:
                self.recovery_error = str(exc)
                print(f"BOT STATE SAVE ERROR: {exc}", flush=True)
            if terminal:
                try:
                    run.log.write("run_finished", run=run.public())
                except Exception:
                    pass

    async def _retry_or_ask(self, run: Run, error: Exception) -> str:
        run.retry_count += 1
        if run.retry_count >= max(1, self.settings.retry_before_question):
            return await self._await_instruction(
                run,
                "repeated_transport_failure",
                str(error),
                {"retryCount": run.retry_count, "scenario": run.scenario},
            )
        delay = min(
            max(0.1, self.settings.retry_initial_seconds) * (2 ** (run.retry_count - 1)),
            max(0.1, self.settings.retry_max_seconds),
        )
        run.status = "retrying"
        run.error = str(error)
        self._persist()
        await self._log(
            run,
            "run_retry_scheduled",
            {"retry": run.retry_count, "delaySeconds": delay, "error": str(error)},
        )
        try:
            await asyncio.wait_for(
                run.stop_event.wait(), timeout=min(delay, self._remaining_seconds(run))
            )
            return "stop"
        except asyncio.TimeoutError:
            run.status = "running"
            run.error = None
            self._persist()
            return "retry"

    async def _await_instruction(
        self,
        run: Run,
        code: str,
        message: str,
        context: dict[str, Any],
    ) -> str:
        question = self._open_question(run, code, message, context)
        run.pending_resolution = None
        run.resume_event.clear()
        self._persist()
        await self._log(run, "operator_question", {"question": question.public()})
        await run.resume_event.wait()
        if run.stop_event.is_set() or run.pending_resolution != "retry":
            return "stop"
        run.pending_resolution = None
        run.question_id = None
        run.status = "running"
        run.error = None
        self._persist()
        await self._log(run, "operator_retry_accepted", {"questionId": question.id})
        return "retry"

    async def _log(self, run: Run, event: str, fields: dict[str, Any]) -> None:
        if event == "game_session_ended":
            # Прерываем и длительное ожидание: до следующего запроса ждать не нужно.
            run.stop_event.set()
            if fields.get("action") != "gameLoggedOut":
                run.error = "Игровая сессия завершена; нужен новый запуск бота."
        run.log.write(event, **fields)

    async def stop(self, run_id: str) -> Run:
        run = self.runs.get(run_id)
        if run is None:
            raise KeyError(run_id)
        run.stop_event.set()
        run.resume_event.set()
        if run.task is None or run.task.done():
            run.status = "stopped"
            run.finished_at = utc_now()
        else:
            run.status = "stopping"
        self._persist()
        return run

    async def resolve_question(
        self, question_id: str, action: str, instruction: str | None = None
    ) -> OperatorQuestion:
        if action not in {"retry", "stop", "confirm_applied", "confirm_not_applied"}:
            raise ValueError("action must be retry, stop, confirm_applied or confirm_not_applied")
        question = self.questions.get(question_id)
        if question is None:
            raise KeyError(question_id)
        if question.status != "open":
            raise ValueError("question is already resolved")
        run = self.runs.get(question.run_id)
        if run is None:
            raise KeyError("run not found for question")
        if action != "stop" and run.checkpoint.get("legacy"):
            raise ValueError("legacy run has no checkpoint; stop it and create only remaining steps")
        if action in {"confirm_applied", "confirm_not_applied"}:
            current = pending_step(run.steps, run.checkpoint)
            if current is None or not current[1].get("pending") or resumable_steps([current[0]]):
                raise ValueError("confirmation requires a pending non-idempotent step")
            if not instruction or not instruction.strip():
                raise ValueError("confirmation requires an instruction describing verified state")
            leaf = current[1]
            if action == "confirm_applied":
                # Пропуская смену сессии, сохраняем именно её подтверждённый
                # результат, иначе новый client восстановит прежний контекст.
                step = current[0]
                changed_session = session_action(step)
                if changed_session == "logout":
                    raise ValueError("confirmed logout must stop the run; create a new run if needed")
                if changed_session in {"planetselect", "login"}:
                    context = ScenarioContext()
                    context.variables = leaf.get("context", {}).get("variables", {})
                    data = resolve_variables(step.get("data", {}), context)
                    session = run.checkpoint.setdefault("session", {})
                    if changed_session == "planetselect":
                        planet_id = resolve_variables(step.get("planetId", data.get("planetId")), context)
                        if type(planet_id) not in {int, str} or not str(planet_id).isdigit() or int(planet_id) <= 0:
                            raise ValueError("confirmed planetselect has no valid target planet")
                        session["planetId"] = int(planet_id)
                    else:
                        login = resolve_variables(step.get("login", data.get("login")), context)
                        if not isinstance(login, str) or not login:
                            raise ValueError("confirmed login has no valid account")
                        session["login"] = login
                leaf["index"] = leaf.get("index", 0) + 1
            for key in ("pending", "postcondition", "waitUntil"):
                leaf.pop(key, None)

        question.status = "resolved"
        question.resolution = action
        question.instruction = instruction
        question.resolved_at = utc_now()
        if action == "stop":
            run.stop_event.set()
            run.resume_event.set()
            run.status = "stopped"
            run.finished_at = utc_now()
        else:
            run.pending_resolution = "retry"
            # Решение должно пережить crash между fsync и resume_event.set().
            run.question_id = None
            run.status = "queued"
            run.error = None
        self._persist()
        if action != "stop":
            if run.task is None or run.task.done():
                self._schedule(run)
            else:
                run.resume_event.set()
        return question

    def get(self, run_id: str) -> Run | None:
        return self.runs.get(run_id)

    def list(self) -> list[dict[str, Any]]:
        return [run.public() for run in self.runs.values()]

    def get_question(self, question_id: str) -> OperatorQuestion | None:
        return self.questions.get(question_id)

    def list_questions(self, status: str | None = None) -> list[dict[str, Any]]:
        questions = self.questions.values()
        if status:
            questions = (question for question in questions if question.status == status)
        return [question.public() for question in questions]

    def health(self) -> dict[str, Any]:
        active = sum(1 for run in self.runs.values() if run.status in ACTIVE_STATUSES)
        open_questions = sum(1 for question in self.questions.values() if question.status == "open")
        return {
            "status": "degraded" if self.recovery_error else "ok",
            "activeRuns": active,
            "openQuestions": open_questions,
            "stateError": self.recovery_error,
        }

    async def shutdown(self) -> None:
        self._shutting_down = True
        tasks = [
            run.task
            for run in self.runs.values()
            if run.task is not None and not run.task.done()
        ]
        for task in tasks:
            task.cancel()
        if tasks:
            await asyncio.gather(*tasks, return_exceptions=True)
        try:
            self._persist()
        except StateStoreError as exc:
            self.recovery_error = str(exc)
            print(f"BOT STATE SAVE ERROR: {exc}", flush=True)
