"""NDVI per park from Sentinel-2 L2A (Earth Search STAC, collection sentinel-2-c1-l2a), contract v2.

Only the park's window of each image is read (windowed reads of the cloud-optimised
GeoTIFFs over HTTPS), never whole scenes.

    NDVI = (NIR - Red) / (NIR + Red)      NIR = B08 ("nir"), Red = B04 ("red")

Measured area: the green zone, or the boundary when there is no green zone (flag
`boundary_fallback`). The area is shrunk inward by `shrink_m` in UTM 37N (EPSG:32637) and
every pixel of the shared 10 m grid gets a fractional coverage (exactextract). A pixel is
"pure" when coverage >= PURE_COVERAGE. A pixel is "clear" when the scene classification
(SCL) is not in MASKED_SCL (class 5, not-vegetated, is KEPT: a dying park lands there),
it is not nodata and NIR + Red > 0. Statistics are coverage-weighted.
"""

from __future__ import annotations

import logging
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field
from datetime import date, timedelta

import numpy as np
import rasterio
from pyproj import Transformer
from pystac_client import Client
from pystac_client.exceptions import APIError
from rasterio.enums import Resampling
from rasterio.transform import Affine, from_origin
from rasterio.windows import from_bounds
from shapely import make_valid
from shapely.geometry import MultiPolygon, Point, Polygon, box, mapping, shape
from shapely.ops import transform as shapely_transform, unary_union

log = logging.getLogger("namaa.ndvi")

STAC_URL = "https://earth-search.aws.element84.com/v1"
COLLECTION = "sentinel-2-c1-l2a"
DEFAULT_MAX_CLOUD = 80  # tile-level cloud %; the per-pixel SCL mask handles the rest
SEARCH_TIMEOUT_S = 30
READ_TIMEOUT_S = 30
MAX_ITEMS = 60

UTM_CRS = "EPSG:32637"  # Makkah; every tile that covers the parks is in this zone
PIXEL_M = 10.0
DEFAULT_SHRINK_M = 5.0
PURE_COVERAGE = 0.9
DEFAULT_GREEN_THRESHOLD = 0.20
DEFAULT_COMPOSITE_DAYS = 30
COMPOSITE_MIN_CLEAR_FRACTION = 0.7  # a date joins the composite when this share of the zone is clear
PIXELS_PURE_ONLY_ABOVE = 5000  # above this many pixels only pure ones are sent

# SCL classes masked: 0 nodata, 1 saturated/defective, 3 cloud shadow, 8-10 clouds and cirrus,
# 11 snow. Kept: 2 dark area, 4 vegetation, 5 not vegetated (KEPT on purpose), 6 water, 7 unclassified.
MASKED_SCL = np.array([0, 1, 3, 8, 9, 10, 11])

# GDAL settings for fast, anonymous, windowed HTTPS reads of the COGs.
GDAL_ENV = {
    "GDAL_DISABLE_READDIR_ON_OPEN": "EMPTY_DIR",
    "CPL_VSIL_CURL_ALLOWED_EXTENSIONS": ".tif,.TIF,.tiff",
    "GDAL_HTTP_TIMEOUT": str(READ_TIMEOUT_S),
    "GDAL_HTTP_MAX_RETRY": "2",
    "GDAL_HTTP_RETRY_DELAY": "1",
    "AWS_NO_SIGN_REQUEST": "YES",
    "VSI_CACHE": "TRUE",
}

_TO_UTM = Transformer.from_crs("EPSG:4326", UTM_CRS, always_xy=True)
_TO_WGS = Transformer.from_crs(UTM_CRS, "EPSG:4326", always_xy=True)


class NdviError(Exception):
    """A dependency failed. `status` is the HTTP status the endpoint should return."""

    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status
        self.message = message


# --------------------------------------------------------------------------- geometry


def to_utm(geojson: dict):
    return shapely_transform(_TO_UTM.transform, make_valid(shape(geojson)))


def to_wgs(geom):
    return shapely_transform(_TO_WGS.transform, geom)


