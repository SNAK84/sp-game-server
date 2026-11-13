from __future__ import annotations

import asyncio
import math
import time
from collections.abc import Awaitable, Callable
from typing import Any

from .ws_client import GameClient, GameClientError


class ScenarioError(RuntimeError):
    pass


LogFunction = Callable[[str, dict[str, Any]], Awaitable[None]]
DEFAULT_ENERGY_RECOVERY_ELEMENTS = (4,)


def next_energy_delta(build: dict[str, Any]) -> float:
    """Возвращает прогноз изменения энергии из актуального server payload.

    BuildingsPage уже рассчитывает ``Prod.Next`` с учётом текущих уровней,
    эффективности планеты и игровых коэффициентов. Повторно вычислять это на
    стороне тестора было бы менее надёжно, поэтому используем именно значение,
    которое видит клиент.
    """

    prod = build.get("Prod")
    if prod is None:
        return 0.0
    if not isinstance(prod, dict):
        raise ScenarioError("build_targets: invalid Prod payload")
    next_prod = prod.get("Next")
    if next_prod is None:
        return 0.0
    if not isinstance(next_prod, dict):
        raise ScenarioError("build_targets: invalid Prod.Next payload")
    value = next_prod.get("911", 0)
    try:
        return float(value)
    except (TypeError, ValueError) as exc:
        raise ScenarioError("build_targets: invalid energy forecast") from exc


def queue_contains_element(queue: dict[str, Any] | list[Any], element_id: int) -> bool:
    """Проверяет, что ACK build-команды отразился в очереди нужным элементом."""

    items = queue.values() if isinstance(queue, dict) else queue
    for item in items:
        if not isinstance(item, dict):
            continue
        try:
            if int(item.get("id", -1)) == element_id:
                return True
        except (TypeError, ValueError):
            continue
    return False


def response_page(response: dict[str, Any]) -> dict[str, Any]:
    """Возвращает Page из стандартного ActualData payload без догадок о mode."""

    data = response.get("data")
    if not isinstance(data, dict):
        return {}
    page = data.get("Page")
    return page if isinstance(page, dict) else {}


def response_queue_items(response: dict[str, Any]) -> list[dict[str, Any]]:
    """Нормализует QueueList страницы к списку queue rows."""

    queue = response_page(response).get("QueueList")
    values = queue.values() if isinstance(queue, dict) else queue
    if not isinstance(values, (list, tuple)) and not hasattr(values, "__iter__"):
        return []
    return [item for item in values if isinstance(item, dict)]


def queue_items_for_element(response: dict[str, Any], element_id: int) -> list[dict[str, Any]]:
    items: list[dict[str, Any]] = []
    for item in response_queue_items(response):
        try:
            if int(item.get("id", -1)) == element_id:
                items.append(item)
        except (TypeError, ValueError):
            continue
    return items


def response_element(response: dict[str, Any], element_id: int) -> dict[str, Any] | None:
    """Находит элемент в одном из фактических page payloads игры."""

    page = response_page(response)
    for key in ("BuildList", "ResearchList", "FleetList"):
        collection = page.get(key)
        if not isinstance(collection, dict):
            continue
        item = collection.get(str(element_id), collection.get(element_id))
        if isinstance(item, dict):
            return item
    return None


def queue_state_matches(
    response: dict[str, Any],
    *,
    element_id: int | None,
    queue_state: str | None,
    queue_status: str | None,
    min_level: int | None,
) -> tuple[bool, dict[str, Any]]:
    """Проверяет только наблюдаемое состояние страницы, не меняя игру."""

    page = response_page(response)
    element_items = (
        queue_items_for_element(response, element_id)
        if element_id is not None
        else response_queue_items(response)
    )
    items = (
        [item for item in element_items if item.get("status") == queue_status]
        if queue_status is not None
        else element_items
    )
    details: dict[str, Any] = {
        "page": page.get("page"),
        "queueCount": len(response_queue_items(response)),
        "elementQueueCount": len(element_items),
        "matchingQueueCount": len(items),
    }
    if items:
        item = items[0]
        details["queue"] = {
            key: item[key]
            for key in ("qid", "id", "status", "count", "level", "start_time", "end_time")
            if key in item
        }

    if queue_state == "present" and not items:
        return False, details
    if queue_state == "absent" and items:
        return False, details

    if min_level is not None:
        if element_id is None:
            return False, details
        element = response_element(response, element_id)
        if not isinstance(element, dict):
            return False, details
        try:
            current_level = int(element.get("level", element.get("count", -1)))
        except (TypeError, ValueError):
            return False, details
        details["elementLevel"] = current_level
        if current_level < min_level:
            return False, details

    return True, details


def response_matches_expectations(
    response: dict[str, Any],
    step: dict[str, Any],
) -> bool:
    """Небросающая проверка для фильтрации push-сообщений."""

    expected_status = step.get("expectStatus")
    if expected_status is not None and response.get("status") != expected_status:
        return False
    expected_mode = step.get("expectMode")
    if expected_mode is not None and response.get("mode") != expected_mode:
        return False
    expected_action = step.get("expectAction")
    if expected_action is not None and response.get("action") != expected_action:
        return False
    expected_page = step.get("expectPage")
    if expected_page is not None and response_page(response).get("page") != expected_page:
        return False
    return True


