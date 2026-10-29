/**
 * Matomo for a headless front end, without a framework: every headless site counts its visits in our Matomo
 * (stats.example.com) the same way.
 *
 * Why in the kit (2026-10-19): a large news site, a regional site and a regional news site sent no visits at all after going headless
 * (no tracker), and a regional site's tracker never fired because it was switched on by the request's host on the
 * server, which behind the reverse proxy is 127.0.0.1. Here the decision is made in the browser, by the address the
 * visitor actually opened.
 *
 *   import { matomo } from "@clientnav/headless/matomo";
 *   const m = matomo({ url: "https://stats.example.com", siteId: "11", site: "https://www.example.com" });
 *   m.page();            // on the first page and after every client-side navigation
 *
 * No cookies are set and browsers asking not to be tracked are not counted, unless a site turns that off. Only the
 * site's own address is counted (its www and bare forms both), never a beta or preview address.
 */

/** The host names a site counts on: the one in its address, with and without "www.". */
export function countedHosts(site) {
  let host = "";
  try {
    host = new URL(String(site)).hostname.toLowerCase();
  } catch {
    return [];
  }
  const bare = host.replace(/^www\./, "");
  return [...new Set([host, bare, `www.${bare}`])];
}

/** Whether a page at this host is counted for this site. */
export function counts(site, host) {
  return countedHosts(site).includes(String(host || "").toLowerCase());
}

/**
 * The page's title once the navigation has set it: after a client-side move the title is often still empty or the
 * previous page's for a moment (seen with Next 16). Resolves when the title is set and differs from "before", or
 * after "wait" ms with whatever it is then.
 */
export function newTitle(before, { doc = globalThis.document, wait = 1500 } = {}) {
  const ready = () => !!doc.title && doc.title !== before;
  if (!doc || ready()) return Promise.resolve(doc ? doc.title : "");
  return new Promise((resolve) => {
    let done = false;
    const finish = () => {
      if (done) return;
      done = true;
      clearInterval(poll);
      clearTimeout(stop);
      resolve(doc.title);
    };
    const poll = setInterval(() => ready() && finish(), 50);
    const stop = setTimeout(finish, wait);
  });
}

/**
 * A tracker. Nothing happens on a host the site does not count, or without a Matomo address and site number.
 * win and doc are for tests; in a browser they are window and document.
 */
export function matomo({ url, siteId, site, cookies = false, doNotTrack = true, win = globalThis.window, doc = globalThis.document } = {}) {
  const base = String(url || "").replace(/\/+$/, "");
  const on = !!(base && siteId && win && doc && counts(site, win.location?.hostname));
  let started = false;
  let previous = null;
  let lastTitle = null;

  function start() {
    const paq = (win._paq = win._paq || []);
    if (!cookies) paq.push(["disableCookies"]);
    if (doNotTrack) paq.push(["setDoNotTrack", true]);
    paq.push(["setTrackerUrl", `${base}/matomo.php`]);
    paq.push(["setSiteId", String(siteId)]);
    paq.push(["enableLinkTracking"]);
    const s = doc.createElement("script");
    s.async = true;
    s.src = `${base}/matomo.js`;
    doc.head.appendChild(s);
    started = true;
  }

  return {
    /** Whether visits are counted here. */
    on,
    /** Counts the page now shown. title defaults to the document's; call it after the new page's title is set. */
    page(title) {
      if (!on) return false;
      const paq = (win._paq = win._paq || []);
      const href = win.location.href;
      // The same address twice in a row is one visit to it (React runs effects twice in development).
      if (started && href === previous) return false;
      if (!started) start();
      else if (previous) paq.push(["setReferrerUrl", previous]);
      paq.push(["setCustomUrl", href]);
      lastTitle = title ?? doc.title;
      paq.push(["setDocumentTitle", lastTitle]);
      paq.push(["trackPageView"]);
      previous = href;
      return true;
    },
    /** Counts the page once its own title is set (after a client-side move); resolves with whether it counted. */
    async pageWhenReady() {
      if (!on || (started && win.location.href === previous)) return false;
      return this.page(lastTitle === null ? doc.title : await newTitle(lastTitle, { doc }));
    },
    /** A site search, counted as Matomo's site search instead of a page view. */
    search(keyword, count) {
      if (!on) return false;
      if (!started) start();
      win._paq.push(["trackSiteSearch", String(keyword), false, typeof count === "number" ? count : false]);
      return true;
    },
  };
}
