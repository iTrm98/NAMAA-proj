# Model card: NAMAA litter detector (`litter-s640-balanced`)

## What it does

Finds litter in a single photo and draws one box per item. It has **one class**, reported by the vision service as `litter` (the trained class name is `item`; `POST /waste` maps it). It is used by the vision service (`vision-service/models/litter.pt`) on frames from the park cameras. People are not detected by this model: a separate COCO model finds `person` boxes so Laravel can blur them before a photo is saved.

## Model

| Item | Value |
|---|---|
| Base model | YOLO11s (COCO pretrained, Ultralytics 8.4.208), single-class head |
| Parameters | 9,413,187 (fused model; 9,428,179 before fusing) |
| Input size | 640 px |
| Weights | `ml/runs/litter-s640-balanced/weights/best.pt` (never committed; copied to `vision-service/models/litter.pt`) |

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

- **Default: 0.1775** (best F1 on the balanced val split; val P 0.647 / R 0.538). Set in `vision-service/app/waste.py` for a custom model.
- **Option: 0.039** (best precision with recall >= 0.70 on val; val P 0.320 / R 0.700; set `WASTE_CONFIDENCE=0.039`). It finds more litter but about one in three boxes is right on val (0.29 on public-test). On public-test recall at this threshold is 0.624, not 0.70.
- The COCO fallback (no `litter.pt`) keeps 0.35.

## Speed

77 ms per image on CPU (median of 50 images, preprocess + inference + postprocess, imgsz 640). The old COCO yolo26n model took 30 ms.

## Our own demo tray

**Not measured yet.** Photos from the C920 at the demo position are still to be taken, labelled and scored with `evaluate.py --test demo-tray` (see `ml/PHOTO_GUIDE.md`).

## Known limits

- **Tiny items:** BePLi beach fragments are mostly under 32 px; recall there is 0.37 at the default threshold.
- **Partly hidden litter** (under leaves, in shadow, behind objects) is missed more often; the datasets contain few such cases.
- **False alarms are not measured.** Public-test has only 1 clean image (3 in the whole dataset, all beach photos), so false alarms per clean image cannot be stated. In a quick live check, 1 of 3 clean beach photos produced a false detection.
- **Domain gap:** training photos come mostly from Europe and the US plus beach photos (PlastOPol, TACO, BePLi), not from Makkah parks, its light, grass types or litter. Numbers above are on those datasets, not on our parks.
- One class only: it does not say what kind of litter an object is.

## One sentence for the pitch slide

On 753 held-out photos from three public litter datasets, our model finds 41% of litter objects with 63% of its boxes correct (mAP50 0.47), where the detector we used before found 0.6% of them; we have not yet measured it on our own park photos.
