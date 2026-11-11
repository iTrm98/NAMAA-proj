# Photo guide: demo tray (Logitech C920)

On demo day the C920 looks down at a tray of sand or soil with a small plant
and some litter. These photos measure how well the model works on exactly that
scene (`demo-tray`, the test set) and, separately, teach it that scene
(`local-train`). All photos stay local: `ml/photos/` is git-ignored.

## Set up once, then never move the camera

1. Build the tray as on demo day: tray, sand or soil, the plant, the table, the
   room (or the same kind of light).
2. Mount the C920 at its demo position and height (stand or clamp, same angle).
   Mark the tray corners and the stand on the table with tape.
3. Take photos at the size the app uses: **1280x720** (the Hardware page asks the
   browser for 1280x720). In the Windows Camera app: Settings > Photo quality >
   16:9 0.9 MP (1280x720). Turn off HDR and filters.
4. **Do not move the camera or the tray between the test photos and the demo.**
   If it moves, the demo-tray score no longer describes the demo. If you have to
   move it, take a new demo-tray set.

## Items

Use real, used-looking items: plastic bottle, drink can, plastic bag, paper
(crumpled and flat), food wrapper, cigarette butt, paper or plastic cup.
Have two or three of each (different brands, colours, sizes), plus a few items
that are not litter (the plant, a stone, a leaf, a stick) to check for false alarms.

## What to shoot (about 40-50 photos per set)

- **1 to 6 litter items per photo.** Mix the counts: some photos with one item,
  some with five or six.
- **About 1/3 clean photos**: the tray with the plant and no litter at all. Also
  rake the sand, move the plant, add stones or leaves between clean shots.
- Vary between photos: item positions (centre, edges, corners, half under the
  plant, touching each other), standing / lying / crushed / half buried,
  light (room light, window light, a lamp from the side), shadows (hand or
  object shadow across the tray), sand smooth or messy.
- Keep it honest: no item fully hidden, no blur (wait for autofocus), no hands
  in the photo.
- One item type should not appear only in one place or one light.

## Where the photos go

| Folder | What |
|---|---|
| `ml/photos/demo-tray/litter/` | test photos with litter |
| `ml/photos/demo-tray/clean/` | test photos without litter |
| `ml/photos/local-train/litter/` | extra training photos with litter |
| `ml/photos/local-train/clean/` | extra training photos without litter |

After labelling (see [LABELING_GUIDE.md](LABELING_GUIDE.md)), `export_labels.py`
writes the YOLO layout next to them: `ml/photos/<set>/images/` and
`ml/photos/<set>/labels/` (one `.txt` per image; clean photos get an empty
`.txt`). `evaluate.py --test demo-tray` reads `ml/photos/demo-tray/{images,labels}`
and counts the empty-label images as clean (false alarms per clean image). Do not
also copy them into `ml/photos/clean/`, or they are counted twice.

## Test photos are never training photos

- Take the **demo-tray** set first, in one session, and do not touch it again.
- Take the **local-train** set in a separate session: other item positions,
  other items where possible, another time of day. Never copy or re-crop a
  demo-tray photo into local-train. `export_labels.py` and `finetune_local.py`
  stop if the same file is in both sets.
- Never pick thresholds or training settings by looking at demo-tray results
  over and over; use `--conf val` in `evaluate.py`.

## Order of work

1. Shoot demo-tray (litter + clean). Shoot local-train in another session.
2. Label both in Label Studio ([LABELING_GUIDE.md](LABELING_GUIDE.md)), export each set.
3. Score the current model: `evaluate.py --test demo-tray --conf val`.
4. If needed, `finetune_local.py`, then score the new model on demo-tray once.
