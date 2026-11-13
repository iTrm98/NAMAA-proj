"""NDVI per park from Sentinel-2 L2A (Earth Search STAC, collection sentinel-2-c1-l2a).

Only the park's window of each image is read (windowed reads of the cloud-optimised
GeoTIFFs over HTTPS), never whole scenes.

    NDVI = (NIR - Red) / (NIR + Red)      NIR = B08 ("nir"), Red = B04 ("red")

Valid pixel: inside the polygon, not nodata, scene classification (SCL) in
VALID_SCL (vegetation, bare soil, water, unclassified), NIR + Red > 0.
One value per acquisition date; when two tiles cover the park on the same date the
tile with more valid pixels is used (tiles overlap, so merging would count pixels twice).
"""

from __future__ import annotations

import logging
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from datetime import date

import numpy as np
import rasterio
from pyproj import Transformer
from pystac_client import Client
from pystac_client.exceptions import APIError
from rasterio.enums import Resampling
from rasterio.features import geometry_mask
from rasterio.windows import Window, bounds as window_bounds, from_bounds
from shapely.geometry import shape
from shapely.ops import transform as shapely_transform

log = logging.getLogger("namaa.ndvi")

STAC_URL = "https://earth-search.aws.element84.com/v1"
COLLECTION = "sentinel-2-c1-l2a"
MIN_VALID_PIXELS = 10  # proposed
DEFAULT_MAX_CLOUD = 80  # tile-level cloud %; per-pixel SCL mask + MIN_VALID_PIXELS drop cloudy parks
SEARCH_TIMEOUT_S = 30
READ_TIMEOUT_S = 30
MAX_ITEMS = 60

# SCL classes kept: 4 vegetation, 5 not vegetated (bare soil), 6 water, 7 unclassified.
# Dropped: 0 nodata, 1 saturated/defective, 2 dark/topographic shadow, 3 cloud shadow,
# 8-10 clouds and cirrus, 11 snow.
VALID_SCL = np.array([4, 5, 6, 7])

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


class NdviError(Exception):
    """A dependency failed. `status` is the HTTP status the endpoint should return."""

    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status
        self.message = message


@dataclass
class DateValue:
    date: str
    mean: float
    pixels: int
    reason: str | None = None  # why the item has no value: outside_outline | no_data


def compute_ndvi(polygon: dict, date_from: date, date_to: date, max_cloud: float = DEFAULT_MAX_CLOUD) -> list[dict]:
    """Return [{date, mean, pixels}] sorted by date (dates with < MIN_VALID_PIXELS left out)."""
    return compute_ndvi_detail(polygon, date_from, date_to, max_cloud)[0]


def compute_ndvi_detail(polygon: dict, date_from: date, date_to: date, max_cloud: float = DEFAULT_MAX_CLOUD) -> tuple[list[dict], list[dict]]:
    """Return (values, dates). `dates` has one entry per acquisition date found:
    {date, status: kept|dropped, reason?: too_few_pixels|outside_outline|no_data, mean?, pixels}."""
    items = _search(polygon, date_from, date_to, max_cloud)
    if not items:
        return [], []

    geom_wgs84 = shape(polygon)

    with ThreadPoolExecutor(max_workers=6) as pool:
        results = list(pool.map(lambda item: _item_value(item, geom_wgs84), items))

    best: dict[str, DateValue] = {}
    for value in results:
        if value is None:
            continue
        current = best.get(value.date)
        # the tile with more clear pixels wins; a read failure never beats a real count
        if current is None or value.pixels > current.pixels:
            best[value.date] = value

    values, dates = [], []
    for day in sorted(best):
        v = best[day]
        if v.reason is None and v.pixels >= MIN_VALID_PIXELS:
            values.append({"date": v.date, "mean": round(v.mean, 3), "pixels": v.pixels})
            dates.append({"date": v.date, "status": "kept", "mean": round(v.mean, 3), "pixels": v.pixels})
        else:
            dates.append({"date": v.date, "status": "dropped", "reason": v.reason or "too_few_pixels", "pixels": v.pixels})
    return values, dates


