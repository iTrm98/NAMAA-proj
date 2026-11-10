# NAMAA vision service

Python 3.12 + FastAPI, in Docker (`vision`, port 8001, local only). **Only Laravel calls it** (`http://vision:8001`); the dashboard never does.

| Endpoint | What it does |
|---|---|
| `GET /health` | `{ "status": "ok", "waste": { "model", "custom", "classes", "confidence" } }` (`waste` is `null` if the model failed to load) |
| `POST /waste` | Litter detection on one photo (YOLO) |
| `POST /ndvi` | Sentinel-2 NDVI per date for a park polygon (see `app/ndvi.py`) |

## `POST /waste`

Multipart field `image`: JPEG or PNG, at most 5 MB.

```json
{
  "items": [{ "label": "bottle", "confidence": 0.81, "box": [120.0, 88.5, 160.2, 140.0] }],
  "count": 1,
  "people": [{ "confidence": 0.9, "box": [10, 20, 110, 400] }],
  "model": "yolo26n.pt",
  "inference_ms": 140,
  "image_w": 1600,
  "image_h": 1200
}
```

- `box` = `[x1, y1, x2, y2]` in pixels of the uploaded image (after EXIF rotation), top-left origin.
- `people` are person boxes, kept apart from `items`: they are never litter. Laravel blurs them before saving the photo.
- `422` for a missing, empty, unreadable, non-JPEG/PNG or too large file; `503` if the model did not load.

### Models

| Case | Litter classes |
|---|---|
| Default | COCO model `yolo26n.pt` (the smallest Ultralytics YOLO26), downloaded **at image build time** to `/opt/namaa/weights/`. Only the litter-like COCO classes count: `bottle`, `cup`, `wine glass`, `fork`, `knife`, `spoon`, `bowl`, `banana`, `apple`, `orange`, `sandwich`, `hot dog`, `pizza`, `donut`, `cake`. Bags, balls and other things people carry are left out on purpose |
| `vision-service/models/litter.pt` exists | That custom model is used for litter, with **all** its classes. The COCO model still runs for `person` (privacy blur) |

Weights are never committed (`vision-service/models/*.pt` is git-ignored). See [training/README.md](training/README.md) to train `litter.pt` on your own park photos.

### Settings (environment variables, all optional)

| Variable | Default | Meaning |
|---|---|---|
| `WASTE_MODEL` | `models/litter.pt` if present | Path of a custom litter model |
| `WASTE_COCO_MODEL` | `/opt/namaa/weights/yolo26n.pt` | COCO model (litter fallback + people) |
| `WASTE_CONFIDENCE` | `0.35` | Minimum confidence for a litter item |
| `WASTE_PERSON_CONFIDENCE` | `0.25` | Minimum confidence for a person box (low on purpose: blurring too much is better than too little) |
| `WASTE_CLASSES` | the list above | Comma-separated COCO class names that count as litter |
| `WASTE_MAX_MB` | `5` | Largest accepted upload |
| `WASTE_IMGSZ` | `640` | Inference size |

## Licence: Ultralytics is AGPL-3.0

`ultralytics` (the YOLO library and the pretrained YOLO weights) is licensed under the **GNU AGPL-3.0**. For the competition demo and internal use that is fine. If NAMAA is ever offered to customers as a service, the AGPL requires the source of the whole service that uses it to be made available to its users, **or** an Ultralytics Enterprise licence must be bought. Decide this before any commercial launch. The rest of this service (FastAPI, PyTorch, Pillow, rasterio, ...) uses permissive licences.

## Build and test

```powershell
docker compose build vision
docker compose up -d --force-recreate vision
docker compose exec vision pytest -q
```

PyTorch is installed CPU-only from the PyTorch index (`torch 2.14.1`, `torchvision 0.29.1`) to keep the image small; OpenCV is the headless build.
