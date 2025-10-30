from __future__ import annotations

from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any, Dict

import tomllib

DEFAULT_CONFIG_PATH = Path("config/app.toml")


@dataclass
class AppConfig:
    database_path: Path
    secret_key: str
    session_max_age: int = 7 * 24 * 60 * 60  # one week by default


def _coerce_path(base: Path, value: str) -> Path:
    path = Path(value)
    if not path.is_absolute():
        return (base / path).resolve()
    return path


def _load_config_dict(path: Path) -> Dict[str, Any]:
    if not path.exists():
        raise FileNotFoundError(
            f"Configuration file not found: {path}. Please run bin/install.sh or create it manually."
        )
    with path.open("rb") as fp:
        return tomllib.load(fp)


@lru_cache(maxsize=1)
def load_config(path: Path | None = None) -> AppConfig:
    config_path = path or DEFAULT_CONFIG_PATH
    raw = _load_config_dict(config_path)
    base_dir = config_path.parent.parent.resolve()
    database_path = _coerce_path(base_dir, raw.get("database_path", "data/app.db"))
    secret_key = raw.get("secret_key")
    if not secret_key:
        raise ValueError("Configuration secret_key must be provided in config/app.toml")
    session_max_age = int(raw.get("session_max_age", 7 * 24 * 60 * 60))
    return AppConfig(database_path=database_path, secret_key=secret_key, session_max_age=session_max_age)
