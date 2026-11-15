"""Build the litter AI v2 training set: public sets + extra ground-level sets + background images.

Run it after fetch_extra_data.py and coco_backgrounds.py. It copies nothing from the public
sets (list files only) and writes the cleaned extra sets to ml/data/extra/yolo/.

What it does, in order:
 1. Reads every Roboflow set in ml/data/extra/raw/<id>/ (yolov8 export + SOURCE.json).
 2. Cleans each image and label: drops unreadable or tiny images, maps EVERY class to class 0
    (`litter`), turns polygons into boxes, clips boxes to the image, drops broken boxes
    (NaN, zero or negative size, outside the image) and tiny boxes (a side under 6 px at 640 px,
    the same rule as prepare_data.py), and drops images left with no valid box (an unlabelled
    image from a litter set is not a safe "background").
 3. Deduplicates by image hash: exact (md5) and near-duplicate (64-bit dHash, Hamming <= 4)
    against the public train/val/test images and against each other, so nothing from the public
    test split (or the extra test/val split) can sit in train under another name.
 4. Splits: extra `train` -> train, extra `valid` -> val, extra `test` -> ml/data/extra/lists/extra_test.txt
    (held out, never trained on). The public test split is not touched: litter_v2.yaml still tests on
    images/test, litter_v2_commercial_safe.yaml on lists/commercial_safe_test.txt.
 5. Adds COCO background images (empty labels) so they are --bg-share of the train list (default 20%)
    and the same share of val (taken from the unused backgrounds). ml/data/extra/negatives_test is never used here.
 6. Writes ml/litter_v2.yaml and ml/litter_v2_commercial_safe.yaml (absolute paths).
    The commercial-safe list leaves out non-commercial sets (BePLi, any extra set whose licence
    is not on the allowed list) and COCO images whose own licence is non-commercial or no-derivatives.

    python build_v2.py
    python build_v2.py --bg-share 0.2 --max-per-source 3000 --seed 42
"""

from __future__ import annotations

import argparse
import hashlib
import json
import random
import shutil
import sys
from collections import Counter, defaultdict
from pathlib import Path

import numpy as np
from PIL import Image

ML = Path(__file__).resolve().parent
YOLO = ML / "data" / "yolo"
EXTRA = ML / "data" / "extra"
RAW = EXTRA / "raw"
OUT = EXTRA / "yolo"
LISTS = EXTRA / "lists"
IMG_EXT = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}
MIN_SIDE_AT_640 = 6.0      # same as prepare_data.py
HASH_DIST = 4              # near-duplicate: dHash Hamming distance
COCO_COMMERCIAL = {4, 5, 7, 8}  # CC BY, CC BY-SA, no known copyright, US Gov


def md5(p: Path) -> str:
    return hashlib.md5(p.read_bytes()).hexdigest()


def dhash(img: Image.Image) -> int:
    g = np.asarray(img.convert("L").resize((9, 8), Image.BILINEAR), dtype=np.int16)
    bits = (g[:, 1:] > g[:, :-1]).flatten()
    return int("".join("1" if b else "0" for b in bits), 2)


def hamming(a: int, b: int) -> int:
    return bin(a ^ b).count("1")


class NearIndex:
    """Exact + near-duplicate lookup. Near search is a numpy popcount over all stored hashes."""

    def __init__(self) -> None:
        self.md5: set[str] = set()
        self.hashes: list[int] = []
        self._arr: np.ndarray | None = None

    def add(self, m: str, h: int) -> None:
        self.md5.add(m)
        self.hashes.append(h)
        self._arr = None

    def has(self, m: str, h: int) -> str | None:
        if m in self.md5:
            return "exact"
        if not self.hashes:
            return None
        if self._arr is None:
            self._arr = np.array(self.hashes, dtype=np.uint64)
        x = self._arr ^ np.uint64(h)
        counts = np.zeros(len(x), dtype=np.int32)
        for shift in range(0, 64, 8):  # popcount via byte table
            counts += POP[((x >> np.uint64(shift)) & np.uint64(255)).astype(np.int64)]
        return "near" if counts.min() <= HASH_DIST else None


