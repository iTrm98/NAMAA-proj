import logging
from contextlib import asynccontextmanager
from datetime import date
from typing import Literal

from fastapi import FastAPI, File, HTTPException, UploadFile
from pydantic import BaseModel, Field, field_validator, model_validator

from app import ndvi as ndvi_mod
from app.ndvi import DEFAULT_COMPOSITE_DAYS, DEFAULT_GREEN_THRESHOLD, DEFAULT_MAX_CLOUD, DEFAULT_SHRINK_M, NdviError
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


def _check_rings(rings: list[list[list[float]]]) -> list[list[list[float]]]:
    """First ring = outline, the others are holes; every ring must be closed and in WGS84."""
    if not rings:
        raise ValueError("a polygon needs at least an outer ring")
    for ring in rings:
        if len(ring) < 4:
            raise ValueError("every ring needs at least 4 positions")
        for lng, lat, *_ in ring:
            if not (-180 <= lng <= 180 and -90 <= lat <= 90):
                raise ValueError("positions must be [lng, lat] in WGS84")
        if ring[0][:2] != ring[-1][:2]:
            raise ValueError("every ring must be closed (first position = last position)")
    return rings


class GeoJsonPolygon(BaseModel):
    type: Literal["Polygon"]
    coordinates: list[list[list[float]]]  # [[[lng, lat], ...]] WGS84, first ring = outline, others = holes

    @field_validator("coordinates")
    @classmethod
    def closed_rings(cls, rings):
        return _check_rings(rings)


class GeoJsonMultiPolygon(BaseModel):
    type: Literal["MultiPolygon"]
    coordinates: list[list[list[list[float]]]]  # one Polygon's rings per part: all parts count, holes are excluded

    @field_validator("coordinates")
    @classmethod
    def closed_parts(cls, parts):
        if not parts:
            raise ValueError("a MultiPolygon needs at least one part")
        for rings in parts:
            _check_rings(rings)
        return parts


Geometry = GeoJsonPolygon | GeoJsonMultiPolygon


class NdviRequest(BaseModel):
    """v2: `boundary` (or the legacy name `polygon`) is required; `green_zone` is optional and,
    when given, is what gets measured. Without it the boundary is measured and flagged."""

    park_id: int | None = None
    boundary: Geometry | None = Field(default=None, discriminator="type")
    polygon: Geometry | None = Field(default=None, discriminator="type")  # legacy alias of boundary
    green_zone: Geometry | None = Field(default=None, discriminator="type")
    date_from: date
    date_to: date
    max_cloud: float = Field(default=DEFAULT_MAX_CLOUD, ge=0, le=100)
    shrink_m: float = Field(default=DEFAULT_SHRINK_M, ge=0, le=50)
    composite_days: int = Field(default=DEFAULT_COMPOSITE_DAYS, ge=1, le=120)
    green_threshold: float = Field(default=DEFAULT_GREEN_THRESHOLD, ge=-1, le=1)
    pixels: bool = False

    @model_validator(mode="after")
    def check(self):
        if self.date_from > self.date_to:
            raise ValueError("date_from must not be after date_to")
        if self.boundary is None and self.polygon is None:
            raise ValueError("boundary is required")
        return self


class SuggestZoneRequest(BaseModel):
    boundary: Geometry = Field(discriminator="type")
    date_to: date | None = None  # default: today
    days: int = Field(default=60, ge=7, le=180)
    ndvi_threshold: float = Field(default=DEFAULT_GREEN_THRESHOLD, ge=-1, le=1)
    min_clear_share: float = Field(default=0.5, gt=0, le=1)  # share of clear dates a pixel must be green on
    min_clear_dates: int = Field(default=1, ge=1, le=20)
    min_area_m2: float = Field(default=200, ge=0)  # islands and holes smaller than this are removed
    max_cloud: float = Field(default=DEFAULT_MAX_CLOUD, ge=0, le=100)


@app.post("/ndvi")
def ndvi(request: NdviRequest):
    """Sentinel-2 NDVI statistics per date and a 30-day composite for one park (see app/ndvi.py)."""
    boundary = (request.boundary or request.polygon).model_dump()
    green_zone = request.green_zone.model_dump() if request.green_zone else None
    try:
        result = ndvi_mod.compute_ndvi_v2(
            boundary, green_zone, request.date_from, request.date_to, request.max_cloud,
            request.shrink_m, request.composite_days, request.green_threshold, request.pixels,
        )
    except NdviError as e:
        raise HTTPException(status_code=e.status, detail=e.message) from e
    return {"park_id": request.park_id, **result}


@app.post("/ndvi/suggest-zone")
def ndvi_suggest_zone(request: SuggestZoneRequest):
    """Draft green zone: pixels inside the boundary that are green on most clear dates (a draft only)."""
    try:
        return ndvi_mod.suggest_zone(
            request.boundary.model_dump(), request.date_to or date.today(), request.days, request.ndvi_threshold,
            request.min_clear_share, request.min_clear_dates, request.min_area_m2, request.max_cloud,
        )
    except NdviError as e:
        raise HTTPException(status_code=e.status, detail=e.message) from e


@app.post("/waste")
def waste(image: UploadFile = File(...)):
    """Litter boxes (and person boxes, kept apart for blurring) in one JPEG/PNG photo."""
    if detector is None:
        raise HTTPException(status_code=503, detail="waste model not loaded")
    try:
        return detector.detect(image.file.read())
    except WasteError as e:
        raise HTTPException(status_code=e.status, detail=e.message) from e
