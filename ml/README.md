# ml/: litter detector training (local only)

Training and evaluation of the one-class `litter` detector used by
`vision-service` (`POST /waste`). Runs on the host GPU, outside Docker.
Only the scripts, configs and guides in this folder are tracked
(including `labelstudio/`); data, runs, photos,
venv and weights are git-ignored (`ml/.venv/`, `ml/data/`, `ml/runs/`,
`ml/photos/`, `ml/weights/`, `*.pt`).

Dataset sources, licences and attribution: [DATA_LICENSES.md](DATA_LICENSES.md).

## Environment

`ml/.venv` (Python, PyTorch 2.14.1+cu130, Ultralytics 8.4.208, the same
Ultralytics version as `vision-service/requirements.txt`).

```powershell
cd C:\dev\NAMAA-proj\ml
.\.venv\Scripts\Activate.ps1          # or call .\.venv\Scripts\python.exe directly
nvidia-smi                            # GPU visible
python -c "import torch, ultralytics; print(torch.cuda.is_available(), torch.cuda.get_device_name(0), ultralytics.__version__)"
```

Before a long run, check free RAM; if Docker uses most of it:

```powershell
cd C:\dev\NAMAA-proj; docker compose stop     # later: docker compose start
```

YOLO-World (Phase C baseline) needs the `clip` package; Ultralytics installed
it automatically into `ml/.venv` on first use (`set_classes`).

## 1. Download raw data (into `ml/data/raw/`)

| Dataset | Source | File |
|---|---|---|
| PlastOPol (full) | https://zenodo.org/records/5829156 | `PlastOpol_oneclass.zip` (7.7 GB) |
| TACO (official) | https://github.com/pedropro/TACO + https://zenodo.org/records/3587843 | `data/annotations.json` from the repo, `TACO.zip` (2.7 GB) from Zenodo |
| BePLi v1 | https://doi.org/10.17882/92297 | `98753.zip` (9.5 GB) |

```bash
# Git Bash
cd /c/dev/NAMAA-proj/ml/data/raw
mkdir -p plastopol taco bepli
curl -L -C - -o plastopol/PlastOpol_oneclass.zip https://zenodo.org/api/records/5829156/files/PlastOpol_oneclass.zip/content
curl -L -o taco/annotations.json https://raw.githubusercontent.com/pedropro/TACO/master/data/annotations.json
curl -L -C - -o taco/TACO.zip https://zenodo.org/api/records/3587843/files/TACO.zip/content
curl -L -C - -o bepli/bepli_v1.zip https://www.seanoe.org/data/00811/92297/data/98753.zip
```

Zenodo and SEANOE connections stall often: re-run the same `curl -C -` line to
resume, and check the final size (PlastOPol 7709841780, TACO 2719810615, BePLi
9469512369 bytes). Then unzip (Python `zipfile` works on Windows):

| Archive | Unzip to | `prepare_data.py` reads |
|---|---|---|
| `PlastOpol_oneclass.zip` | `plastopol/x`, then its inner `images.zip` to `plastopol/x` and `plastopol-annotations_folds.zip` to `plastopol/x/ann` | `x/ann/annotations/full_plastopol.json`, `x/images/` |
| `TACO.zip` | `taco/x` | `taco/annotations.json` (repo, official), images in `x/TACO/data/batch_*` |
| `bepli_v1.zip` | `bepli/x` (only `plastic_coco/annotation/` and `plastic_coco/images/original_images/` are needed) | `x/plastic_coco/annotation/all_plastic_coco.json` |

Never use TACO's `annotations_unofficial.json`.

## 2. Convert to YOLO (`ml/data/yolo/`)

```powershell
.\.venv\Scripts\python.exe prepare_data.py            # all datasets, seed 42, 80/10/10 per dataset
```

Writes `ml/data/yolo/{images,labels}/{train,val,test}/<dataset>__<name>`,
`ml/data/yolo/summary.json` and prints the summary. `test` is the
**public-test** split: never used for training or threshold tuning.

Configs: `litter_all.yaml` (PlastOPol + TACO + BePLi) and
`litter_commercial_safe.yaml` (without BePLi, which is non-commercial).
Image lists (absolute paths) in `ml/data/yolo/lists/`: `test_<dataset>.txt`
(score one dataset with `--test data\yolo\lists\test_taco.txt`) and
`commercial_safe_{train,val,test}.txt`.