def assert_response_expectations(
    response: dict[str, Any],
    step: dict[str, Any],
    *,
    step_index: int,
) -> None:
    """Проверяет контракт обычного response и даёт точную причину падения."""

    expected_status = step.get("expectStatus")
    if expected_status is not None and response.get("status") != expected_status:
        raise ScenarioError(
            f"step {step_index}: expected status {expected_status!r}, "
            f"got {response.get('status')!r}"
        )
    expected_mode = step.get("expectMode")
    if expected_mode is not None and response.get("mode") != expected_mode:
        raise ScenarioError(
            f"step {step_index}: expected mode {expected_mode!r}, "
            f"got {response.get('mode')!r}"
        )
    expected_action = step.get("expectAction")
    if expected_action is not None and response.get("action") != expected_action:
        raise ScenarioError(
            f"step {step_index}: expected action {expected_action!r}, "
            f"got {response.get('action')!r}"
        )
    expected_page = step.get("expectPage")
    if expected_page is not None and response_page(response).get("page") != expected_page:
        raise ScenarioError(
            f"step {step_index}: expected page {expected_page!r}, "
            f"got {response_page(response).get('page')!r}"
        )
    if step.get("expectDataEmpty", False):
        data = response.get("data")
        if not isinstance(data, (dict, list)) or len(data) != 0:
            raise ScenarioError(f"step {step_index}: expected empty response data")
    if step.get("requireRequestId", False) and not response.get("requestId"):
        raise ScenarioError(f"step {step_index}: response has no requestId")


def resolve_variables(value: Any, context: "ScenarioContext") -> Any:
    """Подставляет ссылку вида ``$queueId`` из предыдущего проверенного шага."""

    if isinstance(value, str) and value.startswith("$"):
        key = value[1:]
        if key not in context.variables:
            raise ScenarioError(f"unknown scenario variable {key!r}")
        return context.variables[key]
    if isinstance(value, dict):
        return {key: resolve_variables(item, context) for key, item in value.items()}
    if isinstance(value, list):
        return [resolve_variables(item, context) for item in value]
    return value


def save_queue_id(
    context: "ScenarioContext", step: dict[str, Any], items: list[dict[str, Any]]
) -> None:
    variable = step.get("saveQueueIdAs")
    if variable is None:
        return
    if not isinstance(variable, str) or not variable:
        raise ScenarioError("saveQueueIdAs must be a non-empty string")
    if not items or "qid" not in items[0]:
        raise ScenarioError("cannot save queue id: matching queue item has no qid")
    context.variables[variable] = items[0]["qid"]


def response_resources(response: dict[str, Any]) -> dict[str, dict[str, Any]]:
    data = response.get("data")
    if not isinstance(data, dict):
        return {}
    top_nav = data.get("TopNav")
    if not isinstance(top_nav, dict):
        return {}
    resources = top_nav.get("Resources")
    return resources if isinstance(resources, dict) else {}


def resource_delta_since(
    baseline: dict[str, Any],
    current: dict[str, Any],
    resource_id: str,
) -> float:
    """Изменение ресурса сверх естественного накопления между двумя snapshots.

    Для проверки постановки/отмены очереди сравниваем не сырой счётчик, а
    фактическую разницу относительно production rate из исходного snapshot.
    """

    try:
        start = response_resources(baseline)[resource_id]
        end = response_resources(current)[resource_id]
        start_count = float(start["count"])
        end_count = float(end["count"])
        per_hour = float(start.get("perhour", 0))
        maximum = float(start.get("max", float("inf")))
        # ``count`` отражает состояние на resource.time, а не обязательно на
        # serverTime response. Обычный page snapshot может не делать lazy
        # update, тогда именно этот timestamp является корректной границей
        # для сравнения с мутацией очереди.
        start_time = float(start.get("time", baseline["serverTime"]))
        current_time = float(end.get("time", current["serverTime"]))
    except (KeyError, TypeError, ValueError) as exc:
        raise ScenarioError(
            f"resource snapshot is incomplete for resource {resource_id}"
        ) from exc

    elapsed = max(0.0, current_time - start_time)
    expected_without_command = min(maximum, start_count + per_hour * elapsed / 3600)
    return end_count - expected_without_command


def element_cost_from_snapshot(
    snapshot: dict[str, Any], element_id: int
) -> dict[str, float]:
    element = response_element(snapshot, element_id)
    if not isinstance(element, dict):
        raise ScenarioError(f"element {element_id} is absent from baseline snapshot")
    raw_cost = element.get("costResources")
    if not isinstance(raw_cost, dict) or not raw_cost:
        raise ScenarioError(f"element {element_id} has no costResources in baseline snapshot")
    try:
        return {str(resource): float(value) for resource, value in raw_cost.items()}
    except (TypeError, ValueError) as exc:
        raise ScenarioError(f"element {element_id} has invalid costResources") from exc


def resumable_steps(steps: list[dict[str, Any]]) -> bool:
    """True only for idempotent/read-only task descriptions."""

    safe_commands = {
        "page",
        "wait",
        "sleep",
        "reconnect",
        "build_targets",
        "capture_page",
        "expect_queue_state",
        "wait_for_queue_end",
    }
    for step in steps:
        if not isinstance(step, dict):
            return False
        command = str(step.get("command") or step.get("action") or "").strip()
        if command == "page" and str(step.get(
            "requestAction", step.get("pageAction", step.get("action", "ActualData"))
        )) not in {"", "ActualData"}:
            return False
        if command == "repeat":
            nested = step.get("steps")
            if not isinstance(nested, list) or not resumable_steps(nested):
                return False
        elif command not in safe_commands:
            return False
    return True


def prioritize_energy_recovery(
    order: list[int], energy_recovery_elements: set[int]
) -> list[int]:
    """Build energy recovery elements first when they are present in a target."""

    return [item for item in order if item in energy_recovery_elements] + [
        item for item in order if item not in energy_recovery_elements
    ]


def default_steps(scenario: str) -> list[dict[str, Any]]:
    if scenario in {"smoke", "read-only"}:
        return [
            {"command": "page", "mode": "overview"},
            {"command": "page", "mode": "buildings"},
            {"command": "page", "mode": "researchs"},
            {"command": "page", "mode": "shipyard"},
            {"command": "page", "mode": "defense"},
            {"command": "page", "mode": "fleets", "action": "ActualData"},
        ]
    return []


