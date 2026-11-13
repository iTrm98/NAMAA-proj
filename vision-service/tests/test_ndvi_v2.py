"""NDVI v2 with synthetic rasters: no network (STAC search and imagery reads are patched)."""

from datetime import date

import numpy as np
from fastapi.testclient import TestClient
from shapely.geometry import MultiPolygon, Polygon, box, mapping

from app import ndvi
from app.main import app

CAL = (0.0001, -0.1, 0)
# Makkah, UTM 37N: parks are built in metres, converted to WGS84 for the API.
X0, Y0 = 600000.0, 2370000.0


def wgs(geom_utm) -> dict:
    return ndvi._round_geom(mapping(ndvi.to_wgs(geom_utm)))


def sq(x, y, w, h):
    return box(X0 + x, Y0 + y, X0 + x + w, Y0 + y + h)


def grid_for(geom):
    return ndvi.Grid.around(geom)


# ---- coverage fractions, holes, shrink ---------------------------------------------------


def test_fractions_of_a_zone_with_half_pixels():
    zone = sq(0, 0, 25, 20)  # 2.5 x 2 pixels
    cov = ndvi.coverage_grid(zone, grid_for(zone))
    assert cov.sum() == 5.0
    assert (cov == 1).sum() == 4 and (cov == 0.5).sum() == 2


def test_exactextract_and_shapely_fallback_agree():
    zone = sq(3, 4, 47, 33).difference(sq(15, 15, 12, 9))
    g = grid_for(zone)
    assert np.allclose(ndvi.coverage_grid(zone, g), ndvi._coverage_shapely(zone, g), atol=1e-6)


def test_holes_are_excluded_and_multipolygon_parts_counted():
    outer = sq(0, 0, 100, 100)
    hole = sq(20, 20, 30, 30)
    zone = MultiPolygon([Polygon(outer.exterior, [hole.exterior]), sq(200, 0, 40, 40)])
    cov = ndvi.coverage_grid(zone, grid_for(zone))
    assert round(cov.sum()) == 100 - 9 + 16


def test_shrink_is_inward_5m_in_utm():
    z = ndvi.prepare_zone(wgs(sq(0, 0, 100, 100)), None, 5)
    assert abs(z["shrunk"].area - 90 * 90) < 5
    assert z["measured_on"] == "boundary" and z["flags"] == ["boundary_fallback"]
    z2 = ndvi.prepare_zone(wgs(sq(0, 0, 100, 100)), wgs(sq(10, 10, 50, 50)), 5)
    assert z2["measured_on"] == "green_zone" and z2["flags"] == []
    assert abs(z2["zone"].area - 2500) < 5


def test_pure_pixels_after_shrink():
    z = ndvi.prepare_zone(wgs(sq(0, 0, 100, 100)), None, 5)
    cov = ndvi.coverage_grid(z["shrunk"], grid_for(z["zone"]))
    assert abs(cov.sum() - 81) < 0.5
    assert (cov >= ndvi.PURE_COVERAGE).sum() == 64  # 8 x 8 interior pixels; the rim is half covered


def test_tiny_polygon_is_empty_after_shrink(monkeypatch):
    def no_network(*a, **k):
        raise AssertionError("no network")

    monkeypatch.setattr(ndvi, "_search", no_network)
    out = ndvi.compute_ndvi_v2(wgs(sq(0, 0, 8, 8)), None, date(2026, 10, 1), date(2026, 11, 10))
    assert out["geometry"]["pure_pixels"] == 0 and "empty_after_shrink" in out["flags"]
    assert out["values"] == [] and out["composite"] is None


def test_strip_has_small_inner_width_and_no_pure_pixels():
    z = ndvi.prepare_zone(wgs(sq(0, 0, 200, 12)), None, 5)
    assert 11 <= z["inner_width_m"] <= 12.5
    cov = ndvi.coverage_grid(z["shrunk"], grid_for(z["zone"]))
    assert (cov >= ndvi.PURE_COVERAGE).sum() == 0 and cov.sum() > 0
    wide = ndvi.prepare_zone(wgs(sq(0, 0, 100, 100)), None, 5)
    assert 99 <= wide["inner_width_m"] <= 100.5


# ---- pixels -> NDVI: masks, class 5, offset ------------------------------------------------


def dn(reflectance):  # reflectance -> DN with offset -0.1, scale 0.0001
    return int(round((reflectance + 0.1) / 0.0001))


def test_scl_class_5_is_kept_and_others_masked():
    red = np.full((1, 12), dn(0.1))
    nir = np.full((1, 12), dn(0.4))
    scl = np.arange(12).reshape(1, 12)
    out = ndvi.band_to_ndvi(red, nir, scl, CAL, CAL)
    masked = {0, 1, 3, 8, 9, 10, 11}
    for c in range(12):
        assert bool(np.isnan(out[0, c])) == (c in masked), c
    assert not np.isnan(out[0, 5])
    assert abs(out[0, 4] - 0.6) < 1e-3


