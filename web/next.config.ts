import type { NextConfig } from "next";

/** URL katalog lama (/?month=…) kini menjadi halaman hasil pencarian /paket. Query ikut diteruskan. */
const LEGACY_CATALOG_KEYS = ["q", "month", "airline", "departure_city", "price_max", "sort", "cursor"];

/** Origin API Laravel. Browser memanggil /api/v1 dan /sanctum di domain web (same-origin) agar cookie sesi Sanctum berlaku. */
const API_ORIGIN = new URL(process.env.API_BASE_URL ?? "http://127.0.0.1:8000/api/v1").origin;

const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  poweredByHeader: false,
  /** NFR-10 / OWASP A05: header keamanan halaman web. CSP dibatasi pada direktif yang tidak mengganggu skrip Next. */
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "X-Frame-Options", value: "DENY" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
          { key: "Content-Security-Policy", value: "frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'" },
          ...(process.env.NODE_ENV === "production"
            ? [{ key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" }]
            : []),
        ],
      },
    ];
  },
  async rewrites() {
    return [
      { source: "/api/v1/:path*", destination: `${API_ORIGIN}/api/v1/:path*` },
      { source: "/sanctum/csrf-cookie", destination: `${API_ORIGIN}/sanctum/csrf-cookie` },
    ];
  },
  async redirects() {
    return LEGACY_CATALOG_KEYS.map((key) => ({
      source: "/",
      has: [{ type: "query" as const, key }],
      destination: "/paket",
      permanent: false,
    }));
  },
};

export default nextConfig;
