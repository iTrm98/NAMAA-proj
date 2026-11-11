"""Convert PlastOPol, TACO and BePLi into one YOLO dataset with a single class `litter`.

Input (unzipped, see README.md):
    ml/data/raw/plastopol/x/...   PlastOPol full release (Zenodo 5829156)
    ml/data/raw/taco/x/...        TACO image bundle (Zenodo 3587843)
    ml/data/raw/taco/annotations.json   official TACO annotations (never the unofficial file)
    ml/data/raw/bepli/x/...       BePLi v1 (SEANOE 10.17882/92297)

Output:
    ml/data/yolo/images/{train,val,test}/<dataset>__<name>.jpg
    ml/data/yolo/labels/{train,val,test}/<dataset>__<name>.txt
    ml/data/yolo/lists/test_<dataset>.txt, lists/commercial_safe_{train,val,test}.txt
    ml/data/yolo/summary.json

Rules:
- Every annotation becomes class 0 `litter`. Boxes come from the COCO `bbox`; if it is
  missing, from the segmentation polygon.
- Images are written without EXIF, in the pixel orientation the annotations use, and
  downscaled to at most --max-side (default 1280) on the long side.
- Boxes smaller than 6 px on either side at 640 px long side are dropped. An image whose
  boxes were all dropped is dropped too (it is not a clean image). Images that had no
  annotations at all are kept as background images.
- Exact duplicate images (same file bytes), within and across datasets, are kept once.
- Split per dataset 80/10/10 (train/val/test) with a fixed seed. `test` is the public test
  set: never used for training or threshold tuning.

Usage: python prepare_data.py [--datasets plastopol taco bepli] [--seed 42]
"""

from __future__ import annotations

import argparse
import hashlib
import json
import random
import shutil
from collections import defaultdict
from pathlib import Path

from PIL import Image, ImageOps

ML = Path(__file__).resolve().parent
RAW = ML / "data" / "raw"
OUT = ML / "data" / "yolo"
SPLITS = ("train", "val", "test")
MIN_SIDE_AT_640 = 6.0


# --------------------------------------------------------------------------- loaders
# Each loader yields (dataset, source_image_path, ann_width, ann_height, [ (x, y, w, h), ... ])
# with boxes in the pixel coordinates of an image of size ann_width x ann_height.

def _box_from_coco(a: dict) -> tuple[float, float, float, float] | None:
    bbox = a.get("bbox")
    if bbox and len(bbox) == 4 and bbox[2] > 0 and bbox[3] > 0:
        return tuple(map(float, bbox))
    seg = a.get("segmentation")
    if isinstance(seg, list) and seg:
        xs = [p for poly in seg for p in poly[0::2]]
        ys = [p for poly in seg for p in poly[1::2]]
        if xs and ys:
            return min(xs), min(ys), max(xs) - min(xs), max(ys) - min(ys)
    return None


def load_coco(dataset: str, ann_file: Path, image_root: Path):
    coco = json.loads(ann_file.read_text(encoding="utf-8"))
    by_image = defaultdict(list)
    for a in coco["annotations"]:
        b = _box_from_coco(a)
        if b:
            by_image[a["image_id"]].append(b)
    index = None
    for im in coco["images"]:
        path = image_root / im["file_name"]
        if not path.is_file():
            if index is None:  # fall back to a name lookup when folders differ
                index = {p.name: p for p in image_root.rglob("*") if p.is_file()}
            path = index.get(Path(im["file_name"]).name)
        if path is None:
            print(f"  [{dataset}] missing image {im['file_name']}")
            continue
        yield dataset, path, im["width"], im["height"], by_image.get(im["id"], [])


def find_one(root: Path, pattern: str) -> Path:
    hits = sorted(root.rglob(pattern))
    if not hits:
        raise SystemExit(f"nothing matches {pattern} under {root}")
    return hits[0]


def load_taco():
    root = RAW / "taco" / "x"
    yield from load_coco("taco", RAW / "taco" / "annotations.json", find_one(root, "batch_1").parent)


def load_bepli():
    # all_plastic_coco.json covers every image in images/original_images (the release's own
    # train/val/test folders are not used: we split ourselves). Segmentations are RLE with
    # iscrowd=1 by design (single objects, see the release README), so every bbox is kept.
    root = RAW / "bepli" / "x" / "plastic_coco"
    yield from load_coco("bepli", root / "annotation" / "all_plastic_coco.json", root / "images" / "original_images")


def load_plastopol():
    # The release ships images.zip and annotation folds; full_plastopol.json holds all
    # 2418 images. We make our own 80/10/10 split (same rule for every dataset).
    root = RAW / "plastopol" / "x"
    yield from load_coco("plastopol", root / "ann" / "annotations" / "full_plastopol.json", root / "images")


LOADERS = {"plastopol": load_plastopol, "taco": load_taco, "bepli": load_bepli}


