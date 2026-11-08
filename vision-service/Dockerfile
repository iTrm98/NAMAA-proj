# AI: Python 3.12 + FastAPI.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /srv

# rasterio's wheel bundles GDAL but links the system libexpat (absent from -slim).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libexpat1 \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# The code is mounted as a volume in docker-compose for development.
EXPOSE 8001
