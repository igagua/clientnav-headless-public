/**
 * Settings for next.config: the page cache in memory only, with a size cap.
 *
 * Next.js writes every page it renders for a route with `revalidate` (and every fetch answer) to .next/ on disk and
 * never removes one. A crawler reading a news site's archive filled server 36 that way (a site: 72 GB in two
 * days inside the container, 2026-10-17). With these settings Next keeps them only in its own memory store, which
 * drops the least used entries past `memoryMB`; pages built at `next build` are still read from disk. Cloudflare keeps
 * pages at the edge in front of this (s-maxage), so the memory store only has to hold what is asked for now.
 * Turning off the disk store also turns off next/image's disk store: a site that optimises pictures with next/image
 * needs a bounded store of its own before using this.
 *
 *   export default withCacheLimit({ ...nextConfig });            // 64 MB
 *   export default withCacheLimit(nextConfig, { memoryMB: 128 });
 */
export function withCacheLimit(config = {}, { memoryMB = 64 } = {}) {
  return {
    ...config,
    cacheMaxMemorySize: Math.max(1, Math.round(memoryMB)) * 1024 * 1024,
    experimental: { ...(config.experimental ?? {}), isrFlushToDisk: false },
  };
}
