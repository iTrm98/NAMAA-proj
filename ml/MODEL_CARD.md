# Model card: NAMAA litter detector (`litter-v2-s960`)

> Deployed model since the v2 swap: **`litter-v2-s960`, run at 960 px, threshold 0.185**. The sections below describe the first model (`litter-s640-balanced`, kept as `vision-service/models/litter_old_s640.pt`) unless they say v2; the v2 comparison is in the next section.

## What it does

Finds litter in a single photo and draws one box per item. It has **one class**, reported by the vision service as `litter` (the trained class name is `item`; `POST /waste` maps it). It is used by the vision service (`vision-service/models/litter.pt`) on frames from the park cameras. People are not detected by this model: a separate COCO model finds `person` boxes so Laravel can blur them before a photo is saved.

## Model

| Item | Value |
|---|---|
| Base model | YOLO11s (COCO pretrained, Ultralytics 8.4.208), single-class head |
| Parameters | 9,413,187 (fused model; 9,428,179 before fusing) |
| Input size | 640 px |
| Weights | `ml/runs/litter-s640-balanced/weights/best.pt` (never committed; copied to `vision-service/models/litter.pt`) |

## v2 and the model swap (old vs v2 vs v2-s960)

v2 = the same balanced PlastOPol + TACO + BePLi set plus extra ground-level litter sets (`ml/extra_sources.json`) and COCO background images with empty labels (about 20% of train/val), see `litter_v2.yaml`. Two trainings: `litter-v2` (640 px) and `litter-v2-s960` (960 px). All three models were scored on the same data: public-test (753 images, 7,695 boxes, IoU 0.5, never trained or tuned on), and the 500 `ml/data/extra/negatives_test` photos (no litter) for false positives. Each model's best-F1 threshold was picked on its own val set (OLD: balanced val, 449 images; v2 models: v2 val, 561 images), never on test. CPU time is the median of 50 images (preprocess + inference + postprocess).

| | OLD (`litter-s640-balanced`, 640) | v2 (640) | **v2-s960 (960, deployed)** |
|---|---|---|---|
| mAP50 overall | 0.471 | 0.470 | **0.494** |
| mAP50 PlastOPol / TACO / BePLi | **0.795** / 0.628 / 0.429 | 0.783 / 0.622 / 0.427 | 0.790 / **0.638** / **0.455** |
| Best-F1 threshold | 0.1775 | 0.1806 | **0.1854 (set to 0.185)** |
| P / R at best-F1 | 0.627 / 0.412 | **0.647** / 0.395 | 0.615 / **0.448** |
| P / R at 0.1775 | 0.627 / 0.412 | 0.641 / 0.398 | 0.603 / 0.455 |
| False positives per negative photo, at best-F1 | 3.244 | 0.436 | **0.364** |
| False positives per negative photo, at 0.1775 | 3.244 | 0.450 | **0.388** |
| Negative photos with at least one false positive (of 500), at 0.1775 | 420 | 141 | **121** |
| CPU time per image | 74.7 ms | 75.6 ms | 175.4 ms |

Reading: v2 cuts false alarms about 7x with no loss on the litter tests; the 960 px model adds +0.023 mAP50 and about 4 points of recall for 2.3x the CPU time (still under 0.2 s per frame; the camera is polled, not streamed). The negatives are COCO-style photos, not park photos, so the false-positive numbers are relative. **Chosen: v2-s960 at threshold 0.185** (its own best F1; 0.1775 would cost almost nothing). Fallback if CPU time matters: v2 at 640 and about 0.18. Raw numbers: `ml/runs/eval/cmp_{OLD,v2,v2-s960}.json`. Not yet measured on our own demo tray.

## Training data

| Dataset | Used | Licence |
|---|---|---|
| PlastOPol (Zenodo 5829156) | all (train 1,934 images / 4,001 boxes) | CC BY 4.0 |
| TACO, official annotations (Zenodo 3587843) | all (train 1,196 / 3,276) | CC BY 4.0 for the release; per-image notes mixed (ODbL, unspecified "CC", not stated) |
| BePLi v1 (SEANOE 10.17882/92297) | subset (train 364 of 2,897 images / 7,187 of 54,621 boxes) | CC BY-NC-SA 4.0, **non-commercial** |

- **Balanced train:** 3,494 images / 14,464 boxes. BePLi is capped to about the PlastOPol + TACO box count so beach fragments do not dominate (they were 88% of boxes in the full set).
- **Balanced val:** 449 images / 1,946 boxes (same rule). It picks `best.pt`, early stopping and both thresholds.
- **Public-test:** 753 images / 7,695 boxes (PlastOPol 242, TACO 149, BePLi 362). Never used for training, model choice or thresholds.
- Split per dataset 80/10/10, seed 42. All source classes merged into `litter`.

