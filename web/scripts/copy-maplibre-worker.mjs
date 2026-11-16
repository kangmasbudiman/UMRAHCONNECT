// Salin worker MapLibre ke public/ agar bisa dimuat dari URL tetap (bundler tidak bisa menebak lokasinya).
// Dijalankan otomatis setelah `npm install` (postinstall) supaya versinya selalu sama dengan paket terpasang.
import { copyFileSync, mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';

const require = createRequire(import.meta.url);
const dist = dirname(require.resolve('maplibre-gl/dist/maplibre-gl.mjs'));
const out = join(process.cwd(), 'public', 'maplibre');
mkdirSync(out, { recursive: true });
copyFileSync(join(dist, 'maplibre-gl-worker.mjs'), join(out, 'maplibre-gl-worker.mjs'));
console.log('maplibre worker → public/maplibre/maplibre-gl-worker.mjs');
