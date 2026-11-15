"""Check the litter AI v2 dataset written by build_v2.py (read-only except ml/data/extra/_check/).

Reports, for litter_v2.yaml (and the commercial-safe lists):
  - images and boxes per source in train and val, background share
  - class ids (must all be 0), empty / corrupt / missing label or image files
  - train/val overlap by path, and train/val vs test overlap by exact hash and near-duplicate hash
    (tests: public test split, extra_test, negatives_test)
Then draws boxes on a few images per source into ml/data/extra/_check/ (SAMPLES per source) so you can eyeball them.

    python check_v2.py [--samples 4] [--seed 1]
"""

from __future__ import annotations

import argparse
import random
import shutil
import sys
from collections import Counter, defaultdict
from pathlib import Path

from PIL import Image, ImageDraw

import build_v2 as b

ML = b.ML
EXTRA = b.EXTRA
LISTS = b.LISTS
CHECK = EXTRA / "_check"


def label_for(img: Path) -> Path:
    parts = list(img.parts)
    i = len(parts) - 1 - parts[::-1].index("images")
    parts[i] = "labels"
    return Path(*parts).with_suffix(".txt")


def source_of(img: Path) -> str:
    return img.stem.split("__")[0]


def read_list(p: Path) -> list[Path]:
    return [Path(l.strip()) for l in p.read_text().splitlines() if l.strip()]


def check_list(name: str, files: list[Path]) -> dict[str, list]:
    stats = defaultdict(lambda: Counter())
    problems = []
    for img in files:
        src = source_of(img)
        st = stats[src]
        st["images"] += 1
        if not img.is_file() or img.stat().st_size == 0:
            problems.append(f"{name}: missing/empty image {img}")
            continue
        try:
            with Image.open(img) as im:
                im.verify()
        except Exception:
            problems.append(f"{name}: corrupt image {img}")
            continue
        lp = label_for(img)
        if not lp.is_file():
            problems.append(f"{name}: no label file for {img.name}")
            continue
        lines = [l for l in lp.read_text().splitlines() if l.strip()]
        if not lines:
            st["background"] += 1
        for line in lines:
            v = line.split()
            if len(v) != 5 or v[0] != "0":
                st["bad_line"] += 1
                problems.append(f"{name}: bad label line in {lp.name}: {line[:40]}")
                continue
            c = [float(x) for x in v[1:]]
            if not all(0 <= x <= 1 for x in c) or c[2] <= 0 or c[3] <= 0:
                problems.append(f"{name}: box out of range in {lp.name}")
                continue
            st["boxes"] += 1
    return {"stats": stats, "problems": problems}