# --------------------------------------------------------------------------- conversion

def open_oriented(path: Path, w: int, h: int) -> Image.Image | None:
    """Open the image in the orientation the annotations were made for.

    EXIF rotation applied first (TACO's own demo rotates by EXIF before drawing the
    annotations; a 180-degree flip cannot be detected from the size). Raw pixels if only
    they match the annotated size; None if neither matches.
    """
    im = Image.open(path)
    im.load()
    t = ImageOps.exif_transpose(im)
    if t.size == (w, h):
        return t
    if im.size == (w, h):
        return im
    return None


def size_bucket(bw: float, bh: float, scale: float) -> str:
    """COCO size buckets measured at 640 px long side."""
    area = bw * scale * bh * scale
    return "small" if area < 32 ** 2 else "medium" if area < 96 ** 2 else "large"


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--datasets", nargs="+", default=list(LOADERS))
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--max-side", type=int, default=1280)
    args = ap.parse_args()

    if OUT.exists():
        shutil.rmtree(OUT)
    for s in SPLITS:
        (OUT / "images" / s).mkdir(parents=True)
        (OUT / "labels" / s).mkdir(parents=True)

    seen_hashes: set[str] = set()
    summary: dict = {}
    for ds in args.datasets:
        print(f"[{ds}] loading")
        items, log = [], defaultdict(int)
        for _, path, w, h, boxes in LOADERS[ds]():
            log["images_in"] += 1
            log["boxes_in"] += len(boxes)
            digest = hashlib.md5(path.read_bytes()).hexdigest()
            if digest in seen_hashes:
                log["duplicates_removed"] += 1
                continue
            seen_hashes.add(digest)
            scale640 = 640 / max(w, h)
            kept = []
            for x, y, bw, bh in boxes:
                x, y = max(0.0, x), max(0.0, y)
                bw, bh = min(bw, w - x), min(bh, h - y)
                if bw * scale640 < MIN_SIDE_AT_640 or bh * scale640 < MIN_SIDE_AT_640:
                    log["tiny_boxes_dropped"] += 1
                    continue
                kept.append((x, y, bw, bh))
            if boxes and not kept:
                log["images_dropped_all_tiny"] += 1
                continue
            items.append((path, w, h, kept))

        rng = random.Random(args.seed)
        items.sort(key=lambda t: str(t[0]))
        rng.shuffle(items)
        n = len(items)
        n_val, n_test = max(1, round(n * 0.1)), max(1, round(n * 0.1))
        split_of = ["test"] * n_test + ["val"] * n_val + ["train"] * (n - n_val - n_test)

        stats = {s: {"images": 0, "background": 0, "boxes": 0, "small": 0, "medium": 0, "large": 0} for s in SPLITS}
        for (path, w, h, kept), split in zip(items, split_of):
            im = open_oriented(path, w, h)
            if im is None:
                log["orientation_mismatch_dropped"] += 1
                continue
            im = im.convert("RGB")
            name = f"{ds}__{path.stem}"
            if (OUT / "images" / split / f"{name}.jpg").exists():
                name = f"{ds}__{path.parent.name}_{path.stem}"
            scale = min(1.0, args.max_side / max(w, h))
            if scale < 1.0:
                im = im.resize((round(w * scale), round(h * scale)), Image.LANCZOS)
            im.save(OUT / "images" / split / f"{name}.jpg", quality=92)
            lines = []
            st = stats[split]
            for x, y, bw, bh in kept:
                lines.append(f"0 {(x + bw / 2) / w:.6f} {(y + bh / 2) / h:.6f} {bw / w:.6f} {bh / h:.6f}")
                st[size_bucket(bw, bh, 640 / max(w, h))] += 1
            (OUT / "labels" / split / f"{name}.txt").write_text("\n".join(lines) + ("\n" if lines else ""))
            st["images"] += 1
            st["boxes"] += len(kept)
            st["background"] += 0 if kept else 1
        summary[ds] = {"log": dict(log), "splits": stats}
        print(f"[{ds}] {json.dumps(summary[ds])}")

    # Image lists: per dataset test lists, and the commercial-safe set (no BePLi).
    lists = OUT / "lists"
    lists.mkdir()
    for ds in args.datasets:
        files = sorted(p.as_posix() for p in (OUT / "images" / "test").glob(f"{ds}__*.jpg"))
        (lists / f"test_{ds}.txt").write_text("\n".join(files) + "\n")
    for s in SPLITS:
        files = sorted(p.as_posix() for p in (OUT / "images" / s).glob("*.jpg") if not p.name.startswith("bepli__"))
        (lists / f"commercial_safe_{s}.txt").write_text("\n".join(files) + "\n")
    (OUT / "summary.json").write_text(json.dumps(summary, indent=2))
    print(f"wrote {OUT}")


if __name__ == "__main__":
    main()
