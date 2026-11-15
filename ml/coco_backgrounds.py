"""Build the "not litter" images for litter AI v2 from COCO val2017 (CC BY 4.0 annotations, Flickr images).

Keeps only val2017 images that contain NONE of the litter-like COCO objects below (a bottle or
a cup lying in a park could be litter, so those images are not safe "background"), shuffles
them with a fixed seed and splits them:

    ml/data/extra/backgrounds/{images,labels}      2000 images, empty label files (trained on as background)
    ml/data/extra/negatives_test/{images,labels}    500 other images (NEVER trained on; used by
                                                    evaluate.py to count false positives per image)

No secrets involved. Downloads (~1 GB, once) go to ml/data/extra/_downloads/coco/. Run from anywhere:
    python coco_backgrounds.py
    python coco_backgrounds.py --n-train 2000 --n-test 500 --seed 42
Licence: annotations CC BY 4.0; the images keep their individual Flickr licences (see COCO terms
of use). Used only to train/test NAMAA's detector; never committed (ml/data/ is git-ignored).
"""

from __future__ import annotations

import argparse
import hashlib
import json
import random
import shutil
import sys
import zipfile
from pathlib import Path

import requests

ML = Path(__file__).resolve().parent
EXTRA = ML / "data" / "extra"
DL = EXTRA / "_downloads" / "coco"
IMG_URL = "http://images.cocodataset.org/zips/val2017.zip"
ANN_URL = "http://images.cocodataset.org/annotations/annotations_trainval2017.zip"
ANN_MEMBER = "annotations/instances_val2017.json"

EXCLUDE = ["bottle", "cup", "wine glass", "bowl", "fork", "knife", "spoon", "banana", "apple", "orange",
           "sandwich", "pizza", "donut", "cake", "hot dog", "broccoli", "carrot"]


def fetch(url: str, dest: Path) -> None:
    if dest.exists() and dest.stat().st_size > 0:
        print(f"have {dest.name} ({dest.stat().st_size / 1e6:.0f} MB)")
        return
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = dest.with_suffix(dest.suffix + ".part")
    print(f"downloading {url}")
    with requests.get(url, stream=True, timeout=120) as r:
        r.raise_for_status()
        total = int(r.headers.get("content-length", 0))
        done = 0
        with open(tmp, "wb") as f:
            for chunk in r.iter_content(1 << 20):
                f.write(chunk)
                done += len(chunk)
                if total and done % (50 << 20) < (1 << 20):
                    print(f"  {done / 1e6:.0f}/{total / 1e6:.0f} MB", flush=True)
    tmp.rename(dest)


def write_split(zf: zipfile.ZipFile, names: dict[int, str], ids: list[int], out: Path) -> None:
    img_dir, lab_dir = out / "images", out / "labels"
    for d in (img_dir, lab_dir):
        if d.exists():
            shutil.rmtree(d)  # rebuild from scratch: our own generated folder
        d.mkdir(parents=True)
    for i in ids:
        stem = f"coco__{i:012d}"
        (img_dir / f"{stem}.jpg").write_bytes(zf.read(f"val2017/{names[i]}"))
        (lab_dir / f"{stem}.txt").write_text("")  # empty label file = background


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--n-train", type=int, default=2000)
    ap.add_argument("--n-test", type=int, default=500)
    ap.add_argument("--seed", type=int, default=42)
    args = ap.parse_args()

    img_zip, ann_zip = DL / "val2017.zip", DL / "annotations_trainval2017.zip"
    fetch(ANN_URL, ann_zip)
    fetch(IMG_URL, img_zip)

    with zipfile.ZipFile(ann_zip) as z:
        coco = json.loads(z.read(ANN_MEMBER))
    cat = {c["id"]: c["name"] for c in coco["categories"]}
    missing = [n for n in EXCLUDE if n not in cat.values()]
    if missing:
        raise SystemExit(f"unknown COCO class names: {missing}")
    bad_ids = {i for i, n in cat.items() if n in EXCLUDE}
    dirty = {a["image_id"] for a in coco["annotations"] if a["category_id"] in bad_ids}
    names = {im["id"]: im["file_name"] for im in coco["images"]}
    lic = {im["id"]: im["license"] for im in coco["images"]}
    clean = sorted(i for i in names if i not in dirty)
    print(f"val2017: {len(names)} images, {len(dirty)} contain an excluded class, {len(clean)} are clean")

    need = args.n_train + args.n_test
    if len(clean) < need:
        raise SystemExit(f"only {len(clean)} clean images, need {need}: lower --n-train/--n-test")
    random.Random(args.seed).shuffle(clean)
    train_ids, test_ids = clean[: args.n_train], clean[args.n_train: need]

    with zipfile.ZipFile(img_zip) as zf:
        write_split(zf, names, train_ids, EXTRA / "backgrounds")
        write_split(zf, names, test_ids, EXTRA / "negatives_test")

    assert not set(train_ids) & set(test_ids)
    # Same pixels under two COCO ids would leak between the splits: check by hash.
    def hashes(folder: Path) -> set[str]:
        return {hashlib.md5(p.read_bytes()).hexdigest() for p in (folder / "images").iterdir()}
    overlap = hashes(EXTRA / "backgrounds") & hashes(EXTRA / "negatives_test")
    print(f"backgrounds: {len(train_ids)} -> {EXTRA / 'backgrounds'}")
    print(f"negatives_test: {len(test_ids)} -> {EXTRA / 'negatives_test'}")
    print(f"byte-identical images in both: {len(overlap)}")
    (EXTRA / "coco_backgrounds.json").write_text(json.dumps({
        "source": "COCO val2017", "licence": "annotations CC BY 4.0, images per-image Flickr licences",
        "excluded_classes": EXCLUDE, "seed": args.seed, "clean_images": len(clean),
        "backgrounds": len(train_ids), "negatives_test": len(test_ids),
        # COCO per-image licence ids: 1 BY-NC-SA, 2 BY-NC, 3 BY-NC-ND, 4 BY, 5 BY-SA, 6 BY-ND, 7 no known
        # copyright, 8 US Gov. build_v2.py keeps only 4, 5, 7, 8 for the commercial-safe list.
        "image_licence": {f"coco__{i:012d}": lic[i] for i in train_ids + test_ids}}, indent=2))


if __name__ == "__main__":
    if sys.platform == "win32":
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    main()