def prepare_zone(boundary: dict, green_zone: dict | None, shrink_m: float) -> dict:
    """Pick the measured area, shrink it inward in UTM and describe its shape."""
    fallback = green_zone is None
    zone = to_utm(boundary if fallback else green_zone)
    shrunk = zone.buffer(-shrink_m) if shrink_m > 0 else zone
    return {
        "measured_on": "boundary" if fallback else "green_zone",
        "flags": ["boundary_fallback"] if fallback else [],
        "zone": zone,
        "shrunk": shrunk,
        "inner_width_m": inner_width(zone),
    }


def inner_width(zone) -> float:
    """Twice the radius of the largest circle that fits inside the zone (holes respected)."""
    if zone.is_empty:
        return 0.0
    from shapely import maximum_inscribed_circle

    parts = zone.geoms if hasattr(zone, "geoms") else [zone]
    best = 0.0
    for part in parts:
        if part.is_empty or part.area <= 0:
            continue
        best = max(best, maximum_inscribed_circle(part, 0.5).length)
    return round(2 * best, 1)


@dataclass
class Grid:
    transform: Affine
    shape: tuple[int, int]  # rows, cols
    x0: float
    y0: float
    x1: float
    y1: float

    @classmethod
    def around(cls, geom) -> "Grid":
        """A 10 m grid aligned to multiples of 10 m (Sentinel-2 tiles are), covering the geometry."""
        xmin, ymin, xmax, ymax = geom.bounds
        x0, y0 = np.floor(xmin / PIXEL_M) * PIXEL_M, np.floor(ymin / PIXEL_M) * PIXEL_M
        x1, y1 = np.ceil(xmax / PIXEL_M) * PIXEL_M, np.ceil(ymax / PIXEL_M) * PIXEL_M
        rows, cols = max(1, int(round((y1 - y0) / PIXEL_M))), max(1, int(round((x1 - x0) / PIXEL_M)))
        return cls(from_origin(x0, y1, PIXEL_M, PIXEL_M), (rows, cols), x0, y0, x1, y1)

    def cell_polygon(self, row: int, col: int) -> Polygon:
        left = self.x0 + col * PIXEL_M
        top = self.y1 - row * PIXEL_M
        return box(left, top - PIXEL_M, left + PIXEL_M, top)


def coverage_grid(geom, grid: Grid) -> np.ndarray:
    """Fraction (0..1) of every grid cell covered by `geom` (UTM). exactextract, with a shapely
    fallback (same numbers, slower) when the package is missing."""
    cov = np.zeros(grid.shape, dtype="float64")
    if geom.is_empty:
        return cov
    try:
        from exactextract import exact_extract
        from exactextract.raster import NumPyRasterSource
    except ImportError:  # pragma: no cover - the image installs it
        return _coverage_shapely(geom, grid)

    rows, cols = grid.shape
    source = NumPyRasterSource(np.zeros(grid.shape, dtype="float32"), xmin=grid.x0, ymin=grid.y0, xmax=grid.x1, ymax=grid.y1)
    feature = {"type": "Feature", "properties": {}, "geometry": mapping(geom)}
    result = exact_extract(source, [feature], ["cell_id", "coverage"], output="geojson")
    props = result[0]["properties"] if isinstance(result, list) else result["features"][0]["properties"]
    ids = np.asarray(props["cell_id"], dtype="int64")
    cov.reshape(-1)[ids] = np.asarray(props["coverage"], dtype="float64")
    return np.clip(cov, 0.0, 1.0)


def _coverage_shapely(geom, grid: Grid) -> np.ndarray:
    cov = np.zeros(grid.shape, dtype="float64")
    xmin, ymin, xmax, ymax = geom.bounds
    for row in range(grid.shape[0]):
        for col in range(grid.shape[1]):
            cell = grid.cell_polygon(row, col)
            if cell.intersects(geom):
                cov[row, col] = cell.intersection(geom).area / cell.area
    return cov


# --------------------------------------------------------------------------- pixels -> NDVI


