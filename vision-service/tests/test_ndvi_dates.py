"""NDVI date selection with fakes: no network (_search and _item_value are patched)."""

from datetime import date

from fastapi.testclient import TestClient

from app import ndvi
from app.main import app

POLY = {"type": "Polygon", "coordinates": [[[39.8, 21.4], [39.81, 21.4], [39.81, 21.41], [39.8, 21.4]]]}
RANGE = (date(2026, 11, 1), date(2026, 11, 12))


class Item:
    def __init__(self, day):
        self.day = day


def run(monkeypatch, values, max_cloud=80):
    seen = {}

    def fake_search(polygon, d_from, d_to, cloud):
        seen["cloud"] = cloud
        return [Item(v.date + str(i)) for i, v in enumerate(values)]

    by_item = {v.date + str(i): v for i, v in enumerate(values)}
    monkeypatch.setattr(ndvi, "_search", fake_search)
    monkeypatch.setattr(ndvi, "_item_value", lambda item, geom: by_item[item.day])
    return ndvi.compute_ndvi_detail(POLY, *RANGE, max_cloud), seen


def test_cloudy_tile_with_a_clear_park_is_kept(monkeypatch):
    (values, dates), seen = run(monkeypatch, [ndvi.DateValue("2026-11-11", 0.312, 412)])
    assert values == [{"date": "2026-11-11", "mean": 0.312, "pixels": 412}]
    assert dates == [{"date": "2026-11-11", "status": "kept", "mean": 0.312, "pixels": 412}]
    assert seen["cloud"] == 80


def test_a_park_under_cloud_is_dropped_for_that_date_only(monkeypatch):
    (values, dates), _ = run(monkeypatch, [
        ndvi.DateValue("2026-11-06", 0.2, 4),
        ndvi.DateValue("2026-11-11", 0.3, 50),
        ndvi.DateValue("2026-11-09", 0.0, 0, reason="outside_outline"),
        ndvi.DateValue("2026-11-08", 0.0, 0, reason="no_data"),
    ])
    assert [v["date"] for v in values] == ["2026-11-11"]
    by = {d["date"]: d for d in dates}
    assert by["2026-11-06"] == {"date": "2026-11-06", "status": "dropped", "reason": "too_few_pixels", "pixels": 4}
    assert by["2026-11-09"]["reason"] == "outside_outline"
    assert by["2026-11-08"]["reason"] == "no_data"
    assert by["2026-11-11"]["status"] == "kept"
    assert [d["date"] for d in dates] == sorted(by)


def test_search_query_uses_max_cloud(monkeypatch):
    captured = {}

    class FakeClient:
        def search(self, **kw):
            captured.update(kw)
            return type("S", (), {"items": lambda self: []})()

    monkeypatch.setattr(ndvi.Client, "open", lambda *a, **k: FakeClient())
    ndvi._search(POLY, *RANGE, 55)
    assert captured["query"] == {"eo:cloud_cover": {"lt": 55}}


def test_endpoint_returns_values_and_dates_and_defaults_to_80(monkeypatch):
    seen = {}

    def fake(polygon, d_from, d_to, max_cloud):
        seen["cloud"] = max_cloud
        return [{"date": "2026-11-11", "mean": 0.3, "pixels": 40}], [{"date": "2026-11-11", "status": "kept", "mean": 0.3, "pixels": 40}]

    monkeypatch.setattr("app.main.compute_ndvi_detail", fake)
    body = {"polygon": POLY, "date_from": "2026-11-01", "date_to": "2026-11-12"}
    r = TestClient(app).post("/ndvi", json=body)
    assert r.status_code == 200
    assert r.json()["values"][0]["pixels"] == 40 and r.json()["dates"][0]["status"] == "kept"
    assert seen["cloud"] == 80
    assert TestClient(app).post("/ndvi", json={**body, "max_cloud": 101}).status_code == 422