class ScenarioContext:
    def __init__(self) -> None:
        self.last_response: dict[str, Any] | None = None
        self.responses: list[dict[str, Any]] = []
        self.variables: dict[str, Any] = {}


def pending_step(
    steps: list[dict[str, Any]], checkpoint: dict[str, Any]
) -> tuple[dict[str, Any], dict[str, Any]] | None:
    """Находит только текущий лист, не повторяя завершённые ветви repeat."""
    index = checkpoint.get("index", 0)
    if checkpoint.get("done") or not isinstance(index, int) or not 0 <= index < len(steps):
        return None
    step = steps[index]
    if (step.get("command") or step.get("action")) == "repeat":
        return pending_step(step.get("steps", []), checkpoint.get("child", {}))
    return step, checkpoint


def checkpoint_can_resume(steps: list[dict[str, Any]], checkpoint: dict[str, Any]) -> bool:
    if checkpoint.get("legacy"):
        return False
    current = pending_step(steps, checkpoint)
    return current is None or not current[1].get("pending") or resumable_steps([current[0]])


def session_action(step: dict[str, Any]) -> str | None:
    command = step.get("command") or step.get("action")
    action = command
    if command in {"page", "request"}:
        action = step.get("requestAction", step.get("pageAction", step.get("action")))
    return action if action in {"planetselect", "login", "logout"} else None


def validate_checkpoint(steps: list[dict[str, Any]], checkpoint: dict[str, Any]) -> None:
    """Повреждённую позицию нельзя молча заменить началом сценария."""
    index = checkpoint.get("index", 0)
    if type(index) is not int or not 0 <= index <= len(steps):
        raise ValueError("checkpoint has an invalid step index")
    for key in ("pending", "done", "legacy"):
        if key in checkpoint and type(checkpoint[key]) is not bool:
            raise ValueError(f"checkpoint has invalid {key}")
    context = checkpoint.get("context", {})
    if not isinstance(context, dict) or not isinstance(context.get("variables", {}), dict):
        raise ValueError("checkpoint has invalid context")
    for key in ("cycle", "repeatIndex"):
        if key in checkpoint and (type(checkpoint[key]) is not int or checkpoint[key] < 0):
            raise ValueError(f"checkpoint has invalid {key}")
    if "waitUntil" in checkpoint and (
        type(checkpoint["waitUntil"]) not in {int, float}
        or not math.isfinite(checkpoint["waitUntil"])
    ):
        raise ValueError("checkpoint has invalid waitUntil")
    if "child" in checkpoint:
        if index >= len(steps) or (steps[index].get("command") or steps[index].get("action")) != "repeat":
            raise ValueError("checkpoint child does not belong to a repeat")
        if not isinstance(checkpoint["child"], dict) or not isinstance(steps[index].get("steps"), list):
            raise ValueError("checkpoint has invalid child")
        validate_checkpoint(steps[index]["steps"], checkpoint["child"])


async def check_postcondition(
    client: GameClient, condition: dict[str, Any]
) -> bool:
    """Только чтение ActualData; условие — точное значение либо наличие JSON-пути."""
    if not isinstance(condition, dict) or condition.get("mode") not in {
        "overview", "buildings", "researchs", "shipyard", "defense", "fleets", "galaxy"
    }:
        raise ScenarioError("postcondition requires a supported read-only page mode")
    path = condition.get("path")
    if not isinstance(path, list) or not path or any(
        not isinstance(key, (str, int)) or isinstance(key, bool) for key in path
    ):
        raise ScenarioError("postcondition.path must be a non-empty array of keys")
    if ("equals" in condition) == ("exists" in condition) or (
        "exists" in condition and not isinstance(condition["exists"], bool)
    ):
        raise ScenarioError("postcondition requires either equals or boolean exists")
    response = await client.request(condition["mode"], "ActualData", condition.get("data", {}))
    expected_page = "fleet" if condition["mode"] == "fleets" else condition["mode"]
    if response.get("status") != "ok" or response_page(response).get("page") != expected_page:
        raise ScenarioError("postcondition snapshot failed; command result is still unknown")
    value: Any = response
    found = True
    for key in path:
        if isinstance(value, dict) and str(key) in value:
            value = value[str(key)]
        elif isinstance(value, list) and isinstance(key, int) and 0 <= key < len(value):
            value = value[key]
        else:
            found = False
            break
    if "exists" in condition:
        return found == condition["exists"]
    return found and type(value) is type(condition["equals"]) and value == condition["equals"]


