"""Short fine-tune of a trained litter model on the public train split plus our own photos.

Builds ml/data/local-ft/ (git-ignored):
    images/train, labels/train   copies of ml/photos/local-train/{images,labels},
                                 each photo repeated --repeat times (they are ~40 among
                                 ~6000 public images; without repeats they barely count)
    litter_local.yaml            train = [public train split, local-ft/images/train],
                                 val = public val split (unchanged), one class `litter`
then trains from --weights (default: best of run 1) for a short run with a lower lr0.
ml/photos/demo-tray/ is never used here; the script stops if any local-train photo is
also in demo-tray (same bytes).

Example (from ml\\weights, like train.py, so helper downloads land in an ignored folder):
    ..\\.venv\\Scripts\\python.exe ..\\finetune_local.py
    ..\\.venv\\Scripts\\python.exe ..\\finetune_local.py --weights ..\\runs\\litter-s960-all\\weights\\best.pt --imgsz 960
Then score it on the demo tray:
    ..\\.venv\\Scripts\\python.exe ..\\evaluate.py --weights ..\\runs\\litter-local-ft\\weights\\best.pt --test demo-tray --conf val
"""

from __future__ import annotations

import argparse
import hashlib
import shutil
from pathlib import Path

ML = Path(__file__).resolve().parent
PUBLIC = ML / "data" / "yolo"
LOCAL = ML / "photos" / "local-train"
DEMO = ML / "photos" / "demo-tray"
OUT = ML / "data" / "local-ft"
IMG_EXT = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}


def md5(p: Path) -> str:
    return hashlib.md5(p.read_bytes()).hexdigest()


def build_dataset(repeat: int) -> Path:
    images = sorted(p for p in (LOCAL / "images").glob("*") if p.suffix.lower() in IMG_EXT)
    if not images:
        raise SystemExit(f"no photos in {LOCAL / 'images'}: run export_labels.py --set local-train first")
    missing = [p.name for p in images if not (LOCAL / "labels" / f"{p.stem}.txt").is_file()]
    if missing:
        raise SystemExit(f"{len(missing)} photo(s) without a label file, e.g. {missing[0]} "
                         "(clean photos need an empty .txt)")
    if DEMO.is_dir():
        demo = {md5(p) for p in DEMO.rglob("*") if p.suffix.lower() in IMG_EXT}
        clash = [p.name for p in images if md5(p) in demo]
        if clash:
            raise SystemExit(f"{len(clash)} local-train photo(s) are also in demo-tray, e.g. {clash[0]}: stop")
    for split in ("train", "val"):
        if not (PUBLIC / "images" / split).is_dir():
            raise SystemExit(f"public {split} split missing: {PUBLIC / 'images' / split} (run prepare_data.py)")

    shutil.rmtree(OUT, ignore_errors=True)  # rebuilt every time (also drops the old label cache)
    img_dir, lbl_dir = OUT / "images" / "train", OUT / "labels" / "train"
    img_dir.mkdir(parents=True)
    lbl_dir.mkdir(parents=True)
    n_boxes = n_clean = 0
    for p in images:
        lbl = LOCAL / "labels" / f"{p.stem}.txt"
        rows = [r for r in lbl.read_text(encoding="utf-8").splitlines() if r.strip()]
        n_boxes += len(rows)
        n_clean += not rows
        for k in range(repeat):
            name = f"local__r{k}_{p.stem}"
            shutil.copy2(p, img_dir / f"{name}{p.suffix.lower()}")
            shutil.copy2(lbl, lbl_dir / f"{name}.txt")

    yaml = OUT / "litter_local.yaml"
    yaml.write_text(
        "# Built by finetune_local.py: public train split + own photos (repeated), public val split.\n"
        f"path: {PUBLIC.as_posix()}\n"
        "train:\n"
        "  - images/train\n"
        f"  - {img_dir.as_posix()}\n"
        "val: images/val\n"
        "names:\n"
        "  0: litter\n",
        encoding="utf-8",
    )
    print(f"local-train: {len(images)} photos ({n_clean} clean, {n_boxes} boxes) x {repeat} -> {yaml}")
    return yaml


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--weights", default=str(ML / "runs" / "litter-s640-all" / "weights" / "best.pt"),
                    help="start from this trained model")
    ap.add_argument("--epochs", type=int, default=20)
    ap.add_argument("--lr0", type=float, default=0.002, help="5x below the SGD default 0.01")
    ap.add_argument("--optimizer", default="SGD",
                    help="explicit on purpose: with 'auto' Ultralytics ignores lr0")
    ap.add_argument("--imgsz", type=int, default=640)
    ap.add_argument("--batch", type=int, default=4)
    ap.add_argument("--repeat", type=int, default=10, help="copies of each own photo in the train split")
    ap.add_argument("--patience", type=int, default=10)
    ap.add_argument("--workers", type=int, default=2)
    ap.add_argument("--device", default="0")
    ap.add_argument("--name", default="litter-local-ft")
    ap.add_argument("--project", default=str(ML / "runs"))
    ap.add_argument("--dry-run", action="store_true", help="build the dataset only, do not train")
    args = ap.parse_args()

    weights = Path(args.weights)
    if not weights.is_file():
        raise SystemExit(f"weights not found: {weights}")
    yaml = build_dataset(max(1, args.repeat))
    if args.dry_run:
        return

    from ultralytics import YOLO  # lazy: --help and --dry-run need no torch

    YOLO(str(weights)).train(
        data=str(yaml),
        imgsz=args.imgsz,
        epochs=args.epochs,
        batch=args.batch,
        optimizer=args.optimizer,
        lr0=args.lr0,
        warmup_epochs=1,  # the start weights are already trained
        patience=args.patience,
        workers=args.workers,
        device=args.device,
        amp=True,
        project=str(Path(args.project).resolve()),
        name=args.name,
        exist_ok=False,
        seed=0,
        single_cls=True,
        hsv_s=0.7,  # same augmentation as train.py
        hsv_v=0.5,
        plots=True,
        verbose=True,
    )


if __name__ == "__main__":  # required on Windows: dataloader workers re-import this module
    main()