def band_to_ndvi(red, nir, scl, red_cal: tuple[float, float, float | None], nir_cal: tuple[float, float, float | None]) -> np.ndarray:
    """NDVI grid, NaN where a pixel is not clear. `*_cal` = (scale, offset, nodata)."""
    red = np.asarray(red, dtype="float64")
    nir = np.asarray(nir, dtype="float64")
    clear = ~np.isin(scl, MASKED_SCL)
    if red_cal[2] is not None:
        clear &= red != red_cal[2]
    if nir_cal[2] is not None:
        clear &= nir != nir_cal[2]
    red_r = red * red_cal[0] + red_cal[1]
    nir_r = nir * nir_cal[0] + nir_cal[1]
    total = nir_r + red_r
    clear &= total > 0
    ndvi = np.full(red.shape, np.nan)
    ndvi[clear] = (nir_r[clear] - red_r[clear]) / total[clear]
    ndvi[(ndvi < -1) | (ndvi > 1)] = np.nan
    return ndvi


def weighted_quantile(values: np.ndarray, weights: np.ndarray, q: float) -> float:
    """Coverage-weighted quantile; with equal weights it equals numpy's linear percentile."""
    order = np.argsort(values)
    v, w = values[order], weights[order]
    cum = np.cumsum(w) - w / 2
    return float(np.interp(q * w.sum(), cum, v))


def grid_stats(ndvi: np.ndarray, cov: np.ndarray, usual_pixels: float, green_threshold: float) -> dict | None:
    """Statistics of one NDVI grid over the coverage grid. None when no clear pixel touches the zone."""
    clear = ~np.isnan(ndvi) & (cov > 0)
    if not clear.any():
        return None
    v, w = ndvi[clear], cov[clear]
    pure = cov[clear] >= PURE_COVERAGE
    clear_pixels = float(w.sum())
    green = (v[pure] >= green_threshold).mean() * 100 if pure.any() else None
    return {
        "mean": round(float(np.average(v, weights=w)), 3),
        "median": round(weighted_quantile(v, w, 0.5), 3),
        "p90": round(weighted_quantile(v, w, 0.9), 3),
        "pixels": int(round(clear_pixels)),
        "pure_pixels": int(pure.sum()),
        "weighted_pixels": round(clear_pixels, 1),
        "clear_pixels": round(clear_pixels, 1),
        "clear_fraction": round(min(1.0, clear_pixels / usual_pixels), 3) if usual_pixels > 0 else 0.0,
        "green_cover_pct": None if green is None else round(float(green), 1),
    }


# --------------------------------------------------------------------------- reading imagery


@dataclass
class ItemData:
    date: str
    ndvi: np.ndarray | None = None
    reason: str | None = None  # no_data when nothing could be read
    scale: float = 0.0001
    offset: float = -0.1
    boa_offset_applied: bool = False
    stats: dict | None = field(default=None)


def _search(geometry: dict, date_from: date, date_to: date, max_cloud: float) -> list:
    try:
        client = Client.open(STAC_URL, timeout=SEARCH_TIMEOUT_S)
        search = client.search(
            collections=[COLLECTION],
            intersects=geometry,
            datetime=f"{date_from.isoformat()}/{date_to.isoformat()}",
            query={"eo:cloud_cover": {"lt": max_cloud}},
            max_items=MAX_ITEMS,
        )
        return list(search.items())
    except APIError as e:
        raise NdviError(502, f"STAC search failed: {e}") from e
    except Exception as e:  # requests timeouts / connection errors come through here
        if _is_timeout(e):
            raise NdviError(504, f"STAC search timed out after {SEARCH_TIMEOUT_S} s") from e
        raise NdviError(502, f"STAC search failed: {type(e).__name__}: {e}") from e


def _is_timeout(e: BaseException) -> bool:
    text = f"{type(e).__name__} {e}".lower()
    return "timeout" in text or "timed out" in text