`litter_balanced.yaml`: BePLi has ~19 boxes per image and would be 88% of all train
boxes, so this config keeps every PlastOPol and TACO train image and a random BePLi
subset (seed 42) with just under as many boxes as PlastOPol + TACO together. The
**val** split is balanced the same way (the full val is 88% BePLi boxes, and val decides
`best.pt`, early stopping and the `evaluate.py --conf val` thresholds). Public-test is
the full split (same as `litter_all.yaml`, so scores stay comparable); `images/val` itself
is never changed. Both lists (`lists/balanced_train.txt`, `lists/balanced_val.txt`) are
written by `make_balanced.py` (reads label files only):

```powershell
.\.venv\Scripts\python.exe make_balanced.py    # both lists; prints images/boxes per dataset
#   --split train|val|both, --seed 42, --cap / --val-cap (0 = PlastOPol + TACO boxes of that split)
```

| Train | PlastOPol | TACO | BePLi | Total |
|---|---|---|---|---|
| images | 1934 | 1196 | 364 of 2897 | 3494 |
| boxes | 4001 | 3276 | 7187 of 54621 | 14464 |

| Val | PlastOPol | TACO | BePLi | Total |
|---|---|---|---|---|
| images | 242 | 149 | 58 of 362 | 449 |
| boxes | 628 | 436 | 882 of 7548 | 1946 |

(BePLi val stops at 882 of the 1064 cap: the next shuffled image would have crossed it,
the same rule as train.) Ultralytics caches the labels of any list over `images/train`
(or `images/val`) in the same `labels/train.cache` (`labels/val.cache`); switching
configs only triggers a short re-scan.

## 3. Train (`train.py`, YOLO11s, one class)

Free RAM first: the Docker stack can be stopped while training
(`docker compose stop` in the project root, `docker compose start` afterwards).
Run from `ml\weights` so Ultralytics' helper downloads (AMP check model) land in a
git-ignored folder:

```powershell
cd weights
# run 1: 640 px, PlastOPol + TACO + BePLi, AutoBatch, max 3.5 h
..\.venv\Scripts\python.exe ..\train.py --name litter-s640-all --hours 3.5
# run 2: 960 px (fixed batch, AutoBatch is too optimistic at this size on 4 GB)
..\.venv\Scripts\python.exe ..\train.py --imgsz 960 --batch 4 --name litter-s960-all --hours 3
# run 3: commercial-safe data (no BePLi)
..\.venv\Scripts\python.exe ..\train.py --data litter_commercial_safe.yaml --name litter-s640-safe --hours 1.5
# run 4: BePLi down-sampled (new run, own folder; litter-s640-all stays resumable)
..\.venv\Scripts\python.exe ..\train.py --data litter_balanced.yaml --name litter-s640-balanced --hours 3.5 --epochs 100 --patience 20
# continue an interrupted run
..\.venv\Scripts\python.exe ..\train.py --name litter-s640-all --resume
```

Output: `ml/runs/<name>/` (`weights/best.pt` = best on the **val** split,
`results.csv`, plots). `--hours` is Ultralytics `time=`: after each epoch it
re-plans the epoch count so the run ends inside the budget (`--hours 0` trains
exactly `--epochs`). Ultralytics' time mode alone ignores `epochs`, so `train.py` keeps
the re-planned count at or below `--epochs` (LR schedule and close_mosaic follow the
capped count): the run ends at `--epochs`, `--hours` or `--patience`, whichever comes
first. This matters for run 4: 3494 instead of 6027 train images, so an epoch takes
roughly 60% of run 1's ~190 s (not 7x less: time scales with images, not boxes), and
3.5 h alone would plan ~110 epochs; the cap stops at 100 (~3.2 h), early stopping
(patience 20) usually sooner. On `--resume` the cap is applied only if `--epochs` is
given. `--data` may be a bare file name (resolved in `ml/`); a new `--name` always
gets its own folder (an existing name gets a numeric suffix, never overwritten).
Other options: `--imgsz`, `--data`, `--epochs` (default 100),
`--batch` (-1 = AutoBatch, 0.7 = AutoBatch at 70% of GPU memory, 8 = fixed),
`--patience` (20), `--workers` (2), `--cache false|ram|disk`. Augmentation:
Ultralytics defaults plus `hsv_v=0.5` (stronger brightness change for hard sunlight).

## 4. Evaluate

```powershell
# any model on the public-test split (CPU timing included)
.\.venv\Scripts\python.exe evaluate.py --weights runs\<run>\weights\best.pt --test public-test
# production COCO model with the vision-service litter mapping
.\.venv\Scripts\python.exe evaluate.py --weights weights\yolo26n.pt --mode coco --test public-test
.\.venv\Scripts\python.exe evaluate.py --weights weights\yolo11n.pt --mode coco --test public-test
# YOLO-World with the litter prompt list, merged into one class
.\.venv\Scripts\python.exe evaluate.py --weights weights\yolov8s-worldv2.pt --mode world --test public-test
# own photos: --test demo-tray  (ml/photos/demo-tray/{images,labels}, see section 5)
```

