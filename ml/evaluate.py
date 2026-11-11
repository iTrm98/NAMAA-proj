"""Score a detector as a one-class `litter` model on a test set.

Works for:
- custom: a fine-tuned litter model (every class counts as litter),
- coco:   a COCO model with the vision-service litter-class mapping
          (DEFAULT_LITTER_CLASSES in vision-service/app/waste.py), merged into one class,
- world:  a YOLO-World model with text prompts, merged into one class.

Metrics (one class, IoU matching at 0.5 for P/R, COCO 101-point AP as in pycocotools):
precision, recall, F1 and TP/FP/FN at each threshold, mAP50 and mAP50-95, per dataset
(file prefix `<dataset>__`) and overall (ALL); false alarms per clean image at each threshold
(clean = test images with an empty label file, plus ml/photos/clean/ if it exists), and
inference time per image on CPU (preprocess + inference + postprocess, median).

Thresholds: --conf 0.25 (a number) scores one fixed threshold. --conf val picks two on the
val split, never on the test set: `best_f1` (best F1 at IoU 0.5) and `recall_0.70` (best
precision among points with recall >= --min-recall; if that recall is unreachable, the
highest-recall point, flagged reached=false). Both are scored on the test set; each one's
conf and source go into the JSON (`thresholds`) and the printed table.
Val set (--val-set, default auto): `balanced` = lists/balanced_val.txt from make_balanced.py,
`all` = images/val; auto picks balanced when <run>/args.yaml says the weights were trained on
litter_balanced.yaml, else all. Its name, image and box count go into the JSON (`val_set`).

Test sets:
    public-test  ml/data/yolo/images/test   (never used for training or threshold tuning)
    demo-tray    ml/photos/demo-tray/images (labels in ml/photos/demo-tray/labels)
    <path>       any images folder with a sibling `labels` folder, or a .txt list of images

Examples:
    python evaluate.py --weights runs/litter-v1/weights/best.pt --test public-test
    python evaluate.py --weights weights/yolo26n.pt --mode coco
    python evaluate.py --weights weights/yolov8s-worldv2.pt --mode world --conf 0.1
"""

from __future__ import annotations

import argparse
import ast
import json
import statistics
from collections import defaultdict
from pathlib import Path

import numpy as np

ML = Path(__file__).resolve().parent
ROOT = ML.parent
IMG_EXT = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}
IOUS = np.linspace(0.5, 0.95, 10)
WORLD_PROMPTS = ["litter", "plastic bottle", "plastic bag", "can", "paper", "cardboard",
                 "food wrapper", "cup", "cigarette butt"]


def coco_litter_classes() -> tuple[str, ...]:
    """Read DEFAULT_LITTER_CLASSES from the vision-service source (no import, no deps)."""
    src = (ROOT / "vision-service" / "app" / "waste.py").read_text(encoding="utf-8")
    for node in ast.parse(src).body:
        if isinstance(node, ast.Assign) and any(getattr(t, "id", "") == "DEFAULT_LITTER_CLASSES" for t in node.targets):
            return tuple(ast.literal_eval(node.value))
    raise SystemExit("DEFAULT_LITTER_CLASSES not found in vision-service/app/waste.py")


VAL_SETS = {
    "all": ML / "data" / "yolo" / "images" / "val",                      # full val split (litter_all.yaml)
    "balanced": ML / "data" / "yolo" / "lists" / "balanced_val.txt",     # make_balanced.py (litter_balanced.yaml)
}


def run_data_config(weights: str) -> str | None:
    """The `data` field of the training run's args.yaml (<run>/args.yaml next to <run>/weights/),
    or None if there is no such file or field. Plain line scan: no yaml dependency."""
    w = Path(weights)
    for cand in (w.parent.parent / "args.yaml", w.parent / "args.yaml"):
        try:
            lines = cand.read_text(encoding="utf-8", errors="replace").splitlines()
        except OSError:
            continue
        for line in lines:
            if line.startswith("data:"):
                value = line.split(":", 1)[1].strip().strip("'\"")
                return value or None
        return None
    return None