def _scale_offset(asset, boa_offset_applied: bool = False) -> tuple[float, float, float | None]:
    """Scale, offset and nodata of an asset.

    Order: raster extension v1 (`raster:bands[0]`), raster extension v2 (`raster:scale` /
    `raster:offset` on the asset), then the Sentinel-2 L2A baseline >= 04.00 default
    (reflectance = DN * 0.0001 - 0.1). When the item says the offset is already applied
    (`earthsearch:boa_offset_applied`), the offset is 0."""
    extra = getattr(asset, "extra_fields", {}) or {}
    bands = extra.get("raster:bands") or [{}]
    band = bands[0] if bands else {}
    scale = band.get("scale", extra.get("raster:scale"))
    offset = band.get("offset", extra.get("raster:offset"))
    if scale is None or offset is None:
        scale, offset = 0.0001, -0.1
    if boa_offset_applied:
        offset = 0.0
    return float(scale), float(offset), band.get("nodata", extra.get("nodata", 0))


def _item_date(item) -> str:
    return item.datetime.date().isoformat() if item.datetime else str(item.properties.get("datetime", ""))[:10]


def _read_item(item, grid: Grid) -> ItemData:
    """Read red / nir / scl on the shared grid and return the NDVI grid (NaN = not clear)."""
    day = _item_date(item)
    try:
        red_asset, nir_asset, scl_asset = item.assets["red"], item.assets["nir"], item.assets["scl"]
    except KeyError:
        log.warning("item %s lacks red/nir/scl assets", item.id)
        return ItemData(day, reason="no_data")

    applied = bool(item.properties.get("earthsearch:boa_offset_applied", False))
    try:
        with rasterio.Env(**GDAL_ENV):
            out = {}
            for name, asset, resampling in (("red", red_asset, Resampling.nearest), ("nir", nir_asset, Resampling.nearest), ("scl", scl_asset, Resampling.nearest)):
                with rasterio.open(asset.href) as src:
                    if src.crs is None or src.crs.to_string() != UTM_CRS:
                        log.warning("item %s is not in %s (%s): skipped", item.id, UTM_CRS, src.crs)
                        return ItemData(day, reason="no_data")
                    window = from_bounds(grid.x0, grid.y0, grid.x1, grid.y1, transform=src.transform)
                    out[name] = src.read(1, window=window, out_shape=grid.shape, resampling=resampling, boundless=True, fill_value=0)
    except Exception as e:
        log.warning("reading %s failed: %s", item.id, e)
        if _is_timeout(e):
            raise NdviError(504, f"Reading imagery timed out ({item.id})") from e
        return ItemData(day, reason="no_data")

    red_cal = _scale_offset(red_asset, applied)
    nir_cal = _scale_offset(nir_asset, applied)
    ndvi = band_to_ndvi(out["red"], out["nir"], out["scl"], red_cal, nir_cal)
    return ItemData(day, ndvi=ndvi, scale=red_cal[0], offset=red_cal[1], boa_offset_applied=applied)


def _read_items(items: list, grid: Grid) -> list[ItemData]:
    with ThreadPoolExecutor(max_workers=6) as pool:
        return list(pool.map(lambda item: _read_item(item, grid), items))


def _best_per_date(reads: list[ItemData]) -> dict[str, ItemData]:
    """One entry per acquisition date; tiles overlap, so the tile with more clear pixels wins
    (merging would count pixels twice). A read failure never beats a real count."""
    best: dict[str, ItemData] = {}
    for r in reads:
        current = best.get(r.date)
        if current is None or _score(r) > _score(current):
            best[r.date] = r
    return best


def _score(r: ItemData) -> float:
    if r.stats:
        return r.stats["clear_pixels"]
    return 0.0 if r.ndvi is None else 0.0001  # read fine but nothing clear beats a failed read


# --------------------------------------------------------------------------- /ndvi