def test_offset_cases():
    red, nir, scl = np.array([[dn(0.1)]]), np.array([[dn(0.4)]]), np.array([[4]])
    assert abs(ndvi.band_to_ndvi(red, nir, scl, CAL, CAL)[0, 0] - 0.6) < 1e-3
    # without the offset the same DNs give a different (wrong) value
    assert abs(ndvi.band_to_ndvi(red, nir, scl, (0.0001, 0.0, 0), (0.0001, 0.0, 0))[0, 0] - 0.6) > 0.05

    class Asset:
        def __init__(self, extra):
            self.extra_fields = extra

    assert ndvi._scale_offset(Asset({}))[:2] == (0.0001, -0.1)  # not in metadata: baseline default
    assert ndvi._scale_offset(Asset({"raster:bands": [{"scale": 0.0001, "offset": -0.1, "nodata": 0}]}))[:2] == (0.0001, -0.1)
    assert ndvi._scale_offset(Asset({"raster:scale": 0.0002, "raster:offset": -0.2}))[:2] == (0.0002, -0.2)
    assert ndvi._scale_offset(Asset({"raster:bands": [{"scale": 0.0001, "offset": -0.1}]}), boa_offset_applied=True)[:2] == (0.0001, 0.0)


# ---- statistics ----------------------------------------------------------------------------


def test_weighted_stats_and_green_cover():
    cov = np.array([[1.0, 1.0, 0.5, 0.5]])
    v = np.array([[0.1, 0.3, 0.5, 0.9]])
    s = ndvi.grid_stats(v, cov, usual_pixels=3.0, green_threshold=0.2)
    assert s["pure_pixels"] == 2 and s["clear_pixels"] == 3.0 and s["clear_fraction"] == 1.0
    assert s["mean"] == round((0.1 + 0.3 + 0.25 + 0.45) / 3, 3)
    assert s["green_cover_pct"] == 50.0  # only the two pure pixels count: 0.1 no, 0.3 yes
    assert s["p90"] > s["median"]


def test_half_the_zone_clouded_halves_clear_fraction():
    cov = np.ones((4, 4))
    v = np.full((4, 4), 0.4)
    v[:, :2] = np.nan
    s = ndvi.grid_stats(v, cov, 16.0, 0.2)
    assert s["clear_fraction"] == 0.5 and s["clear_pixels"] == 8.0 and s["pure_pixels"] == 8
    assert ndvi.grid_stats(np.full((4, 4), np.nan), cov, 16.0, 0.2) is None


def test_composite_is_a_per_pixel_median():
    a = np.array([[0.1, np.nan]])
    b = np.array([[0.5, 0.2]])
    c = np.array([[0.3, 0.4]])
    out = ndvi.composite_median([a, b, c])
    assert out[0, 0] == 0.3 and abs(out[0, 1] - 0.3) < 1e-9


# ---- end to end with fake imagery ------------------------------------------------------------


def fake_scene(monkeypatch, per_date):
    """per_date: {iso date: function(grid) -> NDVI array}."""

    class Item:
        def __init__(self, day):
            self.day = day

    monkeypatch.setattr(ndvi, "_search", lambda *a, **k: [Item(d) for d in per_date])
    monkeypatch.setattr(ndvi, "_read_item", lambda item, grid: ndvi.ItemData(item.day, ndvi=per_date[item.day](grid)))