def choose_val_set(val_set: str, data: str | None) -> tuple[str, str]:
    """(name, reason) of the val set used by --conf val. `auto`: balanced when the run was
    trained on litter_balanced.yaml, else the full val split."""
    if val_set != "auto":
        return val_set, f"--val-set {val_set}"
    if not data:
        return "all", "auto: no run args.yaml / data field"
    cfg = data.replace("\\", "/").rsplit("/", 1)[-1]
    if cfg.lower() == "litter_balanced.yaml":
        return "balanced", f"auto: run trained on {cfg}"
    return "all", f"auto: run trained on {cfg}"


def test_images(test: str) -> list[Path]:
    if test == "public-test":
        folder = ML / "data" / "yolo" / "images" / "test"
    elif test == "demo-tray":
        folder = ML / "photos" / "demo-tray" / "images"
    else:
        folder = Path(test)
    if folder.suffix == ".txt":
        return [Path(l.strip()) for l in folder.read_text().splitlines() if l.strip()]
    if not folder.is_dir():
        raise SystemExit(f"test set not found: {folder}")
    return sorted(p for p in folder.rglob("*") if p.suffix.lower() in IMG_EXT)


def label_path(img: Path) -> Path:
    parts = list(img.parts)
    i = len(parts) - 1 - parts[::-1].index("images")
    parts[i] = "labels"
    return Path(*parts).with_suffix(".txt")


def read_gt(img: Path, w: int, h: int) -> np.ndarray:
    lp = label_path(img)
    rows = []
    if lp.is_file():
        for line in lp.read_text().splitlines():
            v = line.split()
            if len(v) >= 5:
                cx, cy, bw, bh = (float(x) for x in v[1:5])
                rows.append([(cx - bw / 2) * w, (cy - bh / 2) * h, (cx + bw / 2) * w, (cy + bh / 2) * h])
    return np.array(rows, dtype=float).reshape(-1, 4)


def iou_matrix(a: np.ndarray, b: np.ndarray) -> np.ndarray:
    if len(a) == 0 or len(b) == 0:
        return np.zeros((len(a), len(b)))
    lt = np.maximum(a[:, None, :2], b[None, :, :2])
    rb = np.minimum(a[:, None, 2:], b[None, :, 2:])
    inter = np.clip(rb - lt, 0, None).prod(2)
    area = lambda x: (x[:, 2] - x[:, 0]) * (x[:, 3] - x[:, 1])
    return inter / (area(a)[:, None] + area(b)[None, :] - inter + 1e-9)


def match(pred: np.ndarray, conf: np.ndarray, gt: np.ndarray) -> np.ndarray:
    """Greedy matching by confidence; returns a (n_pred, 10) true-positive matrix."""
    tp = np.zeros((len(pred), len(IOUS)), dtype=bool)
    if len(pred) == 0 or len(gt) == 0:
        return tp
    iou = iou_matrix(pred, gt)
    order = np.argsort(-conf)
    for k, t in enumerate(IOUS):
        used = np.zeros(len(gt), dtype=bool)
        for i in order:
            cand = np.where((iou[i] >= t) & ~used)[0]
            if len(cand):
                j = cand[np.argmax(iou[i, cand])]
                used[j] = True
                tp[i, k] = True
    return tp


def average_precision(tp: np.ndarray, conf: np.ndarray, n_gt: int) -> np.ndarray:
    if n_gt == 0 or len(tp) == 0:
        return np.zeros(len(IOUS))
    order = np.argsort(-conf, kind="stable")
    tp = tp[order]
    # COCO 101-point AP: at each recall level r, the best precision reached at recall >= r,
    # and 0 beyond the highest recall reached. (No linear interpolation towards recall 1.0:
    # that would credit a model that stops at 5% recall with up to ~0.5 AP.)
    aps = []
    x = np.linspace(0, 1, 101)
    for k in range(len(IOUS)):
        ctp = np.cumsum(tp[:, k])
        cfp = np.cumsum(~tp[:, k])
        rec = ctp / n_gt
        prec = np.flip(np.maximum.accumulate(np.flip(ctp / (ctp + cfp))))
        idx = np.searchsorted(rec, x, side="left")
        q = np.where(idx < len(prec), prec[np.minimum(idx, len(prec) - 1)], 0.0)
        aps.append(q.mean())
    return np.array(aps)