def compute_ndvi_v2(
    boundary: dict,
    green_zone: dict | None,
    date_from: date,
    date_to: date,
    max_cloud: float = DEFAULT_MAX_CLOUD,
    shrink_m: float = DEFAULT_SHRINK_M,
    composite_days: int = DEFAULT_COMPOSITE_DAYS,
    green_threshold: float = DEFAULT_GREEN_THRESHOLD,
    with_pixels: bool = False,
) -> dict:
    zone = prepare_zone(boundary, green_zone, shrink_m)
    result = {
        "measured_on": zone["measured_on"],
        "flags": list(zone["flags"]),
        "geometry": {"usual_pixels": 0.0, "pure_pixels": 0, "inner_width_m": zone["inner_width_m"], "shrink_m": shrink_m},
        "values": [],
        "dates": [],
        "composite": None,
        "reflectance": None,
        "pixels": None,
    }
    if zone["shrunk"].is_empty or zone["shrunk"].area <= 0:
        result["flags"].append("empty_after_shrink")
        return result

    grid = Grid.around(zone["zone"])
    cov = coverage_grid(zone["shrunk"], grid)
    usual = float(cov.sum())
    result["geometry"]["usual_pixels"] = round(usual, 1)
    result["geometry"]["pure_pixels"] = int((cov >= PURE_COVERAGE).sum())

    items = _search(mapping(to_wgs(zone["zone"])), date_from, date_to, max_cloud)
    if not items:
        return result

    reads = _read_items(items, grid)
    for r in reads:
        if r.ndvi is not None:
            r.stats = grid_stats(r.ndvi, cov, usual, green_threshold)
    best = _best_per_date(reads)

    for day in sorted(best):
        r = best[day]
        if r.stats:
            result["values"].append({"date": day, **r.stats})
            result["dates"].append({"date": day, "status": "kept", **r.stats})
        else:
            result["dates"].append({"date": day, "status": "dropped", "reason": r.reason or "no_clear_pixels", "pixels": 0})

    sample = next((r for r in reads if r.ndvi is not None), None)
    if sample:
        result["reflectance"] = {"scale": sample.scale, "offset": sample.offset, "boa_offset_applied": sample.boa_offset_applied}

    window_start = date_to - timedelta(days=composite_days)
    in_window = [best[d] for d in sorted(best) if best[d].stats and best[d].stats["clear_fraction"] >= COMPOSITE_MIN_CLEAR_FRACTION and window_start < date.fromisoformat(d) <= date_to]
    composite_grid = None
    if in_window:
        composite_grid = composite_median([r.ndvi for r in in_window])
        stats = grid_stats(composite_grid, cov, usual, green_threshold)
        if stats:
            result["composite"] = {"date_from": window_start.isoformat(), "date_to": date_to.isoformat(), "dates": len(in_window), "newest_date": in_window[-1].date, **stats}

    if with_pixels:
        source, ndvi_grid = ("composite", composite_grid) if composite_grid is not None else (None, None)
        if ndvi_grid is None:
            latest = next((best[d] for d in sorted(best, reverse=True) if best[d].stats), None)
            if latest:
                source, ndvi_grid = latest.date, latest.ndvi
        if ndvi_grid is not None:
            result["pixels"] = pixel_features(ndvi_grid, cov, zone["zone"], grid, source)
    return result


def composite_median(grids: list[np.ndarray]) -> np.ndarray:
    """Per-pixel median over the dates (NaN where no date was clear)."""
    stack = np.stack(grids)
    with np.errstate(all="ignore"):
        import warnings

        with warnings.catch_warnings():
            warnings.simplefilter("ignore", RuntimeWarning)
            return np.nanmedian(stack, axis=0)


def pixel_features(ndvi: np.ndarray, cov: np.ndarray, zone, grid: Grid, source: str | None) -> dict:
    """GeoJSON squares clipped to the (unshrunk) zone, property `v` = NDVI. Above 5000 pixels only
    pure ones (coverage of the shrunk zone >= 0.9) are sent."""
    rows, cols = np.nonzero(~np.isnan(ndvi))
    keep = []
    for row, col in zip(rows, cols):
        keep.append((int(row), int(col)))
    pure_only = len(keep) > PIXELS_PURE_ONLY_ABOVE
    features = []
    for row, col in keep:
        if pure_only and cov[row, col] < PURE_COVERAGE:
            continue
        cell = grid.cell_polygon(row, col)
        clipped = cell.intersection(zone)
        if clipped.is_empty or clipped.area < 0.5:
            continue
        geom = mapping(to_wgs(clipped))
        features.append({"type": "Feature", "properties": {"v": round(float(ndvi[row, col]), 3)}, "geometry": _round_geom(geom)})
    return {"type": "FeatureCollection", "features": features, "meta": {"source": source, "pure_only": pure_only, "pixels": len(features)}}


