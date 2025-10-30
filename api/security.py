from __future__ import annotations

from itsdangerous import BadSignature, SignatureExpired, URLSafeTimedSerializer
from passlib.context import CryptContext

from .config import load_config

pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")
_config = load_config()
_serializer = URLSafeTimedSerializer(_config.secret_key, salt="schedule-session")


def hash_password(password: str) -> str:
    return pwd_context.hash(password)


def verify_password(password: str, password_hash: str) -> bool:
    return pwd_context.verify(password, password_hash)


def create_session_token(user_id: int, token_version: int) -> str:
    payload = {"user_id": user_id, "token_version": token_version}
    return _serializer.dumps(payload)


def decode_session_token(token: str) -> dict | None:
    try:
        return _serializer.loads(token, max_age=_config.session_max_age)
    except (BadSignature, SignatureExpired):
        return None
