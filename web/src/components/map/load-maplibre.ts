'use client';

/** Worker MapLibre disalin ke public/ oleh scripts/copy-maplibre-worker.mjs (postinstall). */
const WORKER_URL = '/maplibre/maplibre-gl-worker.mjs';

/** Impor maplibre-gl hanya di browser dan arahkan worker-nya ke URL tetap (bundler tidak bisa menebak lokasinya). */
export async function loadMaplibre() {
  const ml = await import('maplibre-gl');
  ml.setWorkerUrl(WORKER_URL);
  return ml;
}