```powershell
# run 4 (from ml\weights, like training): two thresholds tuned on the balanced val
# (picked automatically from the run's args.yaml), scored on public-test
..\.venv\Scripts\python.exe ..\evaluate.py --weights ..\runs\litter-s640-balanced\weights\best.pt --test public-test --conf val --out ..\runs\eval\litter-s640-balanced.json
```

Every threshold gets P, R, F1, TP/FP/FN and false alarms per clean image, per dataset
(PlastOPol, TACO, BePLi, from the `<dataset>__` file prefix) and overall (ALL), next to
mAP50 and mAP50-95 (threshold-free); JSON first, then a table.
Options: `--conf` (default 0.25 = one fixed threshold; `--conf val` = two thresholds
from the **val** split, never from the test set: `best_f1`, and `recall_0.70` = best
precision with recall >= `--min-recall` (0.70); if that recall is unreachable, the
highest-recall point is used and flagged `reached: false` / `RECALL TARGET NOT REACHED`;
each threshold's value and source are in `thresholds` in the JSON),
`--val-set auto|balanced|all` (val set for `--conf val`; `auto` = `balanced`
(`lists/balanced_val.txt`) when `<run>/args.yaml` says the weights were trained on
`litter_balanced.yaml`, else `all` (`images/val`); name, reason, images and boxes are
printed and stored as `val_set` in the JSON), `--imgsz 640`, `--cpu-images 50` (images timed on CPU), `--out <json>`.

## 5. Own photos: demo tray (C920)

Guides: [PHOTO_GUIDE.md](PHOTO_GUIDE.md) (what to shoot, where it goes) and
[LABELING_GUIDE.md](LABELING_GUIDE.md) (what to box, Label Studio setup, export).
Folders (git-ignored): raw photos in `ml/photos/{demo-tray,local-train}/{litter,clean}/`,
YOLO layout written by `export_labels.py` in `ml/photos/<set>/{images,labels}/`
(clean photo = empty `.txt`). `demo-tray` is the test set only; `local-train` is
for fine-tuning only; the same photo is never in both.

```powershell
cd C:\dev\NAMAA-proj\ml
# 1. draft boxes for Label Studio (CPU; low conf 0.15 on purpose)
.\.venv\Scripts\python.exe prelabel.py photos\demo-tray\litter photos\demo-tray\clean
#    -> photos\demo-tray-prelabel.json   (--weights, --conf, --device 0, --no-predict, --out)

# 2. Label Studio (own compose file, http://localhost:8090, data in ml\data\labelstudio)
cd labelstudio; docker compose up -d      # label, export JSON, then: docker compose down
cd ..

# 3. Label Studio export -> YOLO layout
.\.venv\Scripts\python.exe export_labels.py --json photos\export-demo-tray.json --set demo-tray
.\.venv\Scripts\python.exe export_labels.py --json photos\export-local-train.json --set local-train
#    (--yolo <export.zip> instead of --json; --overwrite to replace an earlier export)

# 4. score on the demo tray
.\.venv\Scripts\python.exe evaluate.py --weights runs\litter-s640-all\weights\best.pt --test demo-tray --conf val
```

## 6. Fine-tune on own photos (`finetune_local.py`)

Public train split + `ml/photos/local-train/` (each photo copied `--repeat 10` times
into `ml/data/local-ft/`), val split unchanged, start from a trained model, short
run. Writes `ml/data/local-ft/litter_local.yaml` and `ml/runs/litter-local-ft/`.

```powershell
cd C:\dev\NAMAA-proj\ml\weights
..\.venv\Scripts\python.exe ..\finetune_local.py --dry-run     # build the dataset only, check counts
..\.venv\Scripts\python.exe ..\finetune_local.py               # 20 epochs, SGD lr0 0.002, 640, batch 4
..\.venv\Scripts\python.exe ..\evaluate.py --weights ..\runs\litter-local-ft\weights\best.pt --test demo-tray --conf val
```

Options: `--weights` (default `runs\litter-s640-all\weights\best.pt`), `--epochs 20`,
`--lr0 0.002`, `--optimizer SGD` (with `auto` Ultralytics ignores lr0), `--imgsz 640`,
`--batch 4`, `--repeat 10`, `--patience 10`, `--workers 2`, `--name litter-local-ft`.
At ~190 s per epoch (run 1), 20 epochs take about an hour. Compare with the start
model on `demo-tray` **and** `public-test`: the fine-tune must not lose much on public-test.