def _round_geom(geom: dict) -> dict:
    def r(c):
        return [round(c[0], 6), round(c[1], 6)] if isinstance(c[0], (int, float)) else [r(x) for x in c]

    return {"type": geom["type"], "coordinates": r(geom["coordinates"])}


# --------------------------------------------------------------------------- /ndvi/suggest-zone


def pixel_votes(grids: list[np.ndarray], cov: np.ndarray, threshold: float, min_clear_share: float, min_clear_dates: int) -> np.ndarray:
    """Boolean grid: pixels inside the boundary whose NDVI >= threshold on at least `min_clear_share`
    of the clear dates."""
    stack = np.stack(grids)
    clear = ~np.isnan(stack)
    n_clear = clear.sum(axis=0)
    with np.errstate(invalid="ignore"):
        n_green = (np.nan_to_num(stack, nan=-9) >= threshold).sum(axis=0)
    ok = (n_clear >= max(1, min_clear_dates)) & (n_green >= min_clear_share * np.maximum(n_clear, 1))
    return ok & (cov >= 0.5)


def draft_polygon(mask: np.ndarray, grid: Grid, boundary, min_area_m2: float):
    """Merge the selected pixel squares, clip to the boundary, drop islands and holes smaller than
    `min_area_m2`. Returns a UTM geometry or None."""
    rows, cols = np.nonzero(mask)
    if rows.size == 0:
        return None
    merged = unary_union([grid.cell_polygon(int(r), int(c)) for r, c in zip(rows, cols)]).intersection(boundary)
    parts = [merged] if isinstance(merged, Polygon) else [g for g in getattr(merged, "geoms", []) if isinstance(g, Polygon)]
    out = []
    for part in parts:
        if part.area < min_area_m2:
            continue
        holes = [h for h in part.interiors if Polygon(h).area >= min_area_m2]
        out.append(Polygon(part.exterior, holes))
    if not out:
        return None
    return MultiPolygon(out)


def suggest_zone(
    boundary: dict,
    date_to: date,
    days: int = 60,
    ndvi_threshold: float = DEFAULT_GREEN_THRESHOLD,
    min_clear_share: float = 0.5,
    min_clear_dates: int = 1,
    min_area_m2: float = 200.0,
    max_cloud: float = DEFAULT_MAX_CLOUD,
) -> dict:
    boundary_utm = to_utm(boundary)
    grid = Grid.around(boundary_utm)
    cov = coverage_grid(boundary_utm, grid)
    date_from = date_to - timedelta(days=days)

    items = _search(boundary, date_from, date_to, max_cloud)
    reads = _read_items(items, grid) if items else []
    for r in reads:
        if r.ndvi is not None:
            r.stats = grid_stats(r.ndvi, cov, float(cov.sum()), ndvi_threshold)
    best = _best_per_date(reads)
    usable = [best[d] for d in sorted(best) if best[d].stats]
    result = {"draft": None, "area_m2": 0.0, "pixels": 0, "clear_dates": len(usable), "date_from": date_from.isoformat(), "date_to": date_to.isoformat(), "flags": []}
    if not usable:
        result["flags"].append("no_clear_dates")
        return result
    return {**result, **zone_from_grids([r.ndvi for r in usable], boundary_utm, grid, cov, ndvi_threshold, min_clear_share, min_clear_dates, min_area_m2)}


def zone_from_grids(grids, boundary_utm, grid, cov, threshold, min_clear_share, min_clear_dates, min_area_m2) -> dict:
    mask = pixel_votes(grids, cov, threshold, min_clear_share, min_clear_dates)
    poly = draft_polygon(mask, grid, boundary_utm, min_area_m2)
    if poly is None:
        return {"draft": None, "area_m2": 0.0, "pixels": int(mask.sum()), "flags": ["nothing_green"]}
    return {"draft": _round_geom(mapping(to_wgs(poly))), "area_m2": round(poly.area, 1), "pixels": int(mask.sum()), "flags": []}
