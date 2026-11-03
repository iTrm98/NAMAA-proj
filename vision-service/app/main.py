from fastapi import FastAPI

app = FastAPI(title="NAMAA AI Service")


@app.get("/health")
def health():
    return {"status": "ok"}


# To build (see docs/api.md):
#   POST /ndvi    compute NDVI for a park polygon and date range, return the values
#   POST /waste   receive an image, return the detected waste boxes and count
