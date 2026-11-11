"""Draft litter boxes for new photos, as a Label Studio task file with predictions.

Runs a weights file on every image in one or more folders under ml/photos/ and writes a JSON
list of Label Studio tasks. Each task has the image as a local-files URL (matching
ml/labelstudio/docker-compose.yml) and the detections as a prediction (rectanglelabels,
percent coordinates, class `litter`). Import the file into the Label Studio project and fix
the drafts: the low default --conf (0.15) means you mostly delete or adjust boxes, rarely draw.

Examples (from ml/):
    .venv\\Scripts\\python.exe prelabel.py photos\\demo-tray\\litter photos\\demo-tray\\clean
    .venv\\Scripts\\python.exe prelabel.py photos\\local-train\\litter photos\\local-train\\clean --out photos\\local-train-prelabel.json
    .venv\\Scripts\\python.exe prelabel.py photos\\demo-tray\\litter --no-predict   # tasks only, no model

Default output: ml/photos/<common parent of the folders, '/' -> '-'>-prelabel.json, e.g.
photos/demo-tray-prelabel.json (git-ignored, like the photos).
"""

from __future__ import annotations

import argparse
import json
import os
import uuid
from pathlib import Path
from urllib.parse import quote

ML = Path(__file__).resolve().parent
PHOTOS = ML / "photos"
IMG_EXT = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}
# ml/photos is mounted at <document root>/photos in the Label Studio container.
URL_PREFIX = "/data/local-files/?d=photos/"


def local_url(img: Path) -> str:
    try:
        rel = img.resolve().relative_to(PHOTOS.resolve())
    except ValueError:
        raise SystemExit(f"{img} is not under {PHOTOS}: Label Studio only sees ml/photos/")
    return URL_PREFIX + quote(rel.as_posix(), safe="/")


def images_in(folders: list[str]) -> list[Path]:
    out = []
    for f in folders:
        d = Path(f)
        if not d.is_absolute() and not d.exists():
            d = ML / d
        if not d.is_dir():
            raise SystemExit(f"folder not found: {f}")
        found = sorted(p for p in d.rglob("*") if p.suffix.lower() in IMG_EXT)
        if not found:
            print(f"warning: no images in {d}")
        out += found
    return out


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("folders", nargs="+", help="image folders under ml/photos/")
    ap.add_argument("--weights", default=str(ML / "runs" / "litter-s640-all" / "weights" / "best.pt"))
    ap.add_argument("--conf", type=float, default=0.15, help="low on purpose: drafts to delete or adjust")
    ap.add_argument("--imgsz", type=int, default=640)
    ap.add_argument("--device", default="cpu", help="cpu by default (keeps the GPU free); '0' for the GPU")
    ap.add_argument("--out", default=None)
    ap.add_argument("--no-predict", action="store_true", help="write tasks without predictions (no model needed)")
    args = ap.parse_args()

    images = images_in(args.folders)
    if not images:
        raise SystemExit("no images found")
    if args.out:
        out = Path(args.out)
    else:
        dirs = [Path(f) if Path(f).is_absolute() or Path(f).exists() else ML / f for f in args.folders]
        common = Path(os.path.commonpath([d.resolve() for d in dirs]))
        try:
            tag = common.relative_to(PHOTOS.resolve()).as_posix().replace("/", "-")
        except ValueError:
            tag = common.name
        out = PHOTOS / f"{tag or 'photos'}-prelabel.json"

    model = None
    version = "none"
    if not args.no_predict:
        if not Path(args.weights).is_file():
            raise SystemExit(f"weights not found: {args.weights} (or use --no-predict)")
        from ultralytics import YOLO  # lazy: --no-predict and --help need no torch

        model = YOLO(args.weights)
        w = Path(args.weights)
        version = f"{w.parent.parent.name}/{w.name}" if w.parent.name == "weights" else w.name

    tasks, n_boxes = [], 0
    for img in images:
        task = {"data": {"image": local_url(img)}}
        if model is not None:
            r = model.predict(str(img), imgsz=args.imgsz, conf=args.conf, device=args.device, verbose=False)[0]
            h, w = r.orig_shape
            result, scores = [], []
            for (x1, y1, x2, y2), c in zip(r.boxes.xyxy.cpu().tolist(), r.boxes.conf.cpu().tolist()):
                x1, y1 = max(0.0, x1), max(0.0, y1)
                x2, y2 = min(float(w), x2), min(float(h), y2)
                if x2 <= x1 or y2 <= y1:
                    continue
                result.append({
                    "id": uuid.uuid4().hex[:10],
                    "from_name": "label", "to_name": "image", "type": "rectanglelabels",
                    "original_width": w, "original_height": h, "image_rotation": 0,
                    "value": {"x": 100 * x1 / w, "y": 100 * y1 / h,
                              "width": 100 * (x2 - x1) / w, "height": 100 * (y2 - y1) / h,
                              "rotation": 0, "rectanglelabels": ["litter"]},
                    "score": round(float(c), 4),
                })
                scores.append(c)
            n_boxes += len(result)
            task["predictions"] = [{"model_version": version, "result": result,
                                    "score": round(float(sum(scores) / len(scores)), 4) if scores else 0.0}]
        tasks.append(task)

    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(tasks, indent=1), encoding="utf-8")
    print(f"{len(tasks)} tasks, {n_boxes} draft boxes (conf >= {args.conf}, model {version}) -> {out}")


if __name__ == "__main__":
    main()
