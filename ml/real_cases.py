"""Real-case test of the DEPLOYED litter model through the live vision service.

Sends real photos to POST /waste (the same path Laravel uses), so it tests the whole
chain: litter.pt, 960 px, threshold 0.185. Photos are real and never trained on:
  - public-test: held-out photos from PlastOPol, TACO and BePLi with their labels
  - negatives_test: real photos with no litter (false-alarm check)
  - data/samples: the demo litter / clean photos (no labels, counts only)

Output: ml/runs/real-cases/{summary.json, per_image.csv, annotated/*.jpg}

    python ml/real_cases.py [--per-dataset 40] [--negatives 60] [--url http://localhost:8001]
"""

from __future__ import annotations

import argparse
import csv
import json
import random
import time
from pathlib import Path

import requests
from PIL import Image, ImageDraw

ML = Path(__file__).resolve().parent
ROOT = ML.parent
OUT = ML / "runs" / "real-cases"


def read_labels(path: Path, w: int, h: int) -> list[list[float]]:
    boxes = []
    if path.exists():
        for line in path.read_text().splitlines():
            p = line.split()
            if len(p) >= 5:
                cx, cy, bw, bh = (float(v) for v in p[1:5])
                boxes.append([(cx - bw / 2) * w, (cy - bh / 2) * h, (cx + bw / 2) * w, (cy + bh / 2) * h])
    return boxes


def iou(a, b) -> float:
    ix = max(0.0, min(a[2], b[2]) - max(a[0], b[0]))
    iy = max(0.0, min(a[3], b[3]) - max(a[1], b[1]))
    inter = ix * iy
    union = (a[2] - a[0]) * (a[3] - a[1]) + (b[2] - b[0]) * (b[3] - b[1]) - inter
    return inter / union if union > 0 else 0.0


def match(preds: list[dict], gts: list[list[float]]) -> tuple[int, int, int]:
    """Greedy IoU 0.5 matching by confidence. Returns tp, fp, fn."""
    used, tp = set(), 0
    for p in sorted(preds, key=lambda d: -d["confidence"]):
        best, best_i = 0.0, -1
        for i, g in enumerate(gts):
            if i not in used and (v := iou(p["box"], g)) > best:
                best, best_i = v, i
        if best >= 0.5:
            used.add(best_i)
            tp += 1
    return tp, len(preds) - tp, len(gts) - tp


def call(url: str, path: Path) -> dict:
    with path.open("rb") as fh:
        r = requests.post(f"{url}/waste", files={"image": (path.name, fh, "image/jpeg")}, timeout=60)
    r.raise_for_status()
    return r.json()


def annotate(path: Path, preds, gts, dest: Path) -> None:
    im = Image.open(path).convert("RGB")
    d = ImageDraw.Draw(im)
    for g in gts:
        d.rectangle(g, outline=(0, 200, 0), width=2)  # green = ground truth
    for p in preds:
        d.rectangle(p["box"], outline=(255, 40, 40), width=2)  # red = prediction
        d.text((p["box"][0] + 2, p["box"][1] + 2), f'{p["confidence"]:.2f}', fill=(255, 255, 0))
    im.thumbnail((960, 960))
    dest.parent.mkdir(parents=True, exist_ok=True)
    im.save(dest, quality=85)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default="http://localhost:8001")
    ap.add_argument("--per-dataset", type=int, default=40)
    ap.add_argument("--negatives", type=int, default=60)
    ap.add_argument("--seed", type=int, default=42)
    a = ap.parse_args()
    rng = random.Random(a.seed)

    health = requests.get(f"{a.url}/health", timeout=10).json()["waste"]
    test_dir = ML / "data" / "yolo" / "images" / "test"
    lab_dir = ML / "data" / "yolo" / "labels" / "test"

    jobs = []  # (group, path, labelled)
    for ds in ("plastopol", "taco", "bepli"):
        files = sorted(p for p in test_dir.glob(f"{ds}__*.jpg") if (lab_dir / f"{p.stem}.txt").read_text().strip())
        jobs += [(ds, p, True) for p in rng.sample(files, min(a.per_dataset, len(files)))]
    neg = sorted((ML / "data" / "extra" / "negatives_test").rglob("*.jpg"))
    jobs += [("negatives", p, True) for p in rng.sample(neg, min(a.negatives, len(neg)))]
    for sub in ("litter", "clean"):
        jobs += [(f"samples-{sub}", p, False) for p in sorted((ROOT / "data" / "samples" / sub).glob("*.jpg"))]

    rows, t0 = [], time.time()
    for group, path, labelled in jobs:
        res = call(a.url, path)
        gts = read_labels(lab_dir / f"{path.stem}.txt", res["image_w"], res["image_h"]) if group in ("plastopol", "taco", "bepli") else []
        tp, fp, fn = match(res["items"], gts) if labelled else (0, 0, 0)
        rows.append({"group": group, "image": path.name, "gt": len(gts), "pred": res["count"], "tp": tp, "fp": fp,
                     "fn": fn, "ms": res["inference_ms"]})
        if group != "negatives" or fp:
            annotate(path, res["items"], gts, OUT / "annotated" / group / path.name)

    OUT.mkdir(parents=True, exist_ok=True)
    with (OUT / "per_image.csv").open("w", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=list(rows[0]))
        w.writeheader()
        w.writerows(rows)

    summary = {"model": "litter-v2-s960 (latest)", "service": health, "seed": a.seed, "images": len(rows),
               "wall_seconds": round(time.time() - t0, 1), "groups": {}}
    for g in dict.fromkeys(r["group"] for r in rows):
        rs = [r for r in rows if r["group"] == g]
        tp, fp, fn = (sum(r[k] for r in rs) for k in ("tp", "fp", "fn"))
        entry = {"images": len(rs), "median_ms": sorted(r["ms"] for r in rs)[len(rs) // 2]}
        if g in ("plastopol", "taco", "bepli"):
            entry |= {"gt_boxes": sum(r["gt"] for r in rs), "tp": tp, "fp": fp, "fn": fn,
                      "precision": round(tp / (tp + fp), 3) if tp + fp else None,
                      "recall": round(tp / (tp + fn), 3) if tp + fn else None,
                      "images_with_a_hit": sum(1 for r in rs if r["tp"] > 0)}
        elif g == "negatives":
            entry |= {"false_boxes": fp, "fp_per_photo": round(fp / len(rs), 3),
                      "photos_with_false_alarm": sum(1 for r in rs if r["fp"])}
        else:
            entry |= {"boxes_found": sum(r["pred"] for r in rs), "images_with_boxes": sum(1 for r in rs if r["pred"])}
        summary["groups"][g] = entry
    (OUT / "summary.json").write_text(json.dumps(summary, indent=2))
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()
