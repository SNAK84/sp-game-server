from __future__ import annotations

import json

from aiohttp import web

from .config import Settings
from .runner import RunManager


def authorized(request: web.Request, settings: Settings) -> bool:
    if not settings.api_token:
        return True
    supplied = request.headers.get("X-Bot-Token", "")
    if not supplied:
        supplied = request.headers.get("Authorization", "").removeprefix("Bearer ").strip()
    return supplied == settings.api_token


def require_auth(request: web.Request) -> None:
    settings: Settings = request.app["settings"]
    if not authorized(request, settings):
        raise web.HTTPUnauthorized(text="invalid bot token")


async def health(request: web.Request) -> web.Response:
    require_auth(request)
    settings: Settings = request.app["settings"]
    manager: RunManager = request.app["manager"]
    payload = {
        "service": "sp-game-test-bot",
        "gameWsUrl": settings.game_ws_url,
        **manager.health(),
    }
    return web.json_response(payload, status=503 if payload["status"] == "degraded" else 200)


async def create_run(request: web.Request) -> web.Response:
    require_auth(request)
    try:
        payload = await request.json()
        if not isinstance(payload, dict):
            raise ValueError("request body must be a JSON object")
        run = await request.app["manager"].create(payload)
    except json.JSONDecodeError as exc:
        raise web.HTTPBadRequest(text=f"invalid JSON: {exc}") from exc
    except ValueError as exc:
        raise web.HTTPBadRequest(text=str(exc)) from exc
    return web.json_response(run.public(), status=202)


async def list_runs(request: web.Request) -> web.Response:
    require_auth(request)
    return web.json_response({"runs": request.app["manager"].list()})


async def get_run(request: web.Request) -> web.Response:
    require_auth(request)
    run = request.app["manager"].get(request.match_info["run_id"])
    if run is None:
        raise web.HTTPNotFound(text="run not found")
    return web.json_response(run.public())


async def stop_run(request: web.Request) -> web.Response:
    require_auth(request)
    try:
        run = await request.app["manager"].stop(request.match_info["run_id"])
    except KeyError as exc:
        raise web.HTTPNotFound(text="run not found") from exc
    return web.json_response(run.public())


async def get_logs(request: web.Request) -> web.Response:
    require_auth(request)
    run = request.app["manager"].get(request.match_info["run_id"])
    if run is None:
        raise web.HTTPNotFound(text="run not found")
    if not run.log.path.exists():
        return web.Response(text="", content_type="application/x-ndjson")
    return web.Response(
        text=run.log.path.read_text(encoding="utf-8"),
        content_type="application/x-ndjson",
    )


async def list_questions(request: web.Request) -> web.Response:
    require_auth(request)
    status = request.query.get("status")
    return web.json_response({"questions": request.app["manager"].list_questions(status)})


async def get_question(request: web.Request) -> web.Response:
    require_auth(request)
    question = request.app["manager"].get_question(request.match_info["question_id"])
    if question is None:
        raise web.HTTPNotFound(text="question not found")
    return web.json_response(question.public())


async def resolve_question(request: web.Request) -> web.Response:
    require_auth(request)
    try:
        payload = await request.json()
        if not isinstance(payload, dict):
            raise ValueError("request body must be a JSON object")
        action = str(payload.get("action", ""))
        instruction = payload.get("instruction")
        if instruction is not None:
            instruction = str(instruction)
        question = await request.app["manager"].resolve_question(
            request.match_info["question_id"], action, instruction
        )
    except json.JSONDecodeError as exc:
        raise web.HTTPBadRequest(text=f"invalid JSON: {exc}") from exc
    except ValueError as exc:
        raise web.HTTPBadRequest(text=str(exc)) from exc
    except KeyError as exc:
        raise web.HTTPNotFound(text=str(exc)) from exc
    return web.json_response(question.public())


async def on_startup(app: web.Application) -> None:
    app["settings"].log_dir.mkdir(parents=True, exist_ok=True)
    await app["manager"].restore()


async def on_cleanup(app: web.Application) -> None:
    await app["manager"].shutdown()


def create_app() -> web.Application:
    settings = Settings()
    app = web.Application()
    app["settings"] = settings
    app["manager"] = RunManager(settings)
    app.router.add_get("/health", health)
    app.router.add_post("/runs", create_run)
    app.router.add_get("/runs", list_runs)
    app.router.add_get("/runs/{run_id}", get_run)
    app.router.add_post("/runs/{run_id}/stop", stop_run)
    app.router.add_get("/runs/{run_id}/logs", get_logs)
    app.router.add_get("/questions", list_questions)
    app.router.add_get("/questions/{question_id}", get_question)
    app.router.add_post("/questions/{question_id}/resolve", resolve_question)
    app.on_startup.append(on_startup)
    app.on_cleanup.append(on_cleanup)
    return app


if __name__ == "__main__":
    settings = Settings()
    web.run_app(create_app(), host=settings.api_host, port=settings.api_port)
