"""Download the extra ground-level litter sets (litter AI v2) from Roboflow Universe.

Run it yourself: it needs your Roboflow key in the environment, and the key is never
printed, logged or written to disk.

    PowerShell:  $env:ROBOFLOW_API_KEY = "<your key>"
                 python fetch_extra_data.py --list      # metadata only: licence, images, classes (no download)
                 python fetch_extra_data.py             # download every source that passes the licence check
                 python fetch_extra_data.py --only rf_roads rf_ltp18
                 (sets already in ml/data/extra/raw/<id>/ are skipped; --force fetches them again,
                  --backup also tries the roboflow_backup list)

Sources are listed in extra_sources.json. For each one the licence is read from the Roboflow
API; a set with a missing or unlisted licence is SKIPPED (and a non-commercial one is kept
only with --allow-noncommercial, and flagged so build_v2.py leaves it out of the
commercial-safe list). Each set is exported as `yolov8`, unzipped to
ml/data/extra/raw/<id>/ and described by <id>/SOURCE.json (licence, URL, version, classes,
file counts; no key). Everything under ml/data/ is git-ignored.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import zipfile
from pathlib import Path

import requests

ML = Path(__file__).resolve().parent
RAW = ML / "data" / "extra" / "raw"
API = "https://api.roboflow.com"


def key() -> str:
    k = os.environ.get("ROBOFLOW_API_KEY", "").strip()
    if not k:
        raise SystemExit("Set the ROBOFLOW_API_KEY environment variable first "
                         "(PowerShell: $env:ROBOFLOW_API_KEY = '<your key>').")
    return k


def get_json(url: str, k: str, retries: int = 3) -> dict:
    """GET with the key as a query parameter. Errors never include the URL (it holds the key)."""
    last = ""
    for i in range(retries):
        try:
            r = requests.get(url, params={"api_key": k}, timeout=60)
            if r.status_code == 200:
                return r.json()
            last = f"HTTP {r.status_code}"
            if r.status_code in (401, 403, 404):
                break
        except requests.RequestException as e:
            last = type(e).__name__  # not str(e): it can contain the full URL
        time.sleep(2 * (i + 1))
    raise RuntimeError(last or "request failed")


def project_info(src: dict, k: str) -> dict:
    meta = get_json(f"{API}/{src['workspace']}/{src['project']}", k)
    proj = meta.get("project", {})
    versions = meta.get("versions", [])
    vers = []  # (number, images), newest first
    for v in versions:
        try:
            vers.append((int(str(v.get("id", "")).rsplit("/", 1)[-1]), v.get("images")))
        except ValueError:
            pass
    vers.sort(reverse=True)
    if src.get("version"):
        vers = [x for x in vers if x[0] == src["version"]] or [(src["version"], None)]
    version, images = vers[0] if vers else (None, None)
    classes = proj.get("classes") or {}
    return {
        "licence": (proj.get("license") or "").strip(),
        "type": proj.get("type"),
        "images_project": proj.get("images"),
        "version": version,
        "images_version": images,
        "all_versions": vers,
        "classes": sorted(classes) if isinstance(classes, dict) else classes,
    }


def licence_ok(licence: str, cfg: dict, allow_nc: bool) -> tuple[bool, str]:
    norm = licence.strip().lower()
    if not norm:
        return False, "no licence stated"
    if norm in (x.lower() for x in cfg["allowed_licences"]):
        return True, "ok"
    if norm in (x.lower() for x in cfg["noncommercial_licences"]):
        return (allow_nc, "non-commercial" + ("" if allow_nc else " (use --allow-noncommercial to keep)"))
    return False, f"licence '{licence}' is not on the allowed list"


def safe_extract(zip_path: Path, dest: Path) -> None:
    dest.mkdir(parents=True, exist_ok=True)
    root = dest.resolve()
    with zipfile.ZipFile(zip_path) as z:
        for m in z.infolist():
            target = (dest / m.filename).resolve()
            if root not in target.parents and target != root:
                raise RuntimeError(f"unsafe path in zip: {m.filename}")
        z.extractall(dest)


def export_link(src: dict, version: int, k: str) -> str:
    path = f"{API}/{src['workspace']}/{src['project']}/{version}/yolov8"
    for _ in range(36):  # the export may take a moment to be generated (3 min max)
        data = get_json(path, k)
        link = (data.get("export") or {}).get("link")
        if link:
            return link
        time.sleep(5)
    raise RuntimeError("export not generated in time")


def download(src: dict, info: dict, k: str) -> tuple[Path, int]:
    """Try the newest versions first (a version without a generated dataset returns an error)."""
    tried = []
    link, used = None, None
    for ver, n_img in info["all_versions"][:5] or [(info["version"], None)]:
        if n_img == 0:
            continue
        try:
            link, used = export_link(src, ver, k), ver
            break
        except RuntimeError as e:
            tried.append(f"v{ver}: {e}")
            print(f"    version {ver} export failed: {e}")
    if not link:
        raise RuntimeError("no version could be exported (" + "; ".join(tried) + ")")
    dest = RAW / src["id"]
    dest.mkdir(parents=True, exist_ok=True)
    zip_path = dest / "_export.zip"
    with requests.get(link, stream=True, timeout=120) as r:  # signed storage link, no key in it
        r.raise_for_status()
        with open(zip_path, "wb") as f:
            for chunk in r.iter_content(1 << 20):
                f.write(chunk)
    safe_extract(zip_path, dest)
    zip_path.unlink()
    return dest, used


def count_files(root: Path) -> dict:
    out = {}
    for split in ("train", "valid", "val", "test"):
        imgs = root / split / "images"
        if imgs.is_dir():
            out[split] = {"images": sum(1 for p in imgs.iterdir() if p.is_file()),
                          "labels": sum(1 for p in (root / split / "labels").glob("*.txt"))}
    return out


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--list", action="store_true", help="print licence, image count and classes only; download nothing")
    ap.add_argument("--only", nargs="*", help="source ids to handle (default: all)")
    ap.add_argument("--allow-noncommercial", action="store_true")
    ap.add_argument("--backup", action="store_true", help="also try the roboflow_backup sets in extra_sources.json")
    ap.add_argument("--force", action="store_true", help="download again even if ml/data/extra/raw/<id>/SOURCE.json exists")
    ap.add_argument("--sources", default=str(ML / "extra_sources.json"))
    args = ap.parse_args()

    cfg = json.loads(Path(args.sources).read_text(encoding="utf-8"))
    k = key()
    pool = cfg["roboflow"] + (cfg.get("roboflow_backup", []) if args.backup else [])
    wanted = [s for s in pool if not args.only or s["id"] in args.only]
    summary = []
    for src in wanted:
        sid = src["id"]
        if not args.list and not args.force and (RAW / sid / "SOURCE.json").is_file():
            print(f"[{sid}] already downloaded: skipped (use --force to fetch again)")
            summary.append((sid, "have", ""))
            continue
        url = f"https://universe.roboflow.com/{src['workspace']}/{src['project']}"
        try:
            info = project_info(src, k)
        except RuntimeError as e:
            print(f"[{sid}] SKIP: could not read project info ({e}) {url}")
            summary.append((sid, "skipped", str(e)))
            continue
        ok, why = licence_ok(info["licence"], cfg, args.allow_noncommercial)
        print(f"[{sid}] {url}\n    licence: {info['licence'] or '(none)'} -> {why}\n"
              f"    version {info['version']}, images {info['images_version']} (project {info['images_project']}), "
              f"type {info['type']}\n    classes: {info['classes']}")
        if not ok:
            summary.append((sid, "skipped", why))
            continue
        if args.list:
            summary.append((sid, "listed", info["licence"]))
            continue
        try:
            dest, info["version"] = download(src, info, k)
        except (RuntimeError, requests.RequestException, zipfile.BadZipFile) as e:
            msg = str(e) if isinstance(e, RuntimeError) else type(e).__name__  # RuntimeError text is status-only
            print(f"    download FAILED: {msg}")
            summary.append((sid, "failed", msg))
            continue
        record = {"id": sid, "source": "roboflow-universe", "url": url, "workspace": src["workspace"],
                  "project": src["project"], "scene": src.get("scene"), **info,
                  "commercial_ok": info["licence"].lower() in (x.lower() for x in cfg["allowed_licences"]),
                  "files": count_files(dest)}
        record.pop("all_versions", None)
        (dest / "SOURCE.json").write_text(json.dumps(record, indent=2), encoding="utf-8")
        print(f"    downloaded to {dest}: {record['files']}")
        summary.append((sid, "downloaded", info["licence"]))

    print("\nSummary")
    for sid, status, note in summary:
        print(f"  {sid:<12} {status:<11} {note}")
    if any(s == "downloaded" for _, s, _ in summary):
        print("\nNext: tell Claude the fetch is done (it runs the checks), then build_v2.py.")


if __name__ == "__main__":
    if sys.platform == "win32":
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    main()
