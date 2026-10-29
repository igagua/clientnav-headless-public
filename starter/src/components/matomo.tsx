"use client";

import { matomo, type MatomoTracker } from "@clientnav/headless/matomo";
import { usePathname, useSearchParams } from "next/navigation";
import { useEffect, useRef } from "react";

/**
 * Visits counted in our Matomo (MATOMO_URL and MATOMO_SITE_ID in .env): the first page, then every page the reader
 * moves to without a reload. Counted only on the site's own address (SITE_URL, www and bare), decided here in the
 * browser: the server behind the proxy cannot tell (a regional site, 2026-10-19). No cookies; "do not track" honoured.
 */
export function Matomo({ url, siteId, site }: { url: string; siteId: string; site: string }) {
  const path = usePathname();
  const search = useSearchParams();
  const tracker = useRef<MatomoTracker | null>(null);
  useEffect(() => {
    tracker.current ??= matomo({ url, siteId, site });
    // After a client-side move the new title comes a moment later: counted once it is there.
    void tracker.current.pageWhenReady();
  }, [path, search, url, siteId, site]);
  return null;
}
