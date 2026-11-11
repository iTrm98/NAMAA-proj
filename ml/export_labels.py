"""Turn a Label Studio export into a YOLO set: ml/photos/<set>/{images,labels}.

Two inputs:
  --json <export.json>   Label Studio "JSON" export (preferred). Every task with a submitted
                         annotation is written; a submitted annotation with no boxes becomes
                         a clean photo (empty .txt). Tasks never labelled or skipped are left
                         out and listed.
  --yolo <folder|zip>    Label Studio "YOLO" export. Its labels/*.txt are matched to the raw
                         photos in --source folders by file name; a raw photo without a label
                         file becomes clean (empty .txt), so only use this when every photo
                         was labelled.

Sets: demo-tray (test set for evaluate.py --test demo-tray), local-train (extra training
photos for finetune_local.py), or any folder. Image names become <raw folder>__<name>
(e.g. litter__IMG_001.jpg), so evaluate.py also reports `litter` and `clean` groups.
The script refuses photos that also exist (same bytes) in the other set: test and
training photos must never be the same.

Examples (from ml/):
    .venv\\Scripts\\python.exe export_labels.py --json photos\\export-demo.json --set demo-tray
    .venv\\Scripts\\python.exe export_labels.py --yolo photos\\project-1-yolo.zip --set local-train ^
        --source photos\\local-train\\litter photos\\local-train\\clean
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import math
import re
import shutil
import zipfile
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlparse

ML = Path(__file__).resolve().parent
PHOTOS = ML / "photos"
IMG_EXT = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}
SETS = {"demo-tray": PHOTOS / "demo-tray", "local-train": PHOTOS / "local-train"}


def md5(p: Path) -> str:
    return hashlib.md5(p.read_bytes()).hexdigest()


def resolve(p: str) -> Path:
    q = Path(p)
    return q if q.is_absolute() or q.exists() else ML / q


def url_to_path(url: str) -> Path:
    """/data/local-files/?d=photos/demo-tray/litter/x.jpg -> ml/photos/demo-tray/litter/x.jpg"""
    d = parse_qs(urlparse(url).query).get("d")
    if not d:
        raise ValueError(f"not a local-files URL: {url}")
    rel = unquote(d[0]).lstrip("/")
    if not rel.startswith("photos/"):
        raise ValueError(f"URL outside ml/photos: {url}")
    return PHOTOS / rel[len("photos/"):]


def box_to_yolo(v: dict) -> str | None:
    """Label Studio percent box (rotation around the top-left corner) -> YOLO line."""
    x, y, w, h = (float(v[k]) / 100 for k in ("x", "y", "width", "height"))
    a = math.radians(float(v.get("rotation", 0) or 0))
    if a:
        # Percent units differ per axis, so this envelope is approximate for rotated boxes.
        pts = [(x + dx * math.cos(a) - dy * math.sin(a), y + dx * math.sin(a) + dy * math.cos(a))
               for dx, dy in ((0, 0), (w, 0), (0, h), (w, h))]
        xs, ys = [p[0] for p in pts], [p[1] for p in pts]
        x, y, w, h = min(xs), min(ys), max(xs) - min(xs), max(ys) - min(ys)
    x1, y1 = max(0.0, x), max(0.0, y)
    x2, y2 = min(1.0, x + w), min(1.0, y + h)
    if x2 - x1 <= 0 or y2 - y1 <= 0:
        return None
    return f"0 {(x1 + x2) / 2:.6f} {(y1 + y2) / 2:.6f} {x2 - x1:.6f} {y2 - y1:.6f}"


def from_json(export: Path) -> tuple[list[tuple[Path, list[str]]], list[str]]:
    tasks = json.loads(export.read_text(encoding="utf-8"))
    items, left_out = [], []
    for t in tasks:
        url = t.get("data", {}).get("image", "")
        anns = [a for a in t.get("annotations", []) if not a.get("was_cancelled")]
        if not anns:
            left_out.append(url)
            continue
        ann = max(anns, key=lambda a: a.get("updated_at") or a.get("created_at") or "")
        lines = []
        for r in ann.get("result", []):
            if r.get("type") != "rectanglelabels":
                continue
            labels = r.get("value", {}).get("rectanglelabels", [])
            if labels and labels != ["litter"]:
                print(f"warning: label {labels} in {url} written as litter")
            line = box_to_yolo(r["value"])
            if line:
                lines.append(line)
        items.append((url_to_path(url), lines))
    return items, left_out


def from_yolo(export: Path, sources: list[Path]) -> tuple[list[tuple[Path, list[str]]], list[str]]:
    labels: dict[str, list[str]] = {}
    if export.suffix.lower() == ".zip":
        with zipfile.ZipFile(export) as z:
            for n in z.namelist():
                if "/labels/" in "/" + n and n.endswith(".txt"):
                    labels[Path(n).stem] = io.TextIOWrapper(z.open(n), encoding="utf-8").read().splitlines()
    else:
        for p in (export / "labels").glob("*.txt"):
            labels[p.stem] = p.read_text(encoding="utf-8").splitlines()
    # Label Studio may prefix names with a short hash ("1a2b3c4d-IMG_001"): match both.
    by_stem = {re.sub(r"^[0-9a-f]{8}-", "", k): v for k, v in labels.items()}
    items, used = [], set()
    for src in sources:
        for img in sorted(p for p in src.rglob("*") if p.suffix.lower() in IMG_EXT):
            rows = labels.get(img.stem, by_stem.get(img.stem))
            if rows is not None:
                used.add(img.stem)
            # one class: force class id 0
            lines = ["0 " + " ".join(r.split()[1:5]) for r in (rows or []) if len(r.split()) >= 5]
            items.append((img, lines))
    unmatched = [k for k in by_stem if k not in used]
    return items, [f"label without photo: {k}" for k in unmatched]


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    src = ap.add_mutually_exclusive_group(required=True)
    src.add_argument("--json", help="Label Studio JSON export")
    src.add_argument("--yolo", help="Label Studio YOLO export (folder or zip)")
    ap.add_argument("--set", required=True, help="demo-tray, local-train or a target folder")
    ap.add_argument("--source", nargs="*", default=None,
                    help="raw photo folders for --yolo (default <set>/litter and <set>/clean)")
    ap.add_argument("--overwrite", action="store_true", help="empty <set>/images and <set>/labels first")
    args = ap.parse_args()

    target = SETS.get(args.set, resolve(args.set))
    if args.json:
        items, notes = from_json(resolve(args.json))
    else:
        sources = [resolve(s) for s in args.source] if args.source else [target / "litter", target / "clean"]
        items, notes = from_yolo(resolve(args.yolo), [s for s in sources if s.is_dir()])
    if not items:
        raise SystemExit("nothing to export")

    # Test and training photos must never be the same.
    other = {"demo-tray": SETS["local-train"], "local-train": SETS["demo-tray"]}.get(args.set)
    if other is not None and other.is_dir():
        theirs = {md5(p) for p in other.rglob("*") if p.suffix.lower() in IMG_EXT}
        clash = [str(img) for img, _ in items if img.is_file() and md5(img) in theirs]
        if clash:
            raise SystemExit(f"{len(clash)} photo(s) also in {other}, e.g. {clash[0]}: stop")

    img_dir, lbl_dir = target / "images", target / "labels"
    if args.overwrite:
        shutil.rmtree(img_dir, ignore_errors=True)
        shutil.rmtree(lbl_dir, ignore_errors=True)
    elif img_dir.is_dir() and any(img_dir.iterdir()):
        raise SystemExit(f"{img_dir} is not empty: pass --overwrite to replace it")
    img_dir.mkdir(parents=True, exist_ok=True)
    lbl_dir.mkdir(parents=True, exist_ok=True)

    n_clean = n_boxes = 0
    missing = []
    for img, lines in items:
        if not img.is_file():
            missing.append(str(img))
            continue
        name = f"{img.parent.name}__{img.name}"
        shutil.copy2(img, img_dir / name)
        (lbl_dir / Path(name).with_suffix(".txt").name).write_text(
            "\n".join(lines) + ("\n" if lines else ""), encoding="utf-8")
        n_boxes += len(lines)
        n_clean += not lines

    written = len(items) - len(missing)
    print(f"{written} images ({n_clean} clean, {n_boxes} boxes) -> {target}\\{{images,labels}}")
    for m in missing:
        print(f"missing photo: {m}")
    if notes:
        print(f"{len(notes)} left out (not labelled / skipped / unmatched):")
        for n in notes[:20]:
            print(f"  {n}")


if __name__ == "__main__":
    main()