def draw(img: Path, out: Path) -> None:
    with Image.open(img) as im:
        im = im.convert("RGB")
        w, h = im.size
        d = ImageDraw.Draw(im)
        lp = label_for(img)
        n = 0
        for line in lp.read_text().splitlines():
            v = line.split()
            if len(v) >= 5:
                cx, cy, bw, bh = (float(x) for x in v[1:5])
                d.rectangle([(cx - bw / 2) * w, (cy - bh / 2) * h, (cx + bw / 2) * w, (cy + bh / 2) * h],
                            outline=(255, 0, 0), width=max(2, w // 200))
                n += 1
        d.text((6, 6), f"{img.stem[:40]}  boxes={n}", fill=(255, 255, 0))
        scale = 800 / max(w, h)
        if scale < 1:
            im = im.resize((round(w * scale), round(h * scale)))
        im.save(out, quality=85)


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--samples", type=int, default=4, help="sample images per source")
    ap.add_argument("--seed", type=int, default=1)
    args = ap.parse_args()
    rng = random.Random(args.seed)

    problems: list[str] = []
    train = read_list(LISTS / "v2_train.txt")
    val = read_list(LISTS / "v2_val.txt")
    for split, files in (("train", train), ("val", val)):
        r = check_list(split, files)
        problems += r["problems"]
        print(f"\nlitter_v2 {split}: {len(files)} images")
        print(f"  {'source':<12}{'images':>8}{'boxes':>8}{'background':>11}")
        for src, st in sorted(r["stats"].items()):
            print(f"  {src:<12}{st['images']:>8}{st['boxes']:>8}{st['background']:>11}")
        bg = sum(st["background"] for st in r["stats"].values())
        print(f"  background share: {bg / len(files):.1%}")
    for tag in ("v2_commercial_safe",):
        tr = read_list(LISTS / f"{tag}_train.txt")
        va = read_list(LISTS / f"{tag}_val.txt")
        bad_src = {source_of(p) for p in tr + va} & {"bepli"}
        print(f"\n{tag}: train {len(tr)}, val {len(va)}; non-commercial sources present: {sorted(bad_src) or 'none'}")
        if bad_src:
            problems.append(f"{tag} contains {bad_src}")

    # overlaps
    print("\nOverlap checks")
    both = set(map(str, train)) & set(map(str, val))
    print(f"  train/val shared paths: {len(both)}")
    if both:
        problems.append("train/val share paths")
    tests = {"public-test": sorted((ML / "data" / "yolo" / "images" / "test").iterdir()),
             "extra_test": read_list(LISTS / "extra_test.txt"),
             "negatives_test": sorted((EXTRA / "negatives_test" / "images").iterdir())}
    import numpy as np
    th, tn, tmd5 = [], [], set()
    for files in tests.values():
        for p in files:
            with Image.open(p) as im:
                th.append(b.dhash(im))
            tn.append(p.name)
            tmd5.add(b.md5(p))
    arr = np.array(th, dtype=np.uint64)
    exact = 0
    near = Counter()  # (train/val source, test source) -> count, dHash distance <= 4
    path_overlap = set(map(str, train + val)) & {str(p) for fs in tests.values() for p in fs}
    for p in train + val:
        exact += b.md5(p) in tmd5
        with Image.open(p) as im:
            h = b.dhash(im)
        d = np.array([bin(int(v)).count("1") for v in arr ^ np.uint64(h)])
        if d.min() <= b.HASH_DIST:
            near[(source_of(p), tn[int(d.argmin())].split("__")[0])] += 1
    same = sum(v for (a_, b_), v in near.items() if a_ == b_)
    print(f"  tests: " + ", ".join(f"{k} {len(v)}" for k, v in tests.items()))
    print(f"  train+val images byte-identical to a test image: {exact}; same path: {len(path_overlap)}")
    print(f"  near-duplicate (dHash <= {b.HASH_DIST}) of a test image: {sum(near.values())} "
          f"({same} within the same source, {sum(near.values()) - same} across sources = coincidences on plain scenes)")
    print(f"    same-source pairs: { {k: v for k, v in near.items() if k[0] == k[1]} }")
    if exact or path_overlap:
        problems.append("train/val overlap with a test set")
    if any(k[0] == k[1] and k[0].startswith(("rf_", "coco")) for k in near):
        problems.append("extra/background image near-duplicates a test image of its own source")

    # sample images
    if CHECK.exists():
        shutil.rmtree(CHECK)
    CHECK.mkdir(parents=True)
    by_src = defaultdict(list)
    for p in train:
        by_src[source_of(p)].append(p)
    for p in read_list(LISTS / "extra_test.txt"):
        by_src["extra_test"].append(p)
    n = 0
    for src, files in sorted(by_src.items()):
        pool = [p for p in files if label_for(p).read_text().strip()] if src != "coco" else files
        for p in rng.sample(pool, min(args.samples if src != "rf_ltp18" else args.samples * 2, len(pool))):
            draw(p, CHECK / f"{src}__{p.stem[len(src) + 2:][:30]}.jpg")
            n += 1
    print(f"\nwrote {n} sample images with boxes to {CHECK}")
    print(f"\nPROBLEMS: {len(problems)}")
    for x in problems[:20]:
        print("  " + x)


if __name__ == "__main__":
    if sys.platform == "win32":
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    main()
