/**
 * The content door of Client Navigator Headless (/wp-json/clientnav-headless/v1 on the CMS), for any front end.
 *
 *   import { createDoor } from "@clientnav/headless/door";
 *   const door = createDoor({ cms: "https://cms.example.com", key: process.env.REVALIDATE_SECRET,
 *                             next: { revalidate: 300, tags: ["wp"] } });
 *   const page = await door.resolve("/about-us/", { with: "meta,sizes" });   // null when nothing lives there
 *
 * Every request carries the shared secret (Authorization: Bearer), so the CMS can close its door to everyone
 * else (plugin 0.16.0 and later, Settings > Headless > Door). "Not found" answers null; anything else that is not
 * an answer throws DoorDown, so a page can show "try again" instead of an empty page.
 * `next` is read by Next.js's fetch and ignored everywhere else.
 */
export class DoorDown extends Error {}

export const NS = "/wp-json/clientnav-headless/v1";

export function createDoor({ cms, key = "", fetch: doFetch = globalThis.fetch, next, timeout = 20000 } = {}) {
  if (!cms) throw new Error("createDoor: name the CMS address (cms)");
  const base = String(cms).replace(/\/+$/, "").replace(/\/wp-json$/, "") + NS;
  const headers = { accept: "application/json", ...(key ? { authorization: `Bearer ${key}` } : {}) };

  function url(route, query = {}) {
    const qs = Object.entries(query)
      .filter(([, v]) => v !== undefined && v !== null && v !== "")
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(Array.isArray(v) ? v.join(",") : String(v))}`)
      .sort()
      .join("&");
    return `${base}/${String(route).replace(/^\/+/, "")}${qs ? `?${qs}` : ""}`;
  }

  async function get(route, query = {}, { fresh = false, refusedIsNone = false } = {}) {
    let res;
    try {
      // A redirect is never followed: the door answers in place, and a CMS that redirects a door call (to the
      // front end, say) would otherwise send this fetch round in a loop between the two sites.
      res = await doFetch(url(route, query), {
        headers,
        redirect: "manual",
        signal: AbortSignal.timeout(timeout),
        ...(fresh ? { cache: "no-store" } : next ? { next } : {}),
      });
    } catch (e) {
      throw new DoorDown(`The CMS did not answer: ${route} (${e instanceof Error ? e.message : e})`);
    }
    if (res.status >= 300 && res.status < 400) throw new DoorDown(`The CMS redirected ${route} (${res.status}) instead of answering: is the CMS address right?`);
    if (res.status === 404 || (refusedIsNone && (res.status === 401 || res.status === 403))) return null;
    if (res.status === 401 || res.status === 403) throw new DoorDown(`The CMS refused ${route} (${res.status}): is this front end paired, and does it send its key?`);
    if (!res.ok) throw new DoorDown(`The CMS answered ${res.status}: ${route}`);
    try {
      return await res.json();
    } catch {
      throw new DoorDown(`The CMS sent something that is not JSON: ${route}`);
    }
  }

  /** An RSS document as text; null when the CMS has none. Never follows a redirect, like every door call. */
  async function rss(route, query = {}) {
    let res;
    try {
      res = await doFetch(url(route, query), { headers: { ...headers, accept: "application/rss+xml" }, redirect: "manual", signal: AbortSignal.timeout(timeout), ...(next ? { next } : {}) });
    } catch (e) {
      throw new DoorDown(`The CMS did not answer: ${route} (${e instanceof Error ? e.message : e})`);
    }
    if (res.status >= 300 && res.status < 400) throw new DoorDown(`The CMS redirected ${route} (${res.status}) instead of answering`);
    if (res.status === 404) return null;
    if (!res.ok) throw new DoorDown(`The CMS answered ${res.status}: ${route}`);
    return res.text();
  }

  return {
    url,
    get,
    /** Name, address, menus, home page and what the plugin offers. */
    site: (q = {}) => get("site", q),
    /** One address: an entry, a term, an author, a list. Also { id }, { old } (an old address), lang, with. */
    resolve: (path, q = {}) => get("resolve", { path, ...q }),
    /** A list: type, category ("a,b"), not_category, tag, tax + term, by (author), search, orderby, page, per_page, offset, count, full, lang. */
    posts: (q = {}) => get("posts", q),
    terms: (q = {}) => get("terms", q),
    authors: (q = {}) => get("authors", q),
    /** Addresses for a sitemap: type, page, per_page, with=terms,authors,archives. */
    paths: (q = {}) => get("paths", q),
    months: (q = {}) => get("months", q),
    /** "2025-03" */
    month: (month, q = {}) => get("month", { month, ...q }),
    /** "2025-03-10" */
    day: (day, q = {}) => get("day", { day, ...q }),
    /** The search feed, in id order: { after, limit (up to 500) }. Never kept. */
    index: (q = {}) => get("index", q, { fresh: true }),
    /** A page made of groups of cards the site describes (a news home page, a side column): { name, groups }. Null for a name the site does not have; without a name, { layouts: [names] }. Plugin 0.23.0. */
    layout: (name, q = {}) => get("layout", { name, ...q }),
    /** The theme builder's header or footer, designed in Elementor. */
    part: (where, q = {}) => get("part", { where, ...q }),
    /** A Fluent Forms form by number, or one of the plugin's own forms by name. */
    form: (idOrName, q = {}) => get(`forms/${encodeURIComponent(String(idOrName))}`, q),
    /** A draft as its editor left it, with the signed token from the Preview button. Never kept. Null when the token is no longer accepted or the entry is gone. */
    preview: (token, q = {}) => get("preview", { token, ...q }, { fresh: true, refusedIsNone: true }),
    /**
     * Unsaved blocks drawn as the entry they belong to, for the editor's live window (POST /live, plugin 0.23.0):
     * { title, content } as the editor holds them, with the window's signed "live" token. Never kept. Null when the
     * token is no longer accepted.
     */
    live: async (token, { title = "", content = "" } = {}) => {
      let res;
      try {
        res = await doFetch(url("live"), {
          method: "POST",
          headers: { ...headers, "content-type": "application/json" },
          body: JSON.stringify({ token, title, content }),
          redirect: "manual",
          cache: "no-store",
          signal: AbortSignal.timeout(timeout),
        });
      } catch (e) {
        throw new DoorDown(`The CMS did not answer: live (${e instanceof Error ? e.message : e})`);
      }
      if (res.status === 401 || res.status === 403 || res.status === 404) return null;
      if (!res.ok) throw new DoorDown(`The CMS answered ${res.status}: live`);
      return res.json();
    },
    /** The RSS feed as text, with the front end's addresses (home page, or one section with { category }); null when there is none. */
    feed: (q = {}) => rss("feed", q),
    /**
     * A podcast show and its episodes (Client Navigator Podcast, plugin 0.25.0): { show, items: [card + episode],
     * total, pages, page, more }. show is null when the site has no podcast; null for a show it does not have.
     * { shows: true } lists the shows instead.
     */
    podcast: ({ show, page, perPage, shows, ...q } = {}) => get("episodes", { show, page, per_page: perPage, shows: shows ? 1 : undefined, ...q }),
    /** A show's podcast feed as text, ready to send as application/rss+xml at the show's feed address; null when there is none. */
    podcastFeed: ({ show } = {}) => rss("episodes/feed", { show }),
  };
}
