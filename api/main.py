from __future__ import annotations

from pathlib import Path

from fastapi import APIRouter, FastAPI
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles

from .routers import auth, people, permissions, schedule, shifts, teams

app = FastAPI(title="排班系统 API")

api_router = APIRouter(prefix="/api")
api_router.include_router(auth.router)
api_router.include_router(teams.router)
api_router.include_router(shifts.router)
api_router.include_router(people.router)
api_router.include_router(schedule.router)
api_router.include_router(permissions.router)


@app.get("/api/health")
async def health_check():
    return {"status": "ok"}


app.include_router(api_router)


FRONTEND_DIR = Path(__file__).resolve().parent.parent / "public"

if FRONTEND_DIR.exists():
    app.mount("/public", StaticFiles(directory=str(FRONTEND_DIR), html=True), name="public")


@app.get("/")
async def serve_index():
    index_file = FRONTEND_DIR / "index.html"
    if not index_file.exists():
        return {"message": "frontend not built"}
    return FileResponse(index_file)
