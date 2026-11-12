# Demo and test photos (data/samples/)

The images in `data/samples/litter/` and `data/samples/clean/` are **git-ignored** (they are real public photos, some may show people). This file is tracked and records where each one came from. The backend `CameraFeed` plays them as camera frames in the demo; never use non-park images for demos or tests.

Selection: all images come from the **public-test split** of the training data (`ml/data/yolo/lists/test_plastopol.txt` and `test_taco.txt`; not BePLi, which is non-commercial). Litter photos: 1-5 labelled boxes, picked by eye for real litter on grass, soil, sand and paths, with no faces, text, indoor or sea-only scenes; resized to a long side of at most 1280 px, JPEG q88. No public-test image has an empty label, so the clean photos are **crops** (960x720, from 576x432 to 768x576 regions, upscaled) of regions that overlap no labelled box (40 px margin). Licences and required attribution: `ml/DATA_LICENSES.md`. TACO images with unspecified licence should get legal review before any commercial use.

## Litter (`data/samples/litter/`)

| Path | Dataset | Original file | Licence | Attribution | Shows |
|---|---|---|---|---|---|
| litter/01.jpg | TACO | 000016.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | white tissue on wood-chip mulch (1 box) |
| litter/02.jpg | PlastOPol | 329920.jpg | CC BY 4.0 | Cordova et al. 2022, Zenodo 10.5281/zenodo.5829156 (Marine Debris Tracker images); converted to YOLO | brown bottle on asphalt path (1 box) |
| litter/03.jpg | PlastOPol | 264040.jpg | CC BY 4.0 | Cordova et al. 2022, Zenodo 10.5281/zenodo.5829156 (Marine Debris Tracker images); converted to YOLO | crumpled wrapper in grass (1 box) |
| litter/04.jpg | TACO | 000089.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | small wrapper on sand (1 box) |
| litter/05.jpg | TACO | batch_8/000036.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | bottles and wrappers in a dirt trench (5 boxes) |
| litter/06.jpg | PlastOPol | 135682.jpg | CC BY 4.0 | Cordova et al. 2022, Zenodo 10.5281/zenodo.5829156 (Marine Debris Tracker images); converted to YOLO | rusty cans and a tray in grass (3 boxes) |
| litter/07.jpg | PlastOPol | 323884.jpg | CC BY 4.0 | Cordova et al. 2022, Zenodo 10.5281/zenodo.5829156 (Marine Debris Tracker images); converted to YOLO | white tissue on mulch (1 box) |
| litter/08.jpg | TACO | 000042.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | small bottle cap or wrapper on a dark path edge (1 box) |
| litter/09.jpg | TACO | batch_14/000047.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | crushed cans in a paved gutter (4 boxes) |
| litter/10.jpg | TACO | batch_6/000046.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | bottle and small items on cobbled path (5 boxes) |
| litter/11.jpg | TACO | 000023.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | plastic bottle in grass (2 boxes) |
| litter/12.jpg | TACO | 000100.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | two plastic bottles on dry ground (2 boxes) |
| litter/13.jpg | TACO | 000061.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | yellow wrapper on asphalt (1 box) |
| litter/14.jpg | TACO | batch_4/000052.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | paper cup on sand (1 box) |
| litter/15.jpg | TACO | batch_13/000045.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | beer cans and a cup among rocks and dry leaves (4 boxes) |

## Clean (`data/samples/clean/`)

| Path | Dataset | Original file | Licence | Attribution | Shows |
|---|---|---|---|---|---|
| clean/01.jpg | TACO | crop of batch_2/000099.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | grass, no litter |
| clean/02.jpg | TACO | crop of batch_4/000081.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | sand, no litter |
| clean/03.jpg | PlastOPol | crop of 325074.jpg | CC BY 4.0 | Cordova et al. 2022, Zenodo 10.5281/zenodo.5829156 (Marine Debris Tracker images); converted to YOLO | concrete path, no litter |
| clean/04.jpg | TACO | crop of batch_6/000046.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | cobbled path, no litter |
| clean/05.jpg | PlastOPol | crop of 264040.jpg | CC BY 4.0 | Cordova et al. 2022, Zenodo 10.5281/zenodo.5829156 (Marine Debris Tracker images); converted to YOLO | grass and soil, no litter |
| clean/06.jpg | PlastOPol | crop of 325527.jpg | CC BY 4.0 | Cordova et al. 2022, Zenodo 10.5281/zenodo.5829156 (Marine Debris Tracker images); converted to YOLO | mulch and soil, no litter |
| clean/07.jpg | TACO | crop of batch_2/000064.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | grass, no litter |
| clean/08.jpg | TACO | crop of 000088.jpg | CC BY 4.0 release; per-image ODbL / CC / unspecified | Proenca and Simoes 2020 (arXiv:2003.06975), github.com/pedropro/TACO, Zenodo 10.5281/zenodo.3587843; OpenLitterMap and contributors | sand, no litter |
