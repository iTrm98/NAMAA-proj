"""Fine-tune YOLO11s as a one-class `litter` detector.

Defaults are the Sprint 5 run 1 settings (RTX 3050 4 GB laptop GPU):
    yolo11s.pt pretrained (COCO), litter_all.yaml, imgsz 640, AutoBatch, AMP,
    early stopping (patience 20), a wall-clock cap (--hours) so a run ends in time,
    default Ultralytics augmentations plus stronger brightness change (hsv_v 0.5) for hard sunlight.

The best checkpoint (weights/best.pt) is chosen by Ultralytics on the VAL split
(fitness = 0.1 * mAP50 + 0.9 * mAP50-95). The public-test split is never used here:
score the result with evaluate.py afterwards.

Examples (from ml/, with the venv active):
    python train.py                                         # run 1: 640, litter_all
    python train.py --imgsz 960 --name litter-s960-all --hours 3.5
    python train.py --data litter_commercial_safe.yaml --name litter-s640-safe --hours 1.5
    python train.py --data litter_balanced.yaml --name litter-s640-balanced   # BePLi down-sampled

--hours: Ultralytics `time=`; after every epoch it re-plans the number of epochs so the
run (and its LR schedule and close_mosaic phase) ends inside that budget. Set --hours 0
to train exactly --epochs.

--epochs: Ultralytics' time mode ignores `epochs` (the plan can exceed it on a small
dataset), so train.py adds a callback that keeps the re-planned count at or below --epochs
(LR schedule and close_mosaic follow the capped count). The run ends at whichever comes
first: --epochs, --hours, or --patience epochs without val improvement.
On --resume the cap is applied only when --epochs is given (the checkpoint stores the
re-planned count, not the original ceiling).

A new --name always starts a new folder (exist_ok=False: an existing name gets a
numeric suffix), so a new run never touches another run's folder.
"""

from __future__ import annotations

import argparse
from pathlib import Path

ML = Path(__file__).resolve().parent


def batch_arg(v: str):
    """-1 = AutoBatch (60% of GPU memory), 0<x<1 = AutoBatch at that memory fraction, else a fixed size."""
    f = float(v)
    return int(f) if f >= 1 or f == -1 else f


def cap_epochs(limit: int):
    """on_fit_epoch_end callback: keep Ultralytics' time-based epoch plan at or below `limit`."""
    def cb(trainer) -> None:
        if trainer.args.time and trainer.epochs > limit:
            trainer.epochs = trainer.args.epochs = limit
            trainer._setup_scheduler()  # same steps Ultralytics runs after re-planning
            trainer.scheduler.last_epoch = trainer.epoch
            if trainer.epoch + 1 >= limit:
                trainer.stop = True
    return cb


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--model", default=str(ML / "weights" / "yolo11s.pt"),
                    help="start weights (downloaded by Ultralytics if missing)")
    ap.add_argument("--data", default=str(ML / "litter_all.yaml"))
    ap.add_argument("--imgsz", type=int, default=640)
    ap.add_argument("--epochs", type=int, default=None,
                    help="ceiling, also in --hours mode (default 100; on --resume only if given)")
    ap.add_argument("--hours", type=float, default=3.5, help="wall-clock cap (Ultralytics time=), 0 = off")
    ap.add_argument("--batch", type=batch_arg, default=-1)
    ap.add_argument("--patience", type=int, default=20)
    ap.add_argument("--workers", type=int, default=2)
    ap.add_argument("--cache", default="false", choices=["false", "ram", "disk"])
    ap.add_argument("--device", default="0")
    ap.add_argument("--name", default="litter-s640-all")
    ap.add_argument("--project", default=str(ML / "runs"))
    ap.add_argument("--resume", action="store_true", help="resume <project>/<name>/weights/last.pt")
    args = ap.parse_args()

    from ultralytics import YOLO

    if args.resume:
        last = Path(args.project) / args.name / "weights" / "last.pt"
        model = YOLO(str(last))
        if args.epochs:
            model.add_callback("on_fit_epoch_end", cap_epochs(args.epochs))
        model.train(resume=True)
        return

    args.epochs = args.epochs or 100

    data = Path(args.data)
    if not data.is_absolute() and not data.exists():
        data = ML / data
    model_path = Path(args.model)
    model_path.parent.mkdir(parents=True, exist_ok=True)
    model = YOLO(str(model_path) if model_path.exists() else model_path.name)
    model.add_callback("on_fit_epoch_end", cap_epochs(args.epochs))

    model.train(
        data=str(data),
        imgsz=args.imgsz,
        epochs=args.epochs,
        time=args.hours or None,
        batch=args.batch,
        patience=args.patience,
        workers=args.workers,
        cache=False if args.cache == "false" else args.cache,
        device=args.device,
        amp=True,
        project=str(Path(args.project).resolve()),
        name=args.name,
        exist_ok=False,
        seed=0,
        pretrained=True,
        single_cls=True,
        # Augmentation: Ultralytics defaults (mosaic 1.0, fliplr 0.5, scale 0.5, translate 0.1,
        # hsv_h 0.015, close_mosaic 10) plus moderate brightness/saturation change for sunlight.
        hsv_s=0.7,
        hsv_v=0.5,
        plots=True,
        verbose=True,
    )


if __name__ == "__main__":  # required on Windows: dataloader workers re-import this module
    main()
