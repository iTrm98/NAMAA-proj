import { setWorkerUrl } from 'maplibre-gl'
// Bundled by Vite as its own worker chunk (with maplibre's shared module).
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url'

/**
 * maplibre-gl 6 looks for its worker next to its own module
 * (`new URL('./maplibre-gl-worker.mjs', import.meta.url)`). Vite bundles the
 * library into another file, so that URL does not exist and every GeoJSON
 * source (the park outlines, the NDVI colours) fails with "Worker failed to
 * load"; only the HTML markers showed. Point maplibre at the bundled worker
 * before the first map is created.
 */
setWorkerUrl(workerUrl)
