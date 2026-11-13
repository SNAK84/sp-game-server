from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any


class StateStoreError(RuntimeError):
    """Невозможно безопасно загрузить или сохранить состояние задач бота."""


class StateStore:
    """Небольшое durable-хранилище состояния run и операторских вопросов."""

    VERSION = 2

    def __init__(self, path: Path) -> None:
        self.path = path

    def load(self) -> dict[str, Any]:
        if not self.path.exists():
            return {"version": self.VERSION, "runs": [], "questions": []}
        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise StateStoreError(f"cannot read bot state {self.path}: {exc}") from exc
        if not isinstance(payload, dict):
            raise StateStoreError(f"bot state {self.path} must be a JSON object")
        if payload.get("version", 1) not in {1, self.VERSION}:
            raise StateStoreError(f"bot state {self.path} has an unsupported version")
        if not isinstance(payload.get("runs", []), list):
            raise StateStoreError(f"bot state {self.path} has invalid runs")
        if not isinstance(payload.get("questions", []), list):
            raise StateStoreError(f"bot state {self.path} has invalid questions")
        return payload

    def save(self, payload: dict[str, Any]) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.path.with_name(f".{self.path.name}.tmp")
        try:
            with temporary.open("w", encoding="utf-8") as stream:
                stream.write(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temporary, self.path)
            directory = os.open(self.path.parent, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
        except OSError as exc:
            raise StateStoreError(f"cannot save bot state {self.path}: {exc}") from exc
