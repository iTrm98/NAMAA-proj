# AI: Python 3.12 + FastAPI.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    # Ultralytics: keep its settings inside the image, no telemetry or update checks.
    YOLO_CONFIG_DIR=/opt/namaa/ultralytics \
    YOLO_OFFLINE=1

WORKDIR /srv

# rasterio's wheel bundles GDAL but links the system libexpat (absent from -slim).
# libglib2.0 is needed by the headless OpenCV build that Ultralytics uses.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libexpat1 libglib2.0-0 curl \
    && rm -rf /var/lib/apt/lists/*

# CPU-only PyTorch first (the default wheels bundle CUDA: several GB).
RUN pip install --no-cache-dir torch==2.14.1 torchvision==0.29.1 --index-url https://download.pytorch.org/whl/cpu

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt \
    && OPENCV=$(pip show opencv-python | sed -n 's/^Version: //p') \
    && pip uninstall -y opencv-python \
    && pip install --no-cache-dir "opencv-python-headless==${OPENCV}"

# YOLO weights at build time, never at request time. vision-service/ is mounted
# at /srv, so the weights live outside it. A custom models/litter.pt (mounted)
# is used instead when it exists (see app/waste.py).
RUN mkdir -p /opt/namaa/weights \
    && curl -fsSL -o /opt/namaa/weights/yolo26n.pt \
       https://github.com/ultralytics/assets/releases/download/v8.4.0/yolo26n.pt

# The code is mounted as a volume in docker-compose for development.
EXPOSE 8001
