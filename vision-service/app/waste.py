"""Litter detection with Ultralytics YOLO (POST /waste).

Two models, both loaded once at startup (never per request):

- The COCO model (WASTE_COCO_MODEL, default the yolo26n weights baked into the
  image at build time). It always finds `person` boxes (returned as `people`,
  so Laravel can blur them before saving), and finds litter through a list of
  litter-like COCO classes (WASTE_CLASSES) while there is no custom model.
- A custom model, if `models/litter.pt` exists (or WASTE_MODEL points to a
  file): then every class of that model counts as litter.

Licence: Ultralytics is AGPL-3.0 (see README.md).
"""

from __future__ import annotations

import io
import os
import time
from dataclasses import dataclass
from pathlib import Path

from PIL import Image, ImageOps, UnidentifiedImageError

# COCO classes that are plausibly litter on park grass: drink containers,
# cutlery, food and food packaging. Bags and toys are left out on purpose
# (people carry them; a ball on a lawn is not litter).
DEFAULT_LITTER_CLASSES = (
    "bottle", "cup", "wine glass", "fork", "knife", "spoon", "bowl",
    "banana", "apple", "orange", "sandwich", "hot dog", "pizza", "donut", "cake",
)

SERVICE_DIR = Path(__file__).resolve().parent.parent


def _env_float(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name, default))
    except ValueError:
        return default


@dataclass(frozen=True)
class WasteConfig:
    coco_model: str
    custom_model: str | None
    confidence: float
    person_confidence: float
    classes: tuple[str, ...]
    max_bytes: int
    imgsz: int

    @classmethod
    def from_env(cls) -> "WasteConfig":
        classes = os.environ.get("WASTE_CLASSES", "")
        custom = os.environ.get("WASTE_MODEL") or str(SERVICE_DIR / "models" / "litter.pt")
        return cls(
            coco_model=os.environ.get("WASTE_COCO_MODEL", "/opt/namaa/weights/yolo26n.pt"),
            custom_model=custom if Path(custom).is_file() else None,
            confidence=_env_float("WASTE_CONFIDENCE", 0.35),
            person_confidence=_env_float("WASTE_PERSON_CONFIDENCE", 0.25),
            classes=tuple(c.strip() for c in classes.split(",") if c.strip()) or DEFAULT_LITTER_CLASSES,
            max_bytes=int(_env_float("WASTE_MAX_MB", 5) * 1024 * 1024),
            imgsz=int(_env_float("WASTE_IMGSZ", 640)),
        )


class WasteError(Exception):
    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status
        self.message = message


class WasteDetector:
    def __init__(self, config: WasteConfig):
        from ultralytics import YOLO  # heavy import: only when the service starts

        self.config = config
        self.coco = YOLO(config.coco_model)
        self.custom = YOLO(config.custom_model) if config.custom_model else None
        names = self.coco.names
        self.litter_ids = [i for i, n in names.items() if n in config.classes]
        self.person_ids = [i for i, n in names.items() if n == "person"]

    @property
    def model_name(self) -> str:
        return Path(self.config.custom_model or self.config.coco_model).name

    def decode(self, data: bytes) -> Image.Image:
        if not data:
            raise WasteError(422, "image is empty")
        if len(data) > self.config.max_bytes:
            raise WasteError(422, f"image is larger than {self.config.max_bytes // (1024 * 1024)} MB")
        try:
            image = Image.open(io.BytesIO(data))
            if image.format not in ("JPEG", "PNG"):
                raise WasteError(422, "image must be JPEG or PNG")
            image.load()
        except WasteError:
            raise
        except (UnidentifiedImageError, OSError, ImportError, ValueError) as e:
            # Ultralytics hooks PIL to try HEIF on unknown files (ImportError without pi-heif).
            raise WasteError(422, "image could not be read as JPEG or PNG") from e
        # Phones and some cameras store the rotation in EXIF.
        return ImageOps.exif_transpose(image).convert("RGB")

    @staticmethod
    def _boxes(result, keep_ids: list[int] | None) -> list[dict]:
        out = []
        names = result.names
        for box in result.boxes:
            cls = int(box.cls[0])
            if keep_ids is not None and cls not in keep_ids:
                continue
            x1, y1, x2, y2 = (round(float(v), 1) for v in box.xyxy[0])
            out.append({"label": names[cls], "confidence": round(float(box.conf[0]), 3), "box": [x1, y1, x2, y2]})
        return sorted(out, key=lambda b: -b["confidence"])

    def detect(self, data: bytes) -> dict:
        image = self.decode(data)
        cfg = self.config
        started = time.perf_counter()

        # One COCO pass at the lower of the two thresholds, split afterwards.
        coco_classes = self.person_ids + ([] if self.custom else self.litter_ids)
        coco = self.coco.predict(image, imgsz=cfg.imgsz, conf=min(cfg.confidence, cfg.person_confidence),
                                 classes=coco_classes or None, verbose=False)[0]
        people = [
            {"confidence": b["confidence"], "box": b["box"]}
            for b in self._boxes(coco, self.person_ids) if b["confidence"] >= cfg.person_confidence
        ]

        if self.custom:
            result = self.custom.predict(image, imgsz=cfg.imgsz, conf=cfg.confidence, verbose=False)[0]
            items = self._boxes(result, None)
        else:
            items = [b for b in self._boxes(coco, self.litter_ids) if b["confidence"] >= cfg.confidence]

        return {
            "items": items,
            "count": len(items),
            "people": people,
            "model": self.model_name,
            "inference_ms": round((time.perf_counter() - started) * 1000),
            "image_w": image.width,
            "image_h": image.height,
        }

    def info(self) -> dict:
        return {
            "model": self.model_name,
            "custom": self.custom is not None,
            "classes": list(self.custom.names.values()) if self.custom else list(self.config.classes),
            "confidence": self.config.confidence,
        }