def load_model(weights: str, mode: str, device: str):
    from ultralytics import YOLO, YOLOWorld

    if mode == "world":
        m = YOLOWorld(weights)
        m.set_classes(WORLD_PROMPTS)
        return m, None
    m = YOLO(weights)
    if mode == "coco":
        wanted = coco_litter_classes()
        ids = [i for i, n in m.names.items() if n in wanted]
        return m, ids
    return m, None


def predict(model, img: Path, args, classes, device: str, conf: float):
    r = model.predict(str(img), imgsz=args.imgsz, conf=conf, iou=0.7, max_det=300, classes=classes,
                      agnostic_nms=args.mode != "custom", device=device, verbose=False)[0]
    b = r.boxes
    return (b.xyxy.cpu().numpy().reshape(-1, 4), b.conf.cpu().numpy().reshape(-1), r.orig_shape, r.speed)


def accuracy_pass(model, images, args, classes):
    """Predict at conf 0.001 on every image; collect per-group TP matrices and scores."""
    groups = defaultdict(lambda: {"tp": [], "conf": [], "n_gt": 0, "images": 0})
    clean_scores = []  # per clean image: the scores of its (false) detections
    for img in images:
        boxes, conf, (h, w), _ = predict(model, img, args, classes, args.device, 0.001)
        gt = read_gt(img, w, h)
        tp = match(boxes, conf, gt)
        ds = img.stem.split("__")[0] if "__" in img.stem else None
        for key in ("ALL", ds) if ds else ("ALL",):
            g = groups[key]
            g["tp"].append(tp)
            g["conf"].append(conf)
            g["n_gt"] += len(gt)
            g["images"] += 1
        if len(gt) == 0:
            clean_scores.append(conf)
    for g in groups.values():
        g["tp"] = np.concatenate(g["tp"]) if g["tp"] else np.zeros((0, 10), bool)
        g["conf"] = np.concatenate(g["conf"]) if g["conf"] else np.zeros(0)
    return groups, clean_scores


def pr_points(g) -> dict[str, np.ndarray]:
    """Operating points at IoU 0.5, one per distinct confidence (ties kept together):
    keeping every detection with conf >= conf[i] gives precision[i] and recall[i]."""
    order = np.argsort(-g["conf"], kind="stable")
    cf, tp = g["conf"][order], g["tp"][order, 0]
    if len(cf) == 0:
        z = np.zeros(0)
        return {"conf": z, "precision": z, "recall": z, "f1": z}
    ctp = np.cumsum(tp)
    n = np.arange(1, len(cf) + 1)
    last = np.r_[cf[1:] != cf[:-1], True]  # last index of each group of equal scores
    cf, ctp, n = cf[last], ctp[last], n[last]
    return {"conf": cf, "precision": ctp / n, "recall": ctp / max(1, g["n_gt"]),
            "f1": 2 * ctp / (n + g["n_gt"])}


def _floor(c: float) -> float:
    """Round a threshold down (4 decimals) so the chosen val point keeps all its detections."""
    return max(0.001, float(np.floor(c * 1e4) / 1e4))


def _point(pts, i: int, source: str, **extra) -> dict:
    return {"conf": _floor(pts["conf"][i]), "source": source,
            "val_precision": round(float(pts["precision"][i]), 3),
            "val_recall": round(float(pts["recall"][i]), 3),
            "val_f1": round(float(pts["f1"][i]), 3), **extra}


def best_f1_point(g) -> dict:
    """Threshold with the best F1 at IoU 0.5 (val split only)."""
    pts = pr_points(g)
    if len(pts["conf"]) == 0:
        return {"conf": 0.25, "source": "val split: no detections, fallback 0.25"}
    return _point(pts, int(np.argmax(pts["f1"])), "val split: best F1 (IoU 0.5)")


def best_f1_conf(g) -> float:
    return best_f1_point(g)["conf"]