async def execute_steps(
    client: GameClient,
    steps: list[dict[str, Any]],
    *,
    duration: float,
    repeat: bool,
    stop_event: asyncio.Event,
    log: LogFunction,
    counters: dict[str, int],
    checkpoint: dict[str, Any] | None = None,
    save_checkpoint: Callable[[], None] | None = None,
    push_boundary: int | None = None,
) -> ScenarioContext:
    checkpoint = checkpoint if checkpoint is not None else {}
    if checkpoint.get("legacy"):
        raise ScenarioError("legacy run has no checkpoint; create only the remaining steps")
    context = ScenarioContext()
    saved = checkpoint.get("context", {})
    context.variables = dict(saved.get("variables", {}))
    context.last_response = saved.get("lastResponse")
    # Новый socket требует новой границы, вход в repeat на том же socket — нет.
    if saved.get("connectionId") != client.connection_id:
        context.variables["_last_push_checkpoint"] = (
            client.push_checkpoint() if push_boundary is None else push_boundary
        )
    deadline = time.monotonic() + duration

    def save() -> None:
        checkpoint["context"] = {
            "variables": context.variables,
            "lastResponse": context.last_response,
            "connectionId": client.connection_id,
        }
        if save_checkpoint is not None:
            save_checkpoint()

    while not checkpoint.get("done"):
        index = checkpoint.get("index", 0)
        if index >= len(steps):
            if not repeat or not steps:
                checkpoint["done"] = True
                save()
                break
            checkpoint["index"] = 0
            checkpoint["cycle"] = checkpoint.get("cycle", 0) + 1
            save()
            continue
        if stop_event.is_set() or time.monotonic() >= deadline:
            return context
        step = steps[index]
        command = str(step.get("command") or step.get("action") or "").strip()
        if command == "repeat":
            nested = step.get("steps")
            if not isinstance(nested, list):
                raise ScenarioError(f"step {index}: repeat.steps must be an array")
            count = max(1, int(step.get("count", 1)))
            while checkpoint.get("repeatIndex", 0) < count:
                child = checkpoint.setdefault("child", {})
                await execute_steps(
                    client, nested, duration=max(0, deadline - time.monotonic()),
                    repeat=False, stop_event=stop_event, log=log, counters=counters,
                    checkpoint=child, save_checkpoint=save,
                    push_boundary=context.variables.get("_last_push_checkpoint", client.push_checkpoint()),
                )
                if not child.get("done"):
                    return context
                checkpoint["repeatIndex"] = checkpoint.get("repeatIndex", 0) + 1
                checkpoint.pop("child", None)
                save()
        else:
            safe = resumable_steps([step])
            if checkpoint.get("pending") and not safe:
                if session_action(step) in {"login", "logout"}:
                    raise ScenarioError("ambiguous authentication step requires operator confirmation")
                condition = checkpoint.get("postcondition")
                if condition is None or not await check_postcondition(client, condition):
                    raise ScenarioError(
                        f"step {index}: ambiguous command result; verify state and use "
                        "confirm_applied/confirm_not_applied or stop; retry will not resend"
                    )
                await log("step_reconciled", {"step": index, "command": command})
            else:
                if not checkpoint.get("pending"):
                    condition = resolve_variables(step.get("postcondition"), context)
                    if condition is not None:
                        if await check_postcondition(client, condition):
                            raise ScenarioError(
                                f"step {index}: postcondition already matches before command"
                            )
                        checkpoint["postcondition"] = condition
                    if command in {"wait", "sleep"}:
                        seconds = max(0.0, float(step.get("seconds", step.get("duration", 1))))
                        checkpoint["waitUntil"] = time.time() + seconds
                    checkpoint["pending"] = True
                    save()  # До любого возможного побочного эффекта на сервере.
                executing = step
                if "waitUntil" in checkpoint:
                    executing = {**step, "seconds": max(0, checkpoint["waitUntil"] - time.time())}
                await execute_step(
                    client, executing, context=context, step_index=index,
                    deadline=deadline, stop_event=stop_event, log=log, counters=counters,
                    step_checkpoint=checkpoint, save_checkpoint=save,
                )
                if stop_event.is_set() or (
                    command in {"wait", "sleep"} and time.time() < checkpoint["waitUntil"]
                ) or (command == "build_targets" and time.monotonic() >= deadline):
                    return context
        # Следующий индекс и контекст фиксируются одним atomic checkpoint.
        checkpoint["index"] = index + 1
        for key in ("pending", "postcondition", "waitUntil", "repeatIndex", "child"):
            checkpoint.pop(key, None)
        save()
    return context


