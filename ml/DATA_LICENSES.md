# Training data: sources, licences, attribution

All three datasets are used only to train and test NAMAA's one-class `litter`
detector. Images and annotations are never committed (`ml/data/` is
git-ignored); only the conversion scripts are. Licences below are as stated on
each source page, checked on 2026-11-10.

## PlastOPol

- Source: https://zenodo.org/records/5829156 (DOI 10.5281/zenodo.5829156), file `PlastOpol_oneclass.zip`.
  Full release: 2418 images, 5300 litter boxes. (Not the 270-image Roboflow copy used in the practice run.)
- Citation: Córdova, M., Pinto, A., Hellevik, C. C., Alaliyat, S. A.-A., Hameed, I. A., Pedrini, H., Torres, R. da S. (2022).
  *Litter Detection with Deep Learning: A Comparative Study.* Sensors 22(2), 548. https://www.mdpi.com/1424-8220/22/2/548
- Licence (Zenodo record): **CC BY 4.0**. The record says the images come from Marine Debris Tracker "under an open access Creative Commons Attribution license".
- Allows: use, sharing and adaptation for any purpose, including commercial, with attribution.
- Required attribution: cite the paper above, link the Zenodo record and the licence, say that the data was converted (boxes to YOLO, one class).

## TACO (Trash Annotations in Context), official annotations only

- Source: https://github.com/pedropro/TACO (`data/annotations.json`, official set: 1500 images, 4784 annotations, 60 categories)
  and the image bundle https://zenodo.org/records/3587843 (DOI 10.5281/zenodo.3587843, `TACO.zip`).
  `annotations_unofficial.json` is **not** used.
- Citation: Proença, P. F., Simões, P. (2020). *TACO: Trash Annotations in Context for Litter Detection.* arXiv:2003.06975.
- Licences:
  - Zenodo record (images + annotations bundle): **CC BY 4.0**.
  - GitHub repository (code/API): MIT.
  - Per-image `license` field in the official `annotations.json`: 466 images "ODBL (c) OpenLitterMap & Contributors",
    319 images "CC" (no version or variant given), 715 images empty. So for 715 images the image licence is
    not stated per image; we rely on the CC BY 4.0 of the Zenodo release. Images from OpenLitterMap are
    under ODbL (a database licence: attribution and share-alike for the database).
- Allows: use and adaptation with attribution (CC BY 4.0 of the release). Caveat for commercial use: the
  mixed per-image notes (ODbL, unspecified "CC") mean the per-image rights are not fully clear; a
  commercial product should get legal review or drop the images with unclear licence.
- Required attribution: cite the paper, link the repo and the Zenodo record, mention OpenLitterMap & Contributors (ODbL) for those images.

## BePLi Dataset v1 (Beach Plastic Litter)

- Source: SEANOE, https://doi.org/10.17882/92297 (https://www.seanoe.org/data/00811/92297/), file `98753.zip`.
  3708 images, COCO-format annotations.
- Citation: Hidaka Mitsuko, Murakami Koshiro, Koshidawa Kenta, Kawahara Shintaro, Sugiyama Daisuke, Kako Shin'ichiro,
  Matsuoka Daisuke (2023). *BePLi Dataset v1: Beach Plastic Litter Dataset version 1.* SEANOE. https://doi.org/10.17882/92297
- Licence (SEANOE page): **CC BY-NC-SA 4.0**.
- Allows: use and adaptation with attribution, **non-commercial only**; adapted material (including, arguably,
  a model trained on it) must be shared under the same licence.
- **Non-commercial.** Fine for the Tanmiathon 2026 hackathon demo. For any commercial use of NAMAA, remove
  BePLi and retrain with `litter_commercial_safe.yaml` (PlastOPol + TACO only).
- Required attribution: cite as above, link the DOI and the licence, state the changes.

## Models

- Ultralytics (training/inference code and pretrained YOLO weights): AGPL-3.0 (see `vision-service/README.md`).
- YOLO-World baseline uses OpenAI CLIP (MIT) for the text prompts; baseline only, not shipped.
