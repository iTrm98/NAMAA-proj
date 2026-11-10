# Training a custom litter model (`litter.pt`)

The default detector uses COCO classes that look like litter (bottles, cups, food). A model trained on **our own** park photos will be much better at what really lies on Makkah's lawns: plastic bags, tissue, cans, wrappers and food boxes. Nothing here runs automatically; it is a short guide for the team.

## 1. Collect photos

- 200-500 photos is a good start: different parks, times of day, sun and shade, the camera's real height and angle (save frames from the ESP32-CAM itself where possible).
- Include photos **without** litter (about a third): the model must learn what clean grass looks like.
- Photos of public places may show people: keep them out of Git (`data/samples/` is git-ignored) and blur faces before sharing.

## 2. Label

Use one of:

- **Label Studio** (free, local): `pip install label-studio`, then `label-studio start`; project type "Object Detection with Bounding Boxes"; export as **YOLO**.
- **CVAT** (free, web: app.cvat.ai) or **Roboflow** (free tier): export as **YOLO**.

Classes (keep the list short, consistent across the team):

```
0 plastic_bottle
1 can
2 plastic_bag
3 paper
4 food_box
5 other_litter
```

Draw one tight box per object. Skip objects smaller than about 10 px.

## 3. Dataset layout

```
litter-dataset/
  data.yaml
  images/train/*.jpg   images/val/*.jpg     (about 80 % / 20 %, different days in val)
  labels/train/*.txt   labels/val/*.txt     (one .txt per image, same name)
```

`data.yaml`:

```yaml
path: /content/litter-dataset   # or a local folder
train: images/train
val: images/val
names: [plastic_bottle, can, plastic_bag, paper, food_box, other_litter]
```

## 4. Train

**Google Colab** (free GPU: Runtime → Change runtime type → T4):

```python
!pip install ultralytics==8.4.208
from ultralytics import YOLO
model = YOLO("yolo26n.pt")                 # small and fast on CPU at inference
model.train(data="/content/litter-dataset/data.yaml", epochs=100, imgsz=640, batch=16, patience=20)
```

**Locally** (slow on CPU, fine on an NVIDIA GPU):

```bash
pip install ultralytics==8.4.208
yolo detect train data=litter-dataset/data.yaml model=yolo26n.pt epochs=100 imgsz=640
```

Check `runs/detect/train/results.png` and the mAP50 on the val set (aim for 0.6+). Look at `val_batch0_pred.jpg` by eye.

## 5. Use it

1. Copy `runs/detect/train/weights/best.pt` to `vision-service/models/litter.pt` (git-ignored; share it by link, never commit it).
2. `docker compose restart vision`.
3. `GET http://localhost:8001/health` now shows `"custom": true` and your class names. Every class of the custom model counts as litter; the COCO model keeps finding people for the privacy blur.

Licence: models trained with Ultralytics are covered by its AGPL-3.0 licence (see ../README.md).