def test_compute_v2_dates_composite_and_pixels(monkeypatch):
    boundary = wgs(sq(0, 0, 100, 100))

    def clear(grid):
        return np.full(grid.shape, 0.4)

    def half_cloud(grid):
        a = np.full(grid.shape, 0.1)
        a[:, : grid.shape[1] // 2] = np.nan
        return a

    fake_scene(monkeypatch, {"2026-11-01": clear, "2026-11-06": half_cloud, "2026-11-11": clear})
    out = ndvi.compute_ndvi_v2(boundary, None, date(2026, 10, 1), date(2026, 11, 12), with_pixels=True)
    assert out["flags"] == ["boundary_fallback"] and out["geometry"]["pure_pixels"] == 64
    assert [v["date"] for v in out["values"]] == ["2026-11-01", "2026-11-06", "2026-11-11"]
    assert out["values"][0]["green_cover_pct"] == 100.0 and out["values"][1]["clear_fraction"] < 0.7
    comp = out["composite"]
    assert comp["dates"] == 2 and comp["newest_date"] == "2026-11-11" and comp["mean"] == 0.4  # half-cloud date left out
    px = out["pixels"]
    assert px["meta"]["source"] == "composite" and px["meta"]["pure_only"] is False
    assert all(f["properties"]["v"] == 0.4 for f in px["features"]) and len(px["features"]) > 90


def test_pixels_above_5000_send_only_pure_ones():
    n = 80
    cov = np.full((n, n), 0.5)
    cov[:10, :10] = 1.0
    zone = box(X0, Y0, X0 + n * 10, Y0 + n * 10)
    out = ndvi.pixel_features(np.full((n, n), 0.3), cov, zone, ndvi.Grid.around(zone), "composite")
    assert out["meta"]["pure_only"] is True and len(out["features"]) == 100


def test_pixels_are_clipped_to_the_zone():
    zone = sq(0, 0, 15, 10)
    g = grid_for(zone)
    out = ndvi.pixel_features(np.full(g.shape, 0.3), np.ones(g.shape), zone, g, "x")
    area = sum(ndvi.to_utm({"type": "Polygon", "coordinates": f["geometry"]["coordinates"]}).area for f in out["features"])
    assert abs(area - 150) < 2


def test_endpoint_defaults_and_legacy_polygon(monkeypatch):
    seen = {}

    def fake(boundary, green_zone, d_from, d_to, max_cloud, shrink, cdays, thr, pixels):
        seen.update(gz=green_zone, cloud=max_cloud, shrink=shrink, cdays=cdays, thr=thr, pixels=pixels)
        return {"measured_on": "boundary", "values": []}

    monkeypatch.setattr(ndvi, "compute_ndvi_v2", fake)
    body = {"polygon": wgs(sq(0, 0, 100, 100)), "date_from": "2026-11-01", "date_to": "2026-11-12", "park_id": 3}
    r = TestClient(app).post("/ndvi", json=body)
    assert r.status_code == 200 and r.json()["park_id"] == 3
    assert seen == {"gz": None, "cloud": 80, "shrink": 5.0, "cdays": 30, "thr": 0.2, "pixels": False}
    assert TestClient(app).post("/ndvi", json={"date_from": "2026-11-01", "date_to": "2026-11-12"}).status_code == 422
    assert TestClient(app).post("/ndvi", json={**body, "max_cloud": 101}).status_code == 422


# ---- suggest zone ---------------------------------------------------------------------------


def test_votes_need_half_of_the_clear_dates():
    cov = np.ones((1, 4))
    nan = np.nan
    grids = [np.array([[0.5, 0.1, 0.5, nan]]), np.array([[0.5, 0.1, 0.1, nan]]), np.array([[0.1, 0.1, nan, 0.5]])]
    mask = ndvi.pixel_votes(grids, cov, 0.2, 0.5, 1)
    assert mask.tolist() == [[True, False, True, True]]  # 2/3, 0/3, 1/2, 1/1


def test_draft_removes_specks_fills_small_holes_and_stays_inside_boundary():
    boundary = sq(0, 0, 300, 300)
    g = grid_for(boundary)
    mask = np.zeros(g.shape, dtype=bool)
    mask[5:25, 5:25] = True  # big block
    mask[10, 10] = False  # one-pixel hole (100 m2)
    mask[2, 28] = True  # one-pixel speck
    poly = ndvi.draft_polygon(mask, g, boundary, min_area_m2=200)
    assert len(poly.geoms) == 1 and len(poly.geoms[0].interiors) == 0
    assert abs(poly.area - 400 * 100) < 1


def test_suggest_zone_end_to_end(monkeypatch):
    boundary = wgs(sq(0, 0, 200, 100))

    def left_green(grid):
        a = np.full(grid.shape, 0.05)
        a[:, : grid.shape[1] // 2] = 0.5
        return a

    fake_scene(monkeypatch, {"2026-10-20": left_green, "2026-11-01": left_green, "2026-11-10": left_green})
    out = ndvi.suggest_zone(boundary, date(2026, 11, 12))
    assert out["clear_dates"] == 3 and out["draft"]["type"] == "MultiPolygon"
    assert 8500 < out["area_m2"] < 10500  # the left half, about 100 x 100 m

    fake_scene(monkeypatch, {})
    empty = ndvi.suggest_zone(boundary, date(2026, 11, 12))
    assert empty["draft"] is None and "no_clear_dates" in empty["flags"]


def test_suggest_zone_endpoint(monkeypatch):
    seen = {}
    monkeypatch.setattr(ndvi, "suggest_zone", lambda b, d, *a: seen.update(args=a) or {"draft": None})
    r = TestClient(app).post("/ndvi/suggest-zone", json={"boundary": wgs(sq(0, 0, 100, 100)), "min_area_m2": 300})
    assert r.status_code == 200 and seen["args"][-2] == 300