# Class names that are never litter. A box of one of these is dropped BEFORE all classes are merged into
# `litter`, otherwise a set that also labels people or bins would teach the model that they are litter.
NOT_LITTER = {"person", "people", "human", "pedestrian", "face", "man", "woman", "child", "bucket", "bin",
              "trash_bin", "trashcan", "trash_can", "dustbin", "car", "vehicle", "truck", "bus", "bike",
              "bicycle", "motorcycle", "dog", "cat", "animal", "background", "tree", "plant"}


def class_names(src: Path) -> list[str]:
    """`names` from the export's data.yaml (list or {id: name} form), no yaml dependency."""
    text = (src / "data.yaml").read_text(encoding="utf-8", errors="replace")
    import re
    m = re.search(r"^names:\s*\[(.*?)\]", text, re.M | re.S)
    if m:
        return [x.strip().strip("'\"") for x in m.group(1).split(",") if x.strip()]
    out: dict[int, str] = {}
    in_names = False
    for line in text.splitlines():
        if line.startswith("names:"):
            in_names = True
            continue
        if in_names:
            mm = re.match(r"\s+(\d+):\s*(.+)", line)
            if not mm:
                break
            out[int(mm.group(1))] = mm.group(2).strip().strip("'\"")
    return [out[i] for i in sorted(out)]


POP = np.array([bin(i).count("1") for i in range(256)], dtype=np.int32)


def parse_label(path: Path, w: int, h: int, log: Counter,
                drop_ids: frozenset[int] = frozenset()) -> list[tuple[float, float, float, float]]:
    """Valid YOLO boxes (cx, cy, bw, bh), all classes merged. Counts every drop in `log`."""
    out = []
    if not path.is_file():
        return out
    scale = 640 / max(w, h)
    for line in path.read_text(errors="replace").splitlines():
        v = line.split()
        if len(v) < 5:
            if v:
                log["box_malformed"] += 1
            continue
        try:
            if int(float(v[0])) in drop_ids:
                log["box_not_litter_class"] += 1
                continue
            nums = [float(x) for x in v[1:]]
        except ValueError:
            log["box_malformed"] += 1
            continue
        if not all(np.isfinite(nums)):
            log["box_malformed"] += 1
            continue
        if len(nums) == 4:
            cx, cy, bw, bh = nums
            x1, y1, x2, y2 = cx - bw / 2, cy - bh / 2, cx + bw / 2, cy + bh / 2
        else:  # polygon: x1 y1 x2 y2 ... -> bounding box
            if len(nums) % 2:
                log["box_malformed"] += 1
                continue
            xs, ys = nums[0::2], nums[1::2]
            x1, y1, x2, y2 = min(xs), min(ys), max(xs), max(ys)
            log["polygon_to_box"] += 1
        if x2 <= x1 or y2 <= y1:
            log["box_non_positive"] += 1
            continue
        if x1 < -0.02 or y1 < -0.02 or x2 > 1.02 or y2 > 1.02:
            log["box_outside"] += 1
            continue
        x1, y1, x2, y2 = (min(1.0, max(0.0, t)) for t in (x1, y1, x2, y2))
        bw, bh = x2 - x1, y2 - y1
        if bw * w * scale < MIN_SIDE_AT_640 or bh * h * scale < MIN_SIDE_AT_640:
            log["box_tiny"] += 1
            continue
        out.append(((x1 + x2) / 2, (y1 + y2) / 2, bw, bh))
    return out


