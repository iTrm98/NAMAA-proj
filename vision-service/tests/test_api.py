"""Vision service tests. Run inside the container: docker compose exec vision pytest -q

No network: /ndvi is only checked for its validation (no STAC call), /waste
runs the real YOLO model on generated images and the Ultralytics sample photo.
"""

import io
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from PIL import Image, ImageDraw

from app.main import app


@pytest.fixture(scope="module")
def client():
    with TestClient(app) as c:  # runs the lifespan: loads the model once
        yield c


def jpeg(width=640, height=480, color=(60, 140, 60)) -> bytes:
    """A generated 'lawn' with a few shapes on it."""
    image = Image.new("RGB", (width, height), color)
    draw = ImageDraw.Draw(image)
    draw.rectangle([100, 200, 140, 300], fill=(230, 230, 240))
    draw.ellipse([400, 300, 460, 340], fill=(200, 40, 40))
    out = io.BytesIO()
    image.save(out, "JPEG")
    return out.getvalue()


def test_health_is_ok_and_reports_the_model(client):
    body = client.get("/health").json()
    assert body["status"] == "ok"
    assert body["waste"]["model"].endswith(".pt")
    assert "bottle" in body["waste"]["classes"] or body["waste"]["custom"]


def test_waste_returns_the_schema(client):
    r = client.post("/waste", files={"image": ("lawn.jpg", jpeg(), "image/jpeg")})
    assert r.status_code == 200, r.text
    body = r.json()
    assert set(body) == {"items", "count", "people", "model", "inference_ms", "image_w", "image_h"}
    assert body["count"] == len(body["items"])
    assert (body["image_w"], body["image_h"]) == (640, 480)
    assert isinstance(body["inference_ms"], int)
    for item in body["items"]:
        assert set(item) == {"label", "confidence", "box"}
        assert len(item["box"]) == 4


def test_png_is_accepted(client):
    out = io.BytesIO()
    Image.new("RGB", (320, 240), (80, 160, 80)).save(out, "PNG")
    r = client.post("/waste", files={"image": ("lawn.png", out.getvalue(), "image/png")})
    assert r.status_code == 200
    assert r.json()["image_w"] == 320


def test_people_are_returned_apart_and_not_as_litter():
    sample = Path(__import__("ultralytics").__file__).parent / "assets" / "bus.jpg"
    if not sample.exists():
        pytest.skip("ultralytics sample photo not shipped")
    with TestClient(app) as c:
        body = c.post("/waste", files={"image": ("bus.jpg", sample.read_bytes(), "image/jpeg")}).json()
    assert len(body["people"]) >= 2, body
    assert all(set(p) == {"confidence", "box"} for p in body["people"])
    assert all(i["label"] != "person" for i in body["items"])


@pytest.mark.parametrize("payload", [b"not an image", b""])
def test_bad_file_is_422(client, payload):
    r = client.post("/waste", files={"image": ("x.jpg", payload, "image/jpeg")})
    assert r.status_code == 422


def test_gif_is_422(client):
    out = io.BytesIO()
    Image.new("RGB", (10, 10)).save(out, "GIF")
    assert client.post("/waste", files={"image": ("x.gif", out.getvalue(), "image/gif")}).status_code == 422


def test_too_large_is_422(client):
    big = jpeg() + b"\0" * (5 * 1024 * 1024)
    assert client.post("/waste", files={"image": ("big.jpg", big, "image/jpeg")}).status_code == 422


def test_missing_image_field_is_422(client):
    assert client.post("/waste").status_code == 422


def test_ndvi_validation_unchanged(client):
    open_ring = {"type": "Polygon", "coordinates": [[[39.8, 21.4], [39.81, 21.4], [39.81, 21.41], [39.8, 21.41]]]}
    r = client.post("/ndvi", json={"polygon": open_ring, "date_from": "2026-11-01", "date_to": "2026-11-05"})
    assert r.status_code == 422
    closed = {"type": "Polygon", "coordinates": [open_ring["coordinates"][0] + [[39.8, 21.4]]]}
    r = client.post("/ndvi", json={"polygon": closed, "date_from": "2026-11-05", "date_to": "2026-11-01"})
    assert r.status_code == 422


