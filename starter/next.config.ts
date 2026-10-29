import type { NextConfig } from "next";
import { withCacheLimit } from "@clientnav/headless/next";

const cms = (process.env.CMS_URL ?? "").replace(/\/+$/, "");

const nextConfig: NextConfig = {
  // Addresses end in a slash, as WordPress's do.
  trailingSlash: true,
  poweredByHeader: false,
  headers: async () => [
    // Pages may be kept at the edge (Cloudflare) for five minutes and served stale for a day while they refresh, so a
    // crawler reading thousands of old pages does not reach the CMS for each (a regional news site, 2026-10-16: without this
    // every page answered "private, no-store" and the CMS ran at two CPUs). A publish refreshes this front end at once;
    // the edge follows within five minutes, or at once when Client Navigator Performance purges it. The previews, the
    // refresh call, the forms and the rest of /api stay out of every cache.
    { source: "/((?!preview/|live-preview/|api/|_next/|wp-admin|wp-login).*)", headers: [{ key: "Cache-Control", value: "public, max-age=0, s-maxage=300, stale-while-revalidate=86400" }] },
    // The previews may be shown inside the editor on the CMS (split view); nowhere else.
    ...["/preview/:path*", "/live-preview/:path*"].map((source) => ({ source, headers: [{ key: "Content-Security-Policy", value: `frame-ancestors 'self'${cms ? ` ${cms}` : ""}` }] })),
  ],
  rewrites: async () => [
    // PR Register's sitemaps and llms.txt under their own names (the routes live under /api/ beside the catch-all page).
    { source: "/sitemap_index.xml", destination: "/api/sitemap/sitemap_index.xml" },
    { source: "/:file(.*-sitemap[0-9]*\\.xml)", destination: "/api/sitemap/:file" },
    { source: "/llms.txt", destination: "/api/llms" },
    // Pictures and files of the CMS, as paths on this site, served through it.
    ...(cms ? [{ source: "/wp-content/:path*", destination: `${cms}/wp-content/:path*` }] : []),
  ],
};

// Rendered pages and the door's answers are kept in memory only, 64 MB at most, never written to .next/ on disk:
// a crawler reading every address would otherwise fill the disk (server 36, 2026-10-17).
export default withCacheLimit(nextConfig);
