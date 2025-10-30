from __future__ import annotations

from contextlib import contextmanager
from pathlib import Path
from typing import Generator

from sqlalchemy import create_engine
from sqlalchemy.orm import DeclarativeBase, sessionmaker

from .config import load_config


class Base(DeclarativeBase):
    """Base class for ORM models."""



def _build_engine(database_path: Path):
    if database_path.parent and not database_path.parent.exists():
        database_path.parent.mkdir(parents=True, exist_ok=True)
    engine_url = f"sqlite:///{database_path}"
    # using synchronous sqlite; disable thread check for FastAPI background usage
    return create_engine(engine_url, connect_args={"check_same_thread": False})


_config = load_config()
engine = _build_engine(_config.database_path)
SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)


def get_session() -> Generator:
    session = SessionLocal()
    try:
        yield session
    finally:
        session.close()


@contextmanager
def session_scope() -> Generator:
    session = SessionLocal()
    try:
        yield session
        session.commit()
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()