def reference_index(log: Counter) -> NearIndex:
    """Hashes of every public image (train, val, test): extras must not duplicate any of them."""
    idx = NearIndex()
    files = sorted(p for s in ("train", "val", "test") for p in (YOLO / "images" / s).iterdir())
    print(f"hashing {len(files)} public images ...", flush=True)
    for p in files:
        with Image.open(p) as im:
            idx.add(md5(p), dhash(im))
    return idx


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--bg-share", type=float, default=0.20, help="background share of train and val (0.10-0.20)")
    ap.add_argument("--max-per-source", type=int, default=3000, help="cap on kept train images per extra set (0 = none)")
    ap.add_argument("--exclude", nargs="*", default=[], help="source ids in ml/data/extra/raw to leave out (e.g. rf_epykz)")
    ap.add_argument("--seed", type=int, default=42)
    args = ap.parse_args()
    rng = random.Random(args.seed)

    for need in (YOLO / "lists" / "balanced_train.txt", EXTRA / "backgrounds" / "images"):
        if not need.exists():
            raise SystemExit(f"missing {need}")
    sources = sorted(d for d in RAW.glob("*") if (d / "SOURCE.json").is_file() and d.name not in args.exclude) if RAW.is_dir() else []
    if not sources:
        print("No extra sets in ml/data/extra/raw/: only public data + backgrounds will be built "
              "(run fetch_extra_data.py first for the full v2 set).")

    if OUT.exists():
        shutil.rmtree(OUT)  # our own generated folder: rebuild from scratch
    if LISTS.exists():
        shutil.rmtree(LISTS)
    LISTS.mkdir(parents=True)

    ref_log: Counter = Counter()
    index = reference_index(ref_log)

    # --- extra sets -------------------------------------------------------------------------
    kept: dict[str, dict[str, list[Path]]] = defaultdict(lambda: defaultdict(list))  # id -> split -> images
    commercial: dict[str, bool] = {}
    report: dict[str, Counter] = {}
    # priority: test, valid, then train, so a train image never survives next to its val/test twin
    order = {"test": 0, "valid": 1, "val": 1, "train": 2}
    for src in sources:
        meta = json.loads((src / "SOURCE.json").read_text(encoding="utf-8"))
        sid = meta["id"]
        commercial[sid] = bool(meta.get("commercial_ok"))
        log: Counter = Counter()
        report[sid] = log
        names = class_names(src)
        drop_ids = frozenset(i for i, n in enumerate(names) if n.strip().lower().replace(" ", "_") in NOT_LITTER)
        print(f"[{sid}] classes {names}; dropped (not litter): {[names[i] for i in sorted(drop_ids)] or 'none'}")
        for split in sorted((s for s in order if (src / s / "images").is_dir()), key=order.get):
            tgt = "val" if split in ("valid", "val") else split
            files = sorted(p for p in (src / split / "images").iterdir() if p.suffix.lower() in IMG_EXT)
            if split == "train":
                rng.shuffle(files)
            for p in files:
                log[f"{tgt}_seen"] += 1
                if split == "train" and args.max_per_source and len(kept[sid]["train"]) >= args.max_per_source:
                    log["train_over_cap"] += 1
                    continue
                try:
                    with Image.open(p) as im:
                        im.load()
                        w, h = im.size
                        if min(w, h) < 64:
                            log["image_tiny"] += 1
                            continue
                        hh = dhash(im)
                except Exception:
                    log["image_corrupt"] += 1
                    continue
                m = md5(p)
                dup = index.has(m, hh)
                if dup:
                    log[f"dup_{dup}"] += 1
                    continue
                boxes = parse_label(src / split / "labels" / f"{p.stem}.txt", w, h, log, drop_ids)
                if not boxes:
                    log["image_no_valid_box"] += 1
                    continue
                index.add(m, hh)
                name = f"{sid}__{p.stem[:80]}"
                for sub in ("images", "labels"):
                    (OUT / sub / tgt).mkdir(parents=True, exist_ok=True)
                shutil.copyfile(p, OUT / "images" / tgt / f"{name}{p.suffix.lower()}")
                (OUT / "labels" / tgt / f"{name}.txt").write_text(
                    "".join(f"0 {a:.6f} {b:.6f} {c:.6f} {d:.6f}\n" for a, b, c, d in boxes))
                kept[sid][tgt].append(OUT / "images" / tgt / f"{name}{p.suffix.lower()}")
                log[f"{tgt}_kept"] += 1
                log["boxes_kept"] += len(boxes)

    def listed(split: str, only_commercial: bool) -> list[str]:
        return [x.as_posix() for sid in sorted(kept) if commercial[sid] or not only_commercial
                for x in sorted(kept[sid][split])]

    # --- backgrounds ------------------------------------------------------------------------
    coco = json.loads((EXTRA / "coco_backgrounds.json").read_text())
    bg_all = sorted((EXTRA / "backgrounds" / "images").iterdir())
    bg_safe = [p for p in bg_all if coco["image_licence"].get(p.stem) in COCO_COMMERCIAL]

    def read_list(name: str) -> list[str]:
        return [l.strip() for l in (YOLO / "lists" / name).read_text().splitlines() if l.strip()]

    def with_bg(base: list[str], pool: list[Path], used: set[Path], share: float) -> tuple[list[str], list[Path]]:
        n = round(len(base) * share / (1 - share))
        free = [p for p in pool if p not in used]
        rng.shuffle(free)
        pick = free[:n]
        return base + [p.as_posix() for p in pick], pick

    sets = {}
    used_all: set[Path] = set()
    for tag, only_c in (("v2", False), ("v2_commercial_safe", True)):
        tr = (read_list("commercial_safe_train.txt") if only_c else read_list("balanced_train.txt")) + listed("train", only_c)
        va = (read_list("commercial_safe_val.txt") if only_c else read_list("balanced_val.txt")) + listed("val", only_c)
        pool = bg_safe if only_c else bg_all
        used: set[Path] = set()
        va, bv = with_bg(va, pool, used, args.bg_share)  # val first: a small pool must not starve it
        used |= set(bv)
        tr, bt = with_bg(tr, pool, used, args.bg_share)
        sets[tag] = (tr, va, len(bt), len(bv), len(pool))
        rng.shuffle(tr)
        (LISTS / f"{tag}_train.txt").write_text("\n".join(tr) + "\n")
        (LISTS / f"{tag}_val.txt").write_text("\n".join(sorted(va)) + "\n")
    extra_test = listed("test", False)
    (LISTS / "extra_test.txt").write_text("\n".join(extra_test) + ("\n" if extra_test else ""))

    # --- yaml -------------------------------------------------------------------------------
    (ML / "litter_v2.yaml").write_text(f"""# Litter AI v2: PlastOPol + TACO + BePLi (balanced, as litter_balanced.yaml) + extra ground-level sets
# (ml/extra_sources.json) + COCO background images (empty labels, ~{args.bg_share:.0%} of train/val).
# BePLi is CC BY-NC-SA 4.0 (non-commercial): use litter_v2_commercial_safe.yaml for any commercial model.
# Lists are written by build_v2.py. `test` is the unchanged public-test split: never train or tune on it.
# False alarms are measured on ml/data/extra/negatives_test (never listed here).
path: {ML.as_posix()}/data/yolo
train: {LISTS.as_posix()}/v2_train.txt
val: {LISTS.as_posix()}/v2_val.txt
test: {YOLO.as_posix()}/images/test
names:
  0: litter
""")
    (ML / "litter_v2_commercial_safe.yaml").write_text(f"""# Litter AI v2 without non-commercial data: PlastOPol + TACO + extra sets with a commercial-use licence
# + COCO background images whose own licence allows commercial use. No BePLi.
# Lists are written by build_v2.py. `test` is the public commercial-safe test split (unchanged).
path: {ML.as_posix()}/data/yolo
train: {LISTS.as_posix()}/v2_commercial_safe_train.txt
val: {LISTS.as_posix()}/v2_commercial_safe_val.txt
test: {YOLO.as_posix()}/lists/commercial_safe_test.txt
names:
  0: litter
""")

    # --- report -----------------------------------------------------------------------------
    print("\nExtra sets (after cleaning):")
    for sid, log in report.items():
        print(f"  {sid}: " + ", ".join(f"{k}={v}" for k, v in sorted(log.items())) +
              ("" if commercial[sid] else "   [NON-COMMERCIAL: left out of commercial-safe]"))
    for tag, (tr, va, nbt, nbv, pool) in sets.items():
        print(f"\n{tag}: train {len(tr)} images ({nbt} background = {nbt / len(tr):.1%}), "
              f"val {len(va)} ({nbv} background = {nbv / len(va):.1%}); background pool {pool}")
    print(f"extra_test (held out): {len(extra_test)} images")
    print(f"wrote {ML / 'litter_v2.yaml'}\nwrote {ML / 'litter_v2_commercial_safe.yaml'}\nlists in {LISTS}")
    (EXTRA / "build_v2_report.json").write_text(json.dumps(
        {sid: dict(l) for sid, l in report.items()}, indent=2))


if __name__ == "__main__":
    if sys.platform == "win32":
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    main()