Licences, citations and required attribution: [DATA_LICENSES.md](DATA_LICENSES.md). Short version: cite Córdova et al. 2022 (PlastOPol), Proença and Simões 2020 (TACO, with OpenLitterMap & Contributors for ODbL images) and Hidaka et al. 2023 (BePLi). **BePLi is non-commercial and share-alike:** fine for Tanmiathon 2026; for any commercial use retrain with `litter_commercial_safe.yaml` (PlastOPol + TACO only) and get legal review of the TACO per-image licences. Ultralytics code and weights are AGPL-3.0.

## Training settings

imgsz 640, batch 4 (AutoBatch; gradients accumulate to an effective 64), AMP, workers 2, Ultralytics default augmentation plus `hsv_s 0.7`, `hsv_v 0.5`. Epoch plan capped at 100; **99 epochs ran, best at epoch 79**; early stopping (patience 20) ended the run at epoch 99, 20 epochs after the best one. 3.15 h on one RTX 3050 4 GB Laptop GPU (11,330 s).

## Results on public-test (753 images, 7,695 boxes, IoU 0.5)

| Model | Threshold | P | R | mAP50 | mAP50-95 | mAP50 PlastOPol / TACO / BePLi |
|---|---|---|---|---|---|---|
| Production before (yolo26n COCO + litter mapping) | 0.35 | 0.922 | 0.006 | 0.052 | 0.040 | 0.279 / 0.181 / 0.031 |
| YOLO-World v2 s (prompts, zero-shot) | 0.001 (floor) | 0.381 | 0.188 | 0.124 | 0.088 | 0.532 / 0.388 / 0.090 |
| Practice model `plastopol-v1` (YOLO11s, 189 PlastOPol images)* | 0.198 | 0.225 | 0.122 | 0.054 | 0.026 | 0.293 / 0.242 / 0.024 |
| **This model** | 0.1775 (best F1) | **0.627** | **0.412** | **0.471** | **0.264** | **0.795 / 0.628 / 0.429** |
| **This model** | 0.039 (recall option) | 0.293 | 0.624 | (same, threshold-free) | | |

\* 27 of its training images are inside public-test, so its numbers may be slightly optimistic.

This model, per dataset (P / R):

| Threshold | PlastOPol | TACO | BePLi |
|---|---|---|---|
| 0.1775 | 0.691 / 0.770 | 0.635 / 0.594 | 0.616 / 0.371 |
| 0.039 | 0.357 / 0.858 | 0.340 / 0.699 | 0.284 / 0.600 |

## Threshold

- **Deployed (v2-s960): 0.185**, `DEFAULT_CONFIDENCE_CUSTOM` in `vision-service/app/waste.py`; override with `WASTE_CONFIDENCE`. The bullets below are for the first model.
- **Default: 0.1775** (best F1 on the balanced val split; val P 0.647 / R 0.538). Set in `vision-service/app/waste.py` for a custom model.
- **Option: 0.039** (best precision with recall >= 0.70 on val; val P 0.320 / R 0.700; set `WASTE_CONFIDENCE=0.039`). It finds more litter but about one in three boxes is right on val (0.29 on public-test). On public-test recall at this threshold is 0.624, not 0.70.
- The COCO fallback (no `litter.pt`) keeps 0.35.

## Speed

v2-s960 at 960 px: 175 ms per image on CPU (see the comparison above). The first model: 77 ms per image on CPU (median of 50 images, preprocess + inference + postprocess, imgsz 640). The old COCO yolo26n model took 30 ms.

## Our own demo tray

**Not measured yet.** Photos from the C920 at the demo position are still to be taken, labelled and scored with `evaluate.py --test demo-tray` (see `ml/PHOTO_GUIDE.md`).

## Known limits

- **Tiny items:** BePLi beach fragments are mostly under 32 px; recall there is 0.37 at the default threshold.
- **Partly hidden litter** (under leaves, in shadow, behind objects) is missed more often; the datasets contain few such cases.
- **False alarms:** measured for v2-s960 on 500 non-litter COCO photos: 0.39 boxes per photo at 0.1775 and 0.36 at 0.185; 121 of 500 photos have at least one. Public-test has only 1 clean image, so the first model's false alarms were unknown until this test (3.24 per photo). Park photos are still to be measured.
- **Domain gap:** training photos come mostly from Europe and the US plus beach photos (PlastOPol, TACO, BePLi), not from Makkah parks, its light, grass types or litter. Numbers above are on those datasets, not on our parks.
- One class only: it does not say what kind of litter an object is.

## One sentence for the pitch slide

On 753 held-out photos from three public litter datasets, our model finds 41% of litter objects with 63% of its boxes correct (mAP50 0.47), where the detector we used before found 0.6% of them; we have not yet measured it on our own park photos.
