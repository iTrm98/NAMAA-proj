# Park data

Park boundaries in this folder come from OpenStreetMap and are licensed under the Open Database License (ODbL).
Data (c) OpenStreetMap contributors, https://www.openstreetmap.org/copyright. If you publish a map or a derived
dataset built from these files, keep the attribution and share it under the same licence.

## Files

| File | Tracked | What it is |
|---|---|---|
| `makkah-parks.geojson` | yes | The selection: the parks NAMAA monitors. A FeatureCollection; `namaa:parks:import` and the setup page read and write it. |
| `osm/makkah-osm-YYYYMMDD.geojson` | no | Raw export of parks and gardens in Makkah from Overpass. Written by `namaa:parks:fetch-osm`. |

## Feature properties

The OSM export has `osm_id` (`way/123`), `name`, `name:ar`, `name:en`, `area_m2`. The selection file accepts those plus:
`slug`, `name_ar`, `name_en`, `district`, `monitoring_mode` (`simulated` | `real_sensors` | `satellite_only`),
`geometry_source` (`osm` | `traced`), `green_zone` (GeoJSON MultiPolygon, vegetation only) and `claims[]`
(`field`, `value`, `source_name`, `source_url`, `source_date`, `note`). A hand-trimmed copy of an OSM export is a valid import file.

The feature geometry is the park boundary (Polygon or MultiPolygon; a MultiPolygon imports as its largest polygon).

## How to refresh

```powershell
docker compose exec backend php artisan namaa:parks:fetch-osm
docker compose exec backend php artisan namaa:parks:import /var/www/data/parks/makkah-parks.geojson --dry-run
docker compose exec backend php artisan namaa:parks:import /var/www/data/parks/makkah-parks.geojson
```

The import creates new parks as `satellite_only`, never changes the mode of an existing park (use
`namaa:parks:set-mode`), and never overwrites a traced boundary or green zone unless `--force-geometry` is given.
Run it twice: the second run reports everything as unchanged.
