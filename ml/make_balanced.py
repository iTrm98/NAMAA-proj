"""Write the train and val lists for litter_balanced.yaml (no copies, list files only).

Per split (train and val): every PlastOPol and TACO image + a random subset of BePLi
images (seed 42) whose boxes add up to just under the PlastOPol + TACO box total of that
split, so BePLi (beach scenes, ~19 boxes per image) no longer dominates the box count.
The balanced val list decides best.pt, early stopping and the `evaluate.py --conf val`
thresholds. Public-test stays the full split (images/test), the same as litter_all.yaml;
the original images/val folder is never changed.

Reads ml/data/yolo/labels/{train,val}/*.txt, writes ml/data/yolo/lists/balanced_train.txt
and ml/data/yolo/lists/balanced_val.txt and prints images/boxes per dataset. Run from anywhere:
    python make_balanced.py                 # both splits, --seed 42, caps = PlastOPol + TACO boxes
    python make_balanced.py --split val     # only the val list
"""

from __future__ import annotations

import argparse
import random
from collections import defaultdict
from pathlib import Path

ML = Path(__file__).resolve().parent
YOLO = ML / "data" / "yolo"
KEEP_ALL = ("plastopol", "taco")
SUBSAMPLE = "bepli"


def boxes(label: Path) -> int:
    if not label.is_file():
        return 0
    return sum(1 for line in label.read_text().splitlines() if len(line.split()) >= 5)


def pick_subset(pool: list[tuple[Path, int]], cap: int, seed: int) -> list[tuple[Path, int]]:
    """Shuffle (seed) and take images until the next one would reach the box cap."""
    pool = list(pool)
    random.Random(seed).shuffle(pool)
    picked, total = [], 0
    for img, n in pool:
        if total + n >= cap:
            break
        picked.append((img, n))
        total += n
    return picked


def balance(split: str, seed: int, cap: int, out: Path) -> None:
    images = sorted((YOLO / "images" / split).iterdir())
    per_ds: dict[str, list[tuple[Path, int]]] = defaultdict(list)
    for img in images:
        ds = img.stem.split("__")[0]
        per_ds[ds].append((img, boxes(YOLO / "labels" / split / f"{img.stem}.txt")))

    keep = [x for ds in KEEP_ALL for x in per_ds[ds]]
    cap = cap or sum(n for _, n in keep)
    picked = pick_subset(per_ds[SUBSAMPLE], cap, seed)

    chosen = keep + picked
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text("\n".join(p.as_posix() for p, _ in sorted(chosen)) + "\n")

    print(f"[{split}] seed {seed}, BePLi box cap {cap} -> {out}")
    print(f"{'dataset':<10} {'images':>7} {'boxes':>7} {'of images':>10} {'of boxes':>9}")
    for ds, rows in (("plastopol", per_ds["plastopol"]), ("taco", per_ds["taco"]), ("bepli", picked)):
        print(f"{ds:<10} {len(rows):>7} {sum(n for _, n in rows):>7} {len(per_ds[ds]):>10} "
              f"{sum(n for _, n in per_ds[ds]):>9}")
    print(f"{'total':<10} {len(chosen):>7} {sum(n for _, n in chosen):>7} {len(images):>10} "
          f"{sum(n for x in per_ds.values() for _, n in x):>9}")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--split", choices=["train", "val", "both"], default="both")
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--cap", type=int, default=0, help="BePLi train box cap (0 = PlastOPol + TACO train boxes)")
    ap.add_argument("--val-cap", type=int, default=0, help="BePLi val box cap (0 = PlastOPol + TACO val boxes)")
    ap.add_argument("--out", default=str(YOLO / "lists" / "balanced_train.txt"))
    ap.add_argument("--val-out", default=str(YOLO / "lists" / "balanced_val.txt"))
    args = ap.parse_args()

    if args.split in ("train", "both"):
        balance("train", args.seed, args.cap, Path(args.out))
    if args.split == "both":
        print()
    if args.split in ("val", "both"):
        balance("val", args.seed, args.val_cap, Path(args.val_out))


if __name__ == "__main__":
    main()