def test_ndvi_accepts_a_multipolygon_with_holes(client, monkeypatch):
    seen = {}

    def fake(boundary, green_zone, *args):
        seen["polygon"] = boundary
        return {"values": []}

    monkeypatch.setattr("app.ndvi.compute_ndvi_v2", fake)
    outer = [[39.80, 21.40], [39.82, 21.40], [39.82, 21.42], [39.80, 21.42], [39.80, 21.40]]
    hole = [[39.805, 21.405], [39.81, 21.405], [39.81, 21.41], [39.805, 21.41], [39.805, 21.405]]
    part2 = [[39.90, 21.40], [39.91, 21.40], [39.91, 21.41], [39.90, 21.41], [39.90, 21.40]]
    body = {"type": "MultiPolygon", "coordinates": [[outer, hole], [part2]]}
    r = client.post("/ndvi", json={"polygon": body, "date_from": "2026-11-01", "date_to": "2026-11-05"})
    assert r.status_code == 200
    assert seen["polygon"]["type"] == "MultiPolygon"
    assert len(seen["polygon"]["coordinates"]) == 2 and len(seen["polygon"]["coordinates"][0]) == 2

    bad = {"type": "MultiPolygon", "coordinates": [[outer[:-1]]]}
    r = client.post("/ndvi", json={"polygon": bad, "date_from": "2026-11-01", "date_to": "2026-11-05"})
    assert r.status_code == 422


def test_ndvi_geometry_handles_multipolygon_holes():
    from shapely.geometry import shape

    outer = [[0, 0], [4, 0], [4, 4], [0, 4], [0, 0]]
    hole = [[1, 1], [2, 1], [2, 2], [1, 2], [1, 1]]
    part2 = [[10, 0], [11, 0], [11, 1], [10, 1], [10, 0]]
    g = shape({"type": "MultiPolygon", "coordinates": [[outer, hole], [part2]]})
    assert g.area == 16 - 1 + 1


def test_default_confidence_follows_the_model(tmp_path, monkeypatch):
    from app.waste import WasteConfig, DEFAULT_CONFIDENCE_COCO, DEFAULT_CONFIDENCE_CUSTOM

    weights = tmp_path / "litter.pt"
    weights.write_bytes(b"x")  # only its existence matters here
    monkeypatch.delenv("WASTE_CONFIDENCE", raising=False)
    monkeypatch.setenv("WASTE_MODEL", str(weights))
    assert WasteConfig.from_env().confidence == DEFAULT_CONFIDENCE_CUSTOM == 0.1775
    monkeypatch.setenv("WASTE_MODEL", str(tmp_path / "missing.pt"))
    assert WasteConfig.from_env().confidence == DEFAULT_CONFIDENCE_COCO == 0.35
    monkeypatch.setenv("WASTE_MODEL", str(weights))
    monkeypatch.setenv("WASTE_CONFIDENCE", "0.039")
    assert WasteConfig.from_env().confidence == 0.039


BUS = Path(__import__("ultralytics").__file__).parent / "assets" / "bus.jpg"
CUSTOM = Path(__file__).resolve().parent.parent / "models" / "litter.pt"


def _body(monkeypatch, model_env):
    monkeypatch.setenv("WASTE_MODEL", model_env)
    with TestClient(app) as c:
        health = c.get("/health").json()["waste"]
        body = c.post("/waste", files={"image": ("bus.jpg", BUS.read_bytes(), "image/jpeg")}).json()
    return health, body


def test_people_still_returned_with_the_custom_model(monkeypatch):
    if not (BUS.exists() and CUSTOM.exists()):
        pytest.skip("needs the ultralytics sample photo and models/litter.pt")
    health, body = _body(monkeypatch, str(CUSTOM))
    assert health["custom"] is True and health["classes"] == ["litter"]
    assert len(body["people"]) >= 2, body  # COCO person pass runs next to litter.pt
    assert body["model"] == "litter.pt"
    assert all(i["label"] == "litter" for i in body["items"])


def test_people_and_coco_litter_fallback_without_the_custom_model(monkeypatch, tmp_path):
    if not BUS.exists():
        pytest.skip("ultralytics sample photo not shipped")
    health, body = _body(monkeypatch, str(tmp_path / "missing.pt"))
    assert health["custom"] is False and "bottle" in health["classes"]
    assert health["confidence"] == 0.35
    assert len(body["people"]) >= 2, body
    assert body["model"] != "litter.pt"
    assert all(i["label"] != "person" and i["label"] != "litter" for i in body["items"])
