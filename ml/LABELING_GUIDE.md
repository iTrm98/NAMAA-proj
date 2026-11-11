# Labelling guide: litter boxes in Label Studio

One class, `litter`, one box per item. Label Studio runs locally from its own
compose file (not part of the NAMAA stack); its data stays in
`ml/data/labelstudio/` (git-ignored), the photos are mounted read-only from
`ml/photos/`. Take the photos first: [PHOTO_GUIDE.md](PHOTO_GUIDE.md).

## What counts as litter

Box it:
- bottles, cans, cups, plastic bags, food wrappers, paper (flat or crumpled),
  cardboard, cigarette butts, bottle caps, straws, plastic pieces;
- a piece that is partly hidden (under the plant, half buried): box the visible part;
- crushed, torn or dirty items: still litter.

Do not box:
- the plant, leaves, sticks, stones, sand, soil, the tray, tape marks, shadows;
- reflections or the shadow of an item (box the item only).

## How tight

- The box touches the outermost visible pixels of the item on all four sides;
  no extra sand around it, nothing of the item cut off.
- Shadow not included.
- Two items touching: two boxes. A pile you cannot separate: one box around it.
- A bag with something inside: one box (the bag).
- Items cut by the photo edge: box the visible part.

## What to skip

- Items smaller than about 10x10 pixels in the photo, or so blurred you cannot
  tell what they are: no box.
- A whole photo that is blurred, dark or shows hands: press **Skip** (it is left out
  of the export). Do not skip clean photos: see below.
- **Clean photo (no litter): delete every draft box and press Submit.** A submitted
  task with no boxes becomes an empty label file, i.e. a clean image.

## 1. Start Label Studio

Only when there is RAM to spare (not during a training run).

```powershell
cd C:\dev\NAMAA-proj\ml\labelstudio
docker compose up -d          # image heartexlabs/label-studio:1.18.0, http://localhost:8090
docker compose down           # when done; projects and labels stay in ml\data\labelstudio
```

First visit: sign up with any email and password (stored only in `ml/data/labelstudio/`).

## 2. Set up a project (one per set)

Make two projects: `NAMAA demo-tray` and `NAMAA local-train`. Never mix the sets.

1. **Create Project** > name > **Labeling Setup** > **Custom template** > **Code**:
   paste [labelstudio/labeling-config.xml](labelstudio/labeling-config.xml)
   (one `RectangleLabels` named `label` with the single class `litter`, hotkey `1`).
   Save.
2. **Settings > Cloud Storage > Add Source Storage > Local files**:
   - Absolute local path: `/label-studio/files/photos/demo-tray`
     (`/label-studio/files/photos/local-train` for the other project)
   - File filter regex: `.*\.(jpe?g|png)$`
   - "Treat every bucket object as a source file": on.
   - **Add storage, but do not press Sync** when you import a prelabel file
     (Sync would add every photo a second time, without drafts). Label Studio
     needs this storage only to allow serving the files.
3. **Settings > Annotation**: turn on "Use predictions to prelabel tasks" and pick the
   model version shown (for example `litter-s640-all/best.pt`), so the draft boxes
   are already on the image when you open a task.

## 3. Draft boxes and import

Run in a normal PowerShell (CPU by default, ~1 s per photo; it does not need Docker):

```powershell
cd C:\dev\NAMAA-proj\ml
.\.venv\Scripts\python.exe prelabel.py photos\demo-tray\litter photos\demo-tray\clean
# -> photos\demo-tray-prelabel.json
```

Options: `--weights <best.pt>` (default `runs\litter-s640-all\weights\best.pt`),
`--conf 0.15` (low on purpose: you delete wrong drafts rather than draw missing
ones), `--device 0` for the GPU, `--no-predict` (tasks without drafts).

In the project: **Import** > upload the JSON file > Import. Each photo becomes a task
with a prediction. Image URLs look like
`/data/local-files/?d=photos/demo-tray/litter/IMG_001.jpg`; if images do not show,
check step 2.2 (storage path) and that the photo is under `ml/photos/`.

Without prelabels: skip the import and press **Sync** on the storage instead.

## 4. Label

Open **Label All Tasks**. Per photo: delete wrong drafts, fix the edges of the
right ones, draw missing ones (press `1`, then drag), then **Submit**. Zoom with
the mouse wheel for small items like cigarette butts. Check the task list at the
end: every task should show one annotation.

## 5. Export and place

1. **Export** > **JSON** (not JSON-MIN) > Export, save as for example
   `C:\dev\NAMAA-proj\ml\photos\export-demo-tray.json`.
2. Convert into the YOLO layout:

```powershell
cd C:\dev\NAMAA-proj\ml
.\.venv\Scripts\python.exe export_labels.py --json photos\export-demo-tray.json --set demo-tray
.\.venv\Scripts\python.exe export_labels.py --json photos\export-local-train.json --set local-train
```

This writes `ml/photos/<set>/images/<folder>__<name>.jpg` and
`ml/photos/<set>/labels/<folder>__<name>.txt` (`0 cx cy w h`, normalised; empty
for clean photos), lists tasks left out (not labelled or skipped), and stops if a
photo is in both sets. `--overwrite` replaces an earlier export.

Alternative: **Export > YOLO** gives a zip with `labels/*.txt`:

```powershell
.\.venv\Scripts\python.exe export_labels.py --yolo photos\project-1-yolo.zip --set demo-tray
```

Here every raw photo in `demo-tray\litter` and `demo-tray\clean` without a label
file becomes clean, so use it only when every task was submitted. The JSON route
is safer.

3. Check: `evaluate.py --weights runs\litter-s640-all\weights\best.pt --test demo-tray --conf val`.
