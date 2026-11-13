from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def _int_env(name: str, default: int) -> int:
    value = os.getenv(name)
    if value is None or value == "":
        return default
    return int(value)


@dataclass(frozen=True)
class Settings:
    api_host: str = os.getenv("BOT_API_HOST", "0.0.0.0")
    api_port: int = _int_env("BOT_API_PORT", 8095)
    game_ws_url: str = os.getenv("GAME_WS_URL", "ws://host.docker.internal:9501/")
    game_login: str = os.getenv("SP_GAME_LOGIN", "")
    game_password: str = os.getenv("SP_GAME_PASSWORD", "")
    cabinet_api_url: str = os.getenv("CABINET_API_URL", "https://cabinet.sp-game.ru")
    cabinet_email: str = os.getenv("SP_GAME_CABINET_EMAIL", "")
    game_world_id: str = os.getenv("GAME_WORLD_ID", "sp-game-dev")
    api_token: str = os.getenv("BOT_API_TOKEN", "change-me")
    request_timeout: float = float(os.getenv("BOT_REQUEST_TIMEOUT", "15"))
    max_run_seconds: int = _int_env("BOT_MAX_RUN_SECONDS", 3600)
    log_dir: Path = Path(os.getenv("BOT_LOG_DIR", "/app/data/runs"))
    state_file: Path = Path(os.getenv("BOT_STATE_FILE", "/app/data/state.json"))
    retry_initial_seconds: float = float(os.getenv("BOT_RETRY_INITIAL_SECONDS", "5"))
    retry_max_seconds: float = float(os.getenv("BOT_RETRY_MAX_SECONDS", "300"))
    retry_before_question: int = _int_env("BOT_RETRY_BEFORE_QUESTION", 5)
