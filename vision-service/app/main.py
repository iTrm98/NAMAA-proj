import logging
from contextlib import asynccontextmanager
from datetime import date
from typing import Literal

from fastapi import FastAPI, File, HTTPException, UploadFile
from pydantic import BaseModel, Field, field_validator, model_validator

from app.ndvi import DEFAULT_MAX_CLOUD, NdviError, compute_ndvi_detail
from app.waste import WasteConfig, WasteDetector, WasteError

log = logging.getLogger("uvicorn.error")
detector: WasteDetector | None = None


@asynccontextmanager
async def lifespan(_: FastAPI):
    """Load the YOLO model(s) once at startup. A failure keeps /health and /ndvi up; /waste answers 503."""
    global detector
    try:
        detector = WasteDetector(WasteConfig.from_env())
        log.info("waste model loaded: %s", detector.info())
    except Exception as e:  # noqa: BLE001 - a load error must not take the service down
        log.error("waste model not loaded: %s", e)
        detector = None
    yield


app = FastAPI(title="NAMAA AI Service", lifespan=lifespan)


@app.get("/health")
def health():
    return {"status": "ok", "waste": detector.info() if detector else None}


class GeoJsonPolygon(BaseModel):
    type: Literal["Polygon"]
    coordinates: list[list[list[float]]]  # [[[lng, lat], ...]] WGS84, first ring = outline

    @field_validator("coordinates")
    @classmethod
    def closed_ring(cls, rings: list[list[list[float]]]) -> list[list[list[float]]]:
        if not rings or len(rings[0]) < 4:
            raise ValueError("the outer ring needs at least 4 positions")
        for lng, lat, *_ in rings[0]:
            if not (-180 <= lng <= 180 and -90 <= lat <= 90):
                raise ValueError("positions must be [lng, lat] in WGS84")
        if rings[0][0][:2] != rings[0][-1][:2]:
            raise ValueError("the outer ring must be closed (first position = last position)")
        return rings


class NdviRequest(BaseModel):
    park_id: int | None = None
    polygon: GeoJsonPolygon
    date_from: date
    date_to: date
    max_cloud: float = Field(default=DEFAULT_MAX_CLOUD, ge=0, le=100)

    @model_validator(mode="after")
    def dates_in_order(self):
        if self.date_from > self.date_to:
            raise ValueError("date_from must not be after date_to")
        return self


@app.post("/ndvi")
def ndvi(request: NdviRequest):
    """Sentinel-2 NDVI per date for one park polygon (see app/ndvi.py)."""
    try:
        values, dates = compute_ndvi_detail(request.polygon.model_dump(), request.date_from, request.date_to, request.max_cloud)
    except NdviError as e:
        raise HTTPException(status_code=e.status, detail=e.message) from e

    return {"park_id": request.park_id, "values": values, "dates": dates}


@app.post("/waste")
def waste(image: UploadFile = File(...)):
    """Litter boxes (and person boxes, kept apart for blurring) in one JPEG/PNG photo."""
    if detector is None:
        raise HTTPException(status_code=503, detail="waste model not loaded")
    try:
        return detector.detect(image.file.read())
    except WasteError as e:
        raise HTTPException(status_code=e.status, detail=e.message) from e