def recall_target_point(g, min_recall: float = 0.70) -> dict:
    """Threshold with the best precision among points with recall >= min_recall (val split only).
    If the target is unreachable: the highest-recall point, with reached = False."""
    pts = pr_points(g)
    if len(pts["conf"]) == 0:
        return {"conf": 0.25, "source": "val split: no detections, fallback 0.25",
                "reached": False, "target_recall": min_recall}
    ok = np.where(pts["recall"] >= min_recall)[0]
    if len(ok):
        i = int(ok[np.argmax(pts["precision"][ok])])  # ties: the first = highest conf
        return _point(pts, i, f"val split: best precision at recall >= {min_recall:.2f}",
                      reached=True, target_recall=min_recall)
    cand = np.where(pts["recall"] == pts["recall"].max())[0]
    i = int(cand[np.argmax(pts["precision"][cand])])
    return _point(pts, i, f"val split: recall {min_recall:.2f} NOT REACHED, highest-recall point used",
                  reached=False, target_recall=min_recall)


def metrics_at(g, conf: float) -> dict:
    """Precision, recall, F1 and counts at IoU 0.5 for detections with score >= conf."""
    sel = g["conf"] >= conf
    n_det = int(sel.sum())
    ntp = int(g["tp"][sel, 0].sum())
    p, r = ntp / max(1, n_det), ntp / max(1, g["n_gt"])
    return {"precision": round(p, 3), "recall": round(r, 3), "f1": round(2 * p * r / max(1e-9, p + r), 3),
            "detections": n_det, "tp": ntp, "fp": n_det - ntp, "fn": g["n_gt"] - ntp}