def _search(polygon: dict, date_from: date, date_to: date, max_cloud: float) -> list:
    try:
        client = Client.open(STAC_URL, timeout=SEARCH_TIMEOUT_S)
        search = client.search(
            collections=[COLLECTION],
            intersects=polygon,
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


def _scale_offset(asset) -> tuple[float, float, float | None]:
    """Scale, offset and nodata from the asset's raster:bands (STAC raster extension)."""
    bands = asset.extra_fields.get("raster:bands") or [{}]
    band = bands[0] if bands else {}
    scale = band.get("scale")
    offset = band.get("offset")
    if scale is None or offset is None:
        # Not in the metadata: Sentinel-2 L2A processing baseline >= 04.00 stores
        # reflectance = DN * 0.0001 - 0.1 (BOA_ADD_OFFSET = -1000).
        scale, offset = 0.0001, -0.1
    return float(scale), float(offset), band.get("nodata", 0)


def _item_value(item, geom_wgs84) -> DateValue | None:
    try:
        red_asset, nir_asset, scl_asset = item.assets["red"], item.assets["nir"], item.assets["scl"]
    except KeyError:
        log.warning("item %s lacks red/nir/scl assets", item.id)
        return DateValue(date=_item_date(item), mean=0.0, pixels=0, reason="no_data")

    try:
        with rasterio.Env(**GDAL_ENV):
            with rasterio.open(red_asset.href) as red_src:
                to_crs = Transformer.from_crs("EPSG:4326", red_src.crs, always_xy=True)
                geom = shapely_transform(to_crs.transform, geom_wgs84)

                window = from_bounds(*geom.bounds, transform=red_src.transform)
                window = window.round_offsets(op="floor").round_lengths(op="ceil")
                window = window.intersection(Window(0, 0, red_src.width, red_src.height))
                if window.width < 1 or window.height < 1:
                    return DateValue(date=_item_date(item), mean=0.0, pixels=0, reason="outside_outline")

                red = red_src.read(1, window=window).astype("float64")
                win_transform = red_src.window_transform(window)
                bounds = window_bounds(window, red_src.transform)

            with rasterio.open(nir_asset.href) as nir_src:  # same 10 m grid as red
                nir = nir_src.read(1, window=from_bounds(*bounds, transform=nir_src.transform), out_shape=red.shape).astype("float64")

            with rasterio.open(scl_asset.href) as scl_src:  # 20 m: resample to the 10 m window
                scl = scl_src.read(
                    1,
                    window=from_bounds(*bounds, transform=scl_src.transform),
                    out_shape=red.shape,
                    resampling=Resampling.nearest,
                )
    except Exception as e:
        log.warning("reading %s failed: %s", item.id, e)
        if _is_timeout(e):
            raise NdviError(504, f"Reading imagery timed out ({item.id})") from e
        return DateValue(date=_item_date(item), mean=0.0, pixels=0, reason="no_data")

    inside = geometry_mask([geom], out_shape=red.shape, transform=win_transform, invert=True)

    red_scale, red_offset, red_nodata = _scale_offset(red_asset)
    nir_scale, nir_offset, nir_nodata = _scale_offset(nir_asset)
    valid = inside & np.isin(scl, VALID_SCL)
    if red_nodata is not None:
        valid &= red != red_nodata
    if nir_nodata is not None:
        valid &= nir != nir_nodata

    red_r = red * red_scale + red_offset
    nir_r = nir * nir_scale + nir_offset
    total = nir_r + red_r
    valid &= total > 0

    if not inside.any():
        return DateValue(date=_item_date(item), mean=0.0, pixels=0, reason="outside_outline")
    if not valid.any():
        return DateValue(date=_item_date(item), mean=0.0, pixels=0)

    ndvi = (nir_r[valid] - red_r[valid]) / total[valid]
    ndvi = ndvi[(ndvi >= -1) & (ndvi <= 1)]
    return DateValue(date=_item_date(item), mean=float(ndvi.mean()) if ndvi.size else 0.0, pixels=int(ndvi.size))


def _item_date(item) -> str:
    return item.datetime.date().isoformat() if item.datetime else str(item.properties.get("datetime", ""))[:10]