async def execute_step(
    client: GameClient,
    step: dict[str, Any],
    *,
    context: ScenarioContext,
    step_index: int,
    deadline: float,
    stop_event: asyncio.Event,
    log: LogFunction,
    counters: dict[str, int],
    step_checkpoint: dict[str, Any] | None = None,
    save_checkpoint: Callable[[], None] | None = None,
) -> None:
    command = str(step.get("command") or step.get("action") or "").strip()
    if not command:
        raise ScenarioError(f"step {step_index}: command is required")

    await log("step_start", {"step": step_index, "command": command})
    counters["commands"] = counters.get("commands", 0) + 1

    if command in {"sleep", "wait"}:
        seconds = max(0.0, float(step.get("seconds", step.get("duration", 1))))
        remaining = max(0.0, deadline - time.monotonic())
        if remaining:
            try:
                await asyncio.wait_for(stop_event.wait(), timeout=min(seconds, remaining))
            except asyncio.TimeoutError:
                pass
        return

    if command == "repeat":
        count = max(1, int(step.get("count", 1)))
        nested = step.get("steps")
        if not isinstance(nested, list):
            raise ScenarioError(f"step {step_index}: repeat.steps must be an array")
        for _ in range(count):
            if stop_event.is_set() or time.monotonic() >= deadline:
                return
            await execute_steps(
                client,
                nested,
                duration=max(0, deadline - time.monotonic()),
                repeat=False,
                stop_event=stop_event,
                log=log,
                counters=counters,
            )
        return

    if command == "reconnect":
        await client.close()
        await client.connect()
        context.variables["_last_push_checkpoint"] = client.push_checkpoint()
        await log("step_ok", {"step": step_index, "command": command})
        return

    if command == "login":
        login = str(step.get("login", ""))
        password = str(step.get("password", ""))
        await client.login(login, password)
        await log("step_ok", {"step": step_index, "command": command})
        return

    if command == "wait_for_push":
        raw_element_id = resolve_variables(step.get("elementId"), context)
        raw_min_level = resolve_variables(step.get("minLevel"), context)
        try:
            element_id = None if raw_element_id is None else int(raw_element_id)
            min_level = None if raw_min_level is None else int(raw_min_level)
        except (TypeError, ValueError) as exc:
            raise ScenarioError(
                f"step {step_index}: elementId and minLevel must be integers"
            ) from exc

        queue_state = step.get("queueState")
        if queue_state not in {None, "present", "absent"}:
            raise ScenarioError(
                f"step {step_index}: queueState must be 'present' or 'absent'"
            )
        queue_status = step.get("queueStatus")
        if queue_status is not None and (
            not isinstance(queue_status, str) or not queue_status
        ):
            raise ScenarioError(f"step {step_index}: queueStatus must be a non-empty string")
        try:
            after = int(
                resolve_variables(
                    step.get(
                        "afterPushCheckpoint",
                        context.variables.get("_last_push_checkpoint", 0),
                    ),
                    context,
                )
            )
            timeout_seconds = max(0.1, float(step.get("timeoutSeconds", 30)))
        except (TypeError, ValueError) as exc:
            raise ScenarioError(
                f"step {step_index}: invalid push checkpoint or timeout"
            ) from exc

        def matches(response: dict[str, Any]) -> bool:
            if not response_matches_expectations(response, step):
                return False
            matched, _ = queue_state_matches(
                response,
                element_id=element_id,
                queue_state=queue_state,
                queue_status=queue_status,
                min_level=min_level,
            )
            return matched

        remaining = max(0.0, deadline - time.monotonic())
        if remaining <= 0:
            raise ScenarioError(f"step {step_index}: run deadline reached before push")
        try:
            sequence, response = await client.wait_for_unsolicited(
                matches,
                after=after,
                timeout=min(timeout_seconds, remaining),
            )
        except GameClientError as exc:
            raise ScenarioError(
                f"step {step_index}: expected server push was not observed: {exc}"
            ) from exc
        context.last_response = response
        context.responses.append(response)
        items = (
            queue_items_for_element(response, element_id)
            if element_id is not None
            else response_queue_items(response)
        )
        if queue_status is not None:
            items = [item for item in items if item.get("status") == queue_status]
        save_queue_id(context, step, items)
        _, details = queue_state_matches(
            response,
            element_id=element_id,
            queue_state=queue_state,
            queue_status=queue_status,
            min_level=min_level,
        )
        counters["pushes"] = counters.get("pushes", 0) + 1
        await log(
            "push_matched",
            {
                "step": step_index,
                "command": command,
                "sequence": sequence,
                "afterPushCheckpoint": after,
                "status": response.get("status"),
                "requestId": response.get("requestId"),
                "serverTime": response.get("serverTime"),
                "details": details,
            },
        )
        return

    if command == "expect_queue_state":
        mode = str(step.get("mode", "buildings"))
        raw_element_id = resolve_variables(step.get("elementId"), context)
        raw_min_level = resolve_variables(step.get("minLevel"), context)
        try:
            element_id = None if raw_element_id is None else int(raw_element_id)
            min_level = None if raw_min_level is None else int(raw_min_level)
        except (TypeError, ValueError) as exc:
            raise ScenarioError(
                f"step {step_index}: elementId and minLevel must be integers"
            ) from exc
        queue_state = step.get("queueState")
        if queue_state not in {None, "present", "absent"}:
            raise ScenarioError(
                f"step {step_index}: queueState must be 'present' or 'absent'"
            )
        queue_status = step.get("queueStatus")
        if queue_status is not None and (
            not isinstance(queue_status, str) or not queue_status
        ):
            raise ScenarioError(f"step {step_index}: queueStatus must be a non-empty string")

        checkpoint = client.push_checkpoint()
        response = await client.request(mode, "ActualData")
        context.variables["_last_push_checkpoint"] = checkpoint
        context.last_response = response
        context.responses.append(response)
        counters["responses"] = counters.get("responses", 0) + 1
        if response.get("status") == "error":
            counters["errors"] = counters.get("errors", 0) + 1
        assert_response_expectations(response, step, step_index=step_index)
        matched, details = queue_state_matches(
            response,
            element_id=element_id,
            queue_state=queue_state,
            queue_status=queue_status,
            min_level=min_level,
        )
        if not matched:
            raise ScenarioError(
                f"step {step_index}: queue/page state did not match expectation: {details}"
            )
        items = (
            queue_items_for_element(response, element_id)
            if element_id is not None
            else response_queue_items(response)
        )
        if queue_status is not None:
            items = [item for item in items if item.get("status") == queue_status]
        save_queue_id(context, step, items)
        await log(
            "queue_state_matched",
            {
                "step": step_index,
                "command": command,
                "mode": mode,
                "requestId": response.get("requestId"),
                "serverTime": response.get("serverTime"),
                "details": details,
            },
        )
        return

    if command == "wait_for_queue_end":
        mode = str(step.get("mode", "buildings"))
        raw_element_id = resolve_variables(step.get("elementId"), context)
        try:
            element_id = int(raw_element_id)
            grace_seconds = max(0.0, float(step.get("graceSeconds", 2)))
        except (TypeError, ValueError) as exc:
            raise ScenarioError(
                f"step {step_index}: elementId or graceSeconds is invalid"
            ) from exc
        queue_status = step.get("queueStatus")
        if queue_status is not None and (
            not isinstance(queue_status, str) or not queue_status
        ):
            raise ScenarioError(f"step {step_index}: queueStatus must be a non-empty string")

        checkpoint = client.push_checkpoint()
        response = await client.request(mode, "ActualData")
        context.variables["_last_push_checkpoint"] = checkpoint
        context.last_response = response
        context.responses.append(response)
        counters["responses"] = counters.get("responses", 0) + 1
        if response.get("status") != "ok":
            counters["errors"] = counters.get("errors", 0) + 1
            raise ScenarioError(
                f"step {step_index}: queue end snapshot failed: "
                f"{response.get('error', {}).get('code', 'unknown')}"
            )
        assert_response_expectations(response, step, step_index=step_index)
        items = queue_items_for_element(response, element_id)
        if queue_status is not None:
            items = [item for item in items if item.get("status") == queue_status]
        if not items:
            raise ScenarioError(
                f"step {step_index}: no matching queue item for element {element_id}"
            )
        try:
            end_time = min(float(item["end_time"]) for item in items)
            server_time = float(response["serverTime"])
        except (KeyError, TypeError, ValueError) as exc:
            raise ScenarioError(
                f"step {step_index}: queue item has invalid end_time/serverTime"
            ) from exc
        wait_seconds = max(0.0, end_time - server_time + grace_seconds)
        remaining = max(0.0, deadline - time.monotonic())
        if wait_seconds > remaining:
            raise ScenarioError(
                f"step {step_index}: queue ends in {wait_seconds:.1f}s, "
                f"but only {remaining:.1f}s remain in run"
            )
        await log(
            "queue_end_wait",
            {
                "step": step_index,
                "command": command,
                "mode": mode,
                "elementId": element_id,
                "queueStatus": queue_status,
                "endTime": end_time,
                "serverTime": server_time,
                "waitSeconds": wait_seconds,
            },
        )
        if wait_seconds:
            try:
                await asyncio.wait_for(stop_event.wait(), timeout=wait_seconds)
            except asyncio.TimeoutError:
                pass
        return

    if command == "capture_page":
        variable = step.get("saveAs")
        if not isinstance(variable, str) or not variable:
            raise ScenarioError(f"step {step_index}: capture_page.saveAs is required")
        mode = str(step.get("mode", "buildings"))
        checkpoint = client.push_checkpoint()
        response = await client.request(mode, "ActualData")
        context.variables["_last_push_checkpoint"] = checkpoint
        context.last_response = response
        context.responses.append(response)
        counters["responses"] = counters.get("responses", 0) + 1
        if response.get("status") == "error":
            counters["errors"] = counters.get("errors", 0) + 1
        if response.get("status") != "ok":
            raise ScenarioError(
                f"step {step_index}: snapshot failed: "
                f"{response.get('error', {}).get('code', 'unknown')}"
            )
        assert_response_expectations(response, step, step_index=step_index)
        if not response_page(response) or not response_resources(response):
            raise ScenarioError(f"step {step_index}: snapshot has no page/resources")
        context.variables[variable] = response
        await log(
            "page_captured",
            {
                "step": step_index,
                "command": command,
                "variable": variable,
                "mode": mode,
                "requestId": response.get("requestId"),
                "serverTime": response.get("serverTime"),
                "page": response_page(response).get("page"),
            },
        )
        return

    if command == "assert_resources_unchanged":
        baseline = resolve_variables(step.get("baseline"), context)
        if not isinstance(baseline, dict):
            raise ScenarioError(f"step {step_index}: baseline must reference a snapshot")
        raw_resource_ids = resolve_variables(
            step.get("resourceIds", ["901", "902", "903"]), context
        )
        if not isinstance(raw_resource_ids, list) or not raw_resource_ids:
            raise ScenarioError(f"step {step_index}: resourceIds must be a non-empty array")
        try:
            resource_ids = [str(int(resource_id)) for resource_id in raw_resource_ids]
            tolerance = max(0.0, float(step.get("tolerance", 2.0)))
        except (TypeError, ValueError) as exc:
            raise ScenarioError(
                f"step {step_index}: resourceIds or tolerance is invalid"
            ) from exc

        mode = str(step.get("mode", "buildings"))
        checkpoint = client.push_checkpoint()
        response = await client.request(mode, "ActualData")
        context.variables["_last_push_checkpoint"] = checkpoint
        context.last_response = response
        context.responses.append(response)
        counters["responses"] = counters.get("responses", 0) + 1
        if response.get("status") != "ok":
            counters["errors"] = counters.get("errors", 0) + 1
            raise ScenarioError(
                f"step {step_index}: unchanged-resource snapshot failed: "
                f"{response.get('error', {}).get('code', 'unknown')}"
            )
        assert_response_expectations(response, step, step_index=step_index)

        details: dict[str, Any] = {}
        for resource_id in resource_ids:
            net_delta = resource_delta_since(baseline, response, resource_id)
            details[resource_id] = {"netDelta": net_delta, "tolerance": tolerance}
            if abs(net_delta) > tolerance:
                raise ScenarioError(
                    f"step {step_index}: resource {resource_id} changed unexpectedly: "
                    f"{net_delta}"
                )
        await log(
            "resource_unchanged_assertion_matched",
            {
                "step": step_index,
                "command": command,
                "mode": mode,
                "requestId": response.get("requestId"),
                "serverTime": response.get("serverTime"),
                "resources": details,
            },
        )
        return

    if command in {"assert_resource_cost", "assert_resource_refund"}:
        baseline_key = "baseline" if command == "assert_resource_cost" else "afterBuild"
        cost_key = "baseline" if command == "assert_resource_cost" else "costFrom"
        baseline = resolve_variables(step.get(baseline_key), context)
        cost_snapshot = resolve_variables(step.get(cost_key), context)
        if not isinstance(baseline, dict) or not isinstance(cost_snapshot, dict):
            raise ScenarioError(
                f"step {step_index}: {baseline_key} and {cost_key} must reference snapshots"
            )
        raw_element_id = resolve_variables(step.get("elementId"), context)
        try:
            element_id = int(raw_element_id)
            tolerance = max(0.0, float(step.get("tolerance", 2.0)))
        except (TypeError, ValueError) as exc:
            raise ScenarioError(
                f"step {step_index}: elementId and tolerance are invalid"
            ) from exc
        cost = element_cost_from_snapshot(cost_snapshot, element_id)
        if command == "assert_resource_refund":
            try:
                minimum_fraction = float(step.get("minRefundFraction", 0.5))
            except (TypeError, ValueError) as exc:
                raise ScenarioError(
                    f"step {step_index}: minRefundFraction is invalid"
                ) from exc
            if not 0 <= minimum_fraction <= 1:
                raise ScenarioError(
                    f"step {step_index}: minRefundFraction must be from 0 to 1"
                )
        else:
            minimum_fraction = 0.0

        mode = str(step.get("mode", "buildings"))
        checkpoint = client.push_checkpoint()
        response = await client.request(mode, "ActualData")
        context.variables["_last_push_checkpoint"] = checkpoint
        context.last_response = response
        context.responses.append(response)
        counters["responses"] = counters.get("responses", 0) + 1
        if response.get("status") == "error":
            counters["errors"] = counters.get("errors", 0) + 1
        if response.get("status") != "ok":
            raise ScenarioError(
                f"step {step_index}: resource assertion snapshot failed: "
                f"{response.get('error', {}).get('code', 'unknown')}"
            )
        assert_response_expectations(response, step, step_index=step_index)

        details: dict[str, Any] = {}
        for resource_id, expected_cost in cost.items():
            net_delta = resource_delta_since(baseline, response, resource_id)
            if command == "assert_resource_cost":
                observed_spend = -net_delta
                details[resource_id] = {
                    "expectedSpend": expected_cost,
                    "observedSpend": observed_spend,
                    "tolerance": tolerance,
                }
                if abs(observed_spend - expected_cost) > tolerance:
                    raise ScenarioError(
                        f"step {step_index}: resource {resource_id} spend mismatch: "
                        f"expected {expected_cost}, observed {observed_spend}"
                    )
            else:
                minimum_refund = expected_cost * minimum_fraction
                details[resource_id] = {
                    "minimumRefund": minimum_refund,
                    "observedRefund": net_delta,
                    "tolerance": tolerance,
                }
                if net_delta + tolerance < minimum_refund:
                    raise ScenarioError(
                        f"step {step_index}: resource {resource_id} refund too small: "
                        f"required at least {minimum_refund}, observed {net_delta}"
                    )

        await log(
            "resource_assertion_matched",
            {
                "step": step_index,
                "command": command,
                "mode": mode,
                "elementId": element_id,
                "requestId": response.get("requestId"),
                "serverTime": response.get("serverTime"),
                "resources": details,
            },
        )
        return

    if command == "build_targets":
        step_checkpoint = step_checkpoint if step_checkpoint is not None else {}

        def save_build_pending(pending: dict[str, Any] | None) -> None:
            if pending is None:
                step_checkpoint.pop("buildPending", None)
            else:
                step_checkpoint["buildPending"] = pending
            if save_checkpoint is not None:
                save_checkpoint()

        raw_targets = step.get("targets")
        if not isinstance(raw_targets, dict) or not raw_targets:
            raise ScenarioError(
                f"step {step_index}: build_targets.targets must be a non-empty object"
            )

        targets = {int(element): int(level) for element, level in raw_targets.items()}
        raw_order = step.get("order")
        order = [int(element) for element in raw_order] if raw_order else list(targets)
        raw_energy_recovery = step.get(
            "energyRecoveryElements", DEFAULT_ENERGY_RECOVERY_ELEMENTS
        )
        if not isinstance(raw_energy_recovery, (list, tuple, set)):
            raise ScenarioError(
                f"step {step_index}: energyRecoveryElements must be an array"
            )
        energy_recovery_elements = {int(element) for element in raw_energy_recovery}
        order = prioritize_energy_recovery(order, energy_recovery_elements)
        poll_seconds = max(1.0, float(step.get("pollSeconds", 15)))
        min_energy = float(step.get("minEnergy", 0))
        ack_timeout_seconds = max(
            1.0,
            float(step.get("ackTimeoutSeconds", max(60.0, poll_seconds * 2))),
        )

        async def wait_poll() -> None:
            remaining = max(0.0, deadline - time.monotonic())
            if remaining <= 0:
                return
            try:
                await asyncio.wait_for(stop_event.wait(), timeout=min(poll_seconds, remaining))
            except asyncio.TimeoutError:
                pass

        async def read_buildings() -> tuple[dict[str, Any], dict[str, Any], dict[str, Any]]:
            response = await client.request("buildings", "ActualData")
            if response.get("status") != "ok":
                raise ScenarioError(
                    "build_targets: buildings snapshot failed: "
                    f"{response.get('error', {}).get('code', 'unknown')}"
                )
            data = response.get("data")
            if not isinstance(data, dict):
                raise ScenarioError("build_targets: buildings snapshot has no data")
            page = data.get("Page")
            top_nav = data.get("TopNav")
            if not isinstance(page, dict) or not isinstance(top_nav, dict):
                raise ScenarioError("build_targets: incomplete buildings snapshot")
            builds = page.get("BuildList")
            queue = page.get("QueueList")
            resources = top_nav.get("Resources")
            if not isinstance(builds, dict) or not isinstance(queue, (dict, list)):
                raise ScenarioError("build_targets: incomplete queue/build data")
            if not isinstance(resources, dict):
                resources = {}
            return builds, queue, resources

        for element_id in order:
            if element_id not in targets:
                raise ScenarioError(
                    f"step {step_index}: element {element_id} is missing from targets"
                )

            target_level = targets[element_id]
            pending_command = step_checkpoint.get("buildPending")
            if pending_command is not None and pending_command["elementId"] != element_id:
                pending_command = None
            while not stop_event.is_set() and time.monotonic() < deadline:
                builds, queue, resources = await read_buildings()
                build = builds.get(str(element_id))
                if not isinstance(build, dict):
                    raise ScenarioError(f"build_targets: unknown element {element_id}")

                current_level = int(build.get("level", 0))
                if current_level >= target_level:
                    if pending_command is not None:
                        save_build_pending(None)
                    await log(
                        "build_target_reached",
                        {
                            "elementId": element_id,
                            "level": current_level,
                            "target": target_level,
                        },
                    )
                    break

                if pending_command is not None:
                    queue_confirmed = queue_contains_element(queue, element_id)
                    level_confirmed = current_level > pending_command["fromLevel"]
                    if queue_confirmed or level_confirmed:
                        await log(
                            "build_target_ack_confirmed",
                            {
                                "elementId": element_id,
                                "requestId": pending_command["requestId"],
                                "queueConfirmed": queue_confirmed,
                                "levelConfirmed": level_confirmed,
                            },
                        )
                        pending_command = None
                        save_build_pending(None)
                    else:
                        pending_for = time.time() - pending_command["sentAt"]
                        if pending_for >= ack_timeout_seconds:
                            raise ScenarioError(
                                "build_targets: build ACK was not reflected in queue/state "
                                f"within {ack_timeout_seconds:.0f}s for element {element_id}"
                            )
                        await log(
                            "build_target_ack_wait",
                            {
                                "elementId": element_id,
                                "requestId": pending_command["requestId"],
                                "waitedSeconds": pending_for,
                            },
                        )
                        await wait_poll()
                        continue

                energy = float(resources.get("911", {}).get("count", 0))
                if energy < min_energy and element_id not in energy_recovery_elements:
                    raise ScenarioError(
                        f"build_targets: energy floor breached before element "
                        f"{element_id}: {energy} < {min_energy}"
                    )

                if energy < min_energy:
                    await log(
                        "build_energy_recovery",
                        {
                            "elementId": element_id,
                            "energy": energy,
                            "minEnergy": min_energy,
                        },
                    )

                if queue:
                    await wait_poll()
                    continue

                overflow = build.get("costOverflow", {})
                missing = {
                    str(resource): value
                    for resource, value in overflow.items()
                    if float(value) > 0
                }
                if missing:
                    await log(
                        "build_wait_resources",
                        {
                            "elementId": element_id,
                            "currentLevel": current_level,
                            "targetLevel": target_level,
                            "missing": missing,
                        },
                    )
                    await wait_poll()
                    continue

                energy_delta = next_energy_delta(build)
                projected_energy = energy + energy_delta
                if element_id in energy_recovery_elements and energy_delta <= 0:
                    raise ScenarioError(
                        "build_targets: configured energy recovery element has no "
                        f"positive energy forecast: {element_id} ({energy_delta})"
                    )
                if (
                    element_id not in energy_recovery_elements
                    and projected_energy < min_energy
                ):
                    raise ScenarioError(
                        "build_targets: projected energy floor would be breached by "
                        f"element {element_id}: {energy} + {energy_delta} = "
                        f"{projected_energy} < {min_energy}"
                    )

                pending_command = {
                    "elementId": element_id,
                    "fromLevel": current_level,
                    "requestId": None,
                    "sentAt": time.time(),
                }
                save_build_pending(pending_command)
                response = await client.request(
                    "buildings",
                    "build",
                    {"id": element_id},
                )
                counters["responses"] = counters.get("responses", 0) + 1
                if response.get("status") == "error":
                    counters["errors"] = counters.get("errors", 0) + 1
                    raise ScenarioError(
                        f"build_targets: build {element_id} failed: "
                        f"{response.get('error', {}).get('code', 'unknown')}"
                    )

                await log(
                    "build_target_command",
                    {
                        "elementId": element_id,
                        "fromLevel": current_level,
                        "toLevel": current_level + 1,
                        "requestId": response.get("requestId"),
                        "serverTime": response.get("serverTime"),
                        "energyBefore": energy,
                        "energyDelta": energy_delta,
                        "projectedEnergy": projected_energy,
                    },
                )
                pending_command["requestId"] = response.get("requestId")
                save_build_pending(pending_command)
                await wait_poll()

            if stop_event.is_set() or time.monotonic() >= deadline:
                return

        await log("step_ok", {"step": step_index, "command": command})
        return

    push_checkpoint = client.push_checkpoint()

    if command == "page":
        mode = str(step.get("mode", "overview"))
        action = str(
            step.get(
                "requestAction",
                step.get("pageAction", step.get("action", "ActualData")),
            )
        )
        response = await client.request(
            mode,
            action,
            dict(resolve_variables(step.get("data", {}), context)),
        )
    elif command in {"build", "dismantle", "research", "shipyard", "defense"}:
        mode = {
            "build": "buildings",
            "dismantle": "buildings",
            "research": "researchs",
            "shipyard": "shipyard",
            "defense": "defense",
        }[command]
        action = "dismantle" if command == "dismantle" else "build"
        data = dict(resolve_variables(step.get("data", {}), context))
        if "elementId" in step:
            data.setdefault("id", resolve_variables(step["elementId"], context))
        if "items" in step:
            data.setdefault("items", resolve_variables(step["items"], context))
        response = await client.request(mode, action, data)
    elif command == "cancel":
        mode = str(step.get("mode", "buildings"))
        response = await client.request(
            mode,
            "cancel",
            {
                "id": resolve_variables(
                    step.get("queueId", step.get("id")), context
                )
            },
        )
    elif command == "planetselect":
        response = await client.request(
            str(step.get("mode", "overview")),
            "planetselect",
            {"planetId": resolve_variables(step.get("planetId"), context)},
        )
    elif command == "request":
        response = await client.request(
            str(step.get("mode", "")),
            str(
                step.get(
                    "requestAction",
                    step.get("pageAction", step.get("action", "")),
                )
            ),
            dict(resolve_variables(step.get("data", {}), context)),
        )
    else:
        raise ScenarioError(f"step {step_index}: unsupported command {command!r}")

    context.last_response = response
    context.responses.append(response)
    context.variables["_last_push_checkpoint"] = push_checkpoint
    status = response.get("status")
    counters["responses"] = counters.get("responses", 0) + 1
    if status == "error":
        counters["errors"] = counters.get("errors", 0) + 1

    assert_response_expectations(response, step, step_index=step_index)
    if step.get("failOnError", False) and status == "error":
        raise ScenarioError(
            f"step {step_index}: server error "
            f"{response.get('error', {}).get('code', 'unknown')}"
        )

    await log(
        "step_response",
        {
            "step": step_index,
            "command": command,
            "status": status,
            "requestId": response.get("requestId"),
            "serverTime": response.get("serverTime"),
        },
    )