def print_table(result: dict) -> None:
    cols = ("threshold", "conf", "group", "images", "boxes", "P", "R", "F1", "TP", "FP", "FN",
            "mAP50", "mAP50-95")
    print("\n" + " ".join(f"{c:>11}" for c in cols))
    for name, th in result["thresholds"].items():
        for key, gr in result["groups"].items():
            m = gr["at"][name]
            row = (name, th["conf"], key, gr["images"], gr["boxes"], m["precision"], m["recall"], m["f1"],
                   m["tp"], m["fp"], m["fn"], gr["mAP50"], gr["mAP50_95"])
            print(" ".join(f"{str(v):>11}" for v in row))
        fa = result["false_alarms_per_clean_image"].get(name)
        print(f"{'':>11} false alarms per clean image ({result['clean_images']} images): {fa}")
    vs = result.get("val_set")
    if vs:
        print(f"thresholds tuned on val set '{vs['name']}' ({vs['reason']}): "
              f"{vs['images']} images, {vs['boxes']} boxes")
    for name, th in result["thresholds"].items():
        print(f"{name}: conf {th['conf']} from {th['source']}"
              + ("" if th.get("reached", True) else "   <-- RECALL TARGET NOT REACHED"))
    print("mAP50 and mAP50-95 do not depend on the threshold (all detections from conf 0.001).")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--weights", required=True)
    ap.add_argument("--mode", choices=["custom", "coco", "world"], default=None,
                    help="default: world if 'world' in the file name, coco if the model has a 'person' class, else custom")
    ap.add_argument("--test", default="public-test")
    ap.add_argument("--conf", default="0.25",
                    help="threshold for precision, recall and false alarms; 'val' = two thresholds from the "
                         "val set (see --val-set): best F1, and best precision at recall >= "
                         "--min-recall; never tuned on the test set")
    ap.add_argument("--val-set", choices=["auto", "balanced", "all"], default="auto",
                    help="val set for --conf val: balanced = lists/balanced_val.txt (make_balanced.py), "
                         "all = images/val; auto = balanced if the run's args.yaml says it was trained "
                         "on litter_balanced.yaml, else all")
    ap.add_argument("--min-recall", type=float, default=0.70,
                    help="recall target for the second --conf val threshold")
    ap.add_argument("--imgsz", type=int, default=640)
    ap.add_argument("--device", default="0", help="device for the accuracy pass (CPU timing is always on cpu)")
    ap.add_argument("--cpu-images", type=int, default=50, help="images timed on CPU, spread over the test set (0 = skip)")
    ap.add_argument("--clean-dir", default=str(ML / "photos" / "clean"))
    ap.add_argument("--out", default=None, help="write the result as JSON")
    args = ap.parse_args()

    if args.mode is None:
        if "world" in Path(args.weights).name.lower():
            args.mode = "world"
        else:
            from ultralytics import YOLO
            args.mode = "coco" if "person" in YOLO(args.weights).names.values() else "custom"

    images = test_images(args.test)
    val_info = None
    if args.conf == "val":  # resolve the val set before loading the model, so a missing list fails fast
        name, reason = choose_val_set(args.val_set, run_data_config(args.weights))
        path = VAL_SETS[name]
        if not path.exists():
            raise SystemExit(f"val set '{name}' not found: {path}"
                             + ("  (run make_balanced.py first)" if name == "balanced" else ""))
        val_images = test_images(str(path))
        val_info = {"name": name, "reason": reason, "path": str(path)}
        print(f"val set for thresholds: {name} ({reason}), {len(val_images)} images")
    model, classes = load_model(args.weights, args.mode, args.device)

    if args.conf == "val":
        val_groups, _ = accuracy_pass(model, val_images, args, classes)
        val_info.update(images=val_groups["ALL"]["images"], boxes=val_groups["ALL"]["n_gt"])
        thresholds = {"best_f1": best_f1_point(val_groups["ALL"]),
                      f"recall_{args.min_recall:.2f}": recall_target_point(val_groups["ALL"], args.min_recall)}
        for t in thresholds.values():
            t["source"] = t["source"].replace("val split", f"val set '{val_info['name']}'", 1)
    else:
        thresholds = {"fixed": {"conf": float(args.conf), "source": "fixed (--conf)"}}
    primary = next(iter(thresholds.values()))
    args.conf = primary["conf"]  # also used for the CPU timing pass

    groups, clean_scores = accuracy_pass(model, images, args, classes)
    clean_dir = Path(args.clean_dir)
    if clean_dir.is_dir():
        low = min(t["conf"] for t in thresholds.values())
        for img in sorted(p for p in clean_dir.rglob("*") if p.suffix.lower() in IMG_EXT):
            _, conf, _, _ = predict(model, img, args, classes, args.device, low)
            clean_scores.append(conf)

    result = {"weights": args.weights, "mode": args.mode, "test": args.test,
              "conf": args.conf, "conf_source": primary["source"],  # first threshold (kept for old readers)
              "thresholds": thresholds, "val_set": val_info, "imgsz": args.imgsz,
              "classes": None if classes is None else [model.names[i] for i in classes],
              "prompts": WORLD_PROMPTS if args.mode == "world" else None, "groups": {}}
    for key, g in sorted(groups.items(), key=lambda kv: kv[0] != "ALL"):
        aps = average_precision(g["tp"], g["conf"], g["n_gt"])
        at = {name: metrics_at(g, t["conf"]) for name, t in thresholds.items()}
        first = next(iter(at.values()))
        result["groups"][key] = {
            "images": g["images"], "boxes": g["n_gt"],
            "precision": first["precision"], "recall": first["recall"],  # at the first threshold
            "mAP50": round(float(aps[0]), 3), "mAP50_95": round(float(aps.mean()), 3),
            "at": at,
        }
    result["clean_images"] = len(clean_scores)
    result["false_alarms_per_clean_image"] = {
        name: (round(statistics.mean(int((c >= t["conf"]).sum()) for c in clean_scores), 3)
               if clean_scores else None)
        for name, t in thresholds.items()}

    if args.cpu_images > 0:
        cpu_model, cpu_classes = load_model(args.weights, args.mode, "cpu")
        step = max(1, len(images) // (args.cpu_images + 3))
        timed = images[::step][: args.cpu_images + 3]
        ms = []
        for i, img in enumerate(timed):
            *_, speed = predict(cpu_model, img, args, cpu_classes, "cpu", args.conf)
            if i >= 3:  # warm-up
                ms.append(speed["preprocess"] + speed["inference"] + speed["postprocess"])
        result["cpu_ms_per_image"] = round(statistics.median(ms), 1) if ms else None
        result["cpu_images_timed"] = len(ms)

    print(json.dumps(result, indent=2))
    print_table(result)
    if args.out:
        Path(args.out).parent.mkdir(parents=True, exist_ok=True)
        Path(args.out).write_text(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
