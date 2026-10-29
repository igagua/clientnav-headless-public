/**
 * The routes a front end needs for the CMS, as plain Request -> Response handlers (Next.js route handlers,
 * Astro endpoints, Hono, a worker...). Next.js:
 *
 *   // app/api/preview/route.ts
 *   import { previewHandler } from "@clientnav/headless/routes";
 *   export const dynamic = "force-dynamic";
 *   export const GET = previewHandler({ secret: process.env.REVALIDATE_SECRET });
 *
 *   // app/api/revalidate/route.ts
 *   import { revalidatePath, revalidateTag } from "next/cache";
 *   export const POST = revalidateHandler({ secret: process.env.REVALIDATE_SECRET, refresh: ({ paths, all }) => {
 *     revalidateTag("wp", { expire: 0 });
 *     all ? revalidatePath("/", "layout") : paths.forEach((p) => revalidatePath(p));
 *   } });
 */
import { PREVIEW_COOKIE, sameSecret, verifyPreviewToken } from "./token.mjs";

const NO_STORE = { "cache-control": "no-store", "x-robots-tag": "noindex" };
const text = (body, status) => new Response(body, { status, headers: { "content-type": "text/plain; charset=utf-8", ...NO_STORE } });
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json", ...NO_STORE } });

function cookie(name, value, { path, maxAge, secure, sameSite }) {
  return `${name}=${value}; Path=${path}; Max-Age=${maxAge}; HttpOnly; SameSite=${sameSite}${secure ? "; Secure" : ""}`;
}

/**
 * Where the CMS's Preview button leads: /api/preview/?token=... Checks the signed token and sends the browser
 * to the draft's page with the token kept in a cookie (cn_preview, the name Client Navigator Performance never
 * caches) for that page only. `page(id)` names the draft's page (default /preview/<id>/).
 * sameSite "Lax" serves a CMS on the same site (cms.example.com for www.example.com), also inside the editor's
 * split view; a CMS on another site needs "None". `secure` marks the cookie for https only; by default when the
 * request came by https (behind a proxy that hands on plain http, say so: secure: true).
 * The browser is sent on with a relative address, so a proxy in front never shows its inner name.
 */
export function previewHandler({ secret, page = (id) => `/preview/${id}/`, cookiePath = "/preview", sameSite = "Lax", secure } = {}) {
  return async function GET(request) {
    const url = new URL(request.url);
    const claims = verifyPreviewToken(url.searchParams.get("token"), "preview", secret);
    if (!claims) return text("This preview link is not valid or has run out. Click Preview in WordPress again.", 401);
    const token = url.searchParams.get("token");
    const maxAge = Math.max(1, Math.floor(claims.exp - Date.now() / 1000));
    const https = secure ?? (url.protocol === "https:" || sameSite === "None");
    return new Response(null, {
      status: 307,
      headers: { location: page(claims.pid), "set-cookie": cookie(PREVIEW_COOKIE, token, { path: cookiePath, maxAge, secure: https, sameSite }), ...NO_STORE },
    });
  };
}

/** The token kept by previewHandler, from a request's cookies, if it is still valid for that entry. */
export function previewToken(cookieHeader, id, secret) {
  const found = String(cookieHeader ?? "")
    .split(/;\s*/)
    .find((c) => c.startsWith(`${PREVIEW_COOKIE}=`));
  const token = found ? decodeURIComponent(found.slice(PREVIEW_COOKIE.length + 1)) : "";
  const claims = verifyPreviewToken(token, "preview", secret);
  return claims && (id === undefined || claims.pid === Number(id)) ? token : null;
}

/** Leaves the preview: the cookie goes, the browser goes to `to`. */
export function exitPreviewHandler({ to = "/", cookiePath = "/preview", sameSite = "Lax", secure } = {}) {
  return async function GET(request) {
    const url = new URL(request.url);
    return new Response(null, {
      status: 307,
      headers: { location: to, "set-cookie": cookie(PREVIEW_COOKIE, "", { path: cookiePath, maxAge: 0, secure: secure ?? url.protocol === "https:", sameSite }), ...NO_STORE },
    });
  };
}

/**
 * POST /api/revalidate/ from the CMS when something is published or changed, with the shared secret
 * (Authorization: Bearer, or X-Revalidate-Secret). Body: { paths: [...], path, all, gone: [ids] }.
 * `refresh({ paths, all, gone })` does the site's own refreshing; paths end in "/" and "/" is always among them.
 */
export function revalidateHandler({ secret, refresh, max = 100 } = {}) {
  return async function POST(request) {
    if (!secret || String(secret).length < 16) return json({ ok: false, error: "not configured" }, 503);
    const given = (request.headers.get("authorization") ?? "").replace(/^Bearer\s+/i, "") || request.headers.get("x-revalidate-secret") || "";
    if (!sameSecret(given, secret)) return json({ ok: false, error: "forbidden" }, 401);
    const body = await request.json().catch(() => ({}));
    const asked = [...(Array.isArray(body?.paths) ? body.paths : []), body?.path]
      .filter((p) => typeof p === "string" && p.startsWith("/") && !p.startsWith("//") && p.length < 500)
      .slice(0, max);
    const gone = (Array.isArray(body?.gone) ? body.gone : []).map(Number).filter((n) => Number.isInteger(n) && n > 0);
    const all = !!body?.all || asked.length === 0;
    const paths = all ? [] : [...new Set([...asked.map((p) => (p.endsWith("/") ? p : `${p}/`)), "/"])];
    await refresh?.({ paths, all, gone });
    return json({ ok: true, refreshed: all ? "everything" : paths, gone });
  };
}

/**
 * The RSS feed, passed on from the CMS (it writes the front end's addresses into it). GET /feed/ for the home
 * page; `category(request)` may name a section from the request (for /category/<slug>/feed/).
 */
export function feedHandler({ door, category = () => undefined, maxAge = 300 } = {}) {
  return async function GET(request) {
    const xml = await door.feed({ category: category(request) }).catch(() => undefined);
    if (xml === undefined) return text("The feed cannot be read just now.", 503);
    if (xml === null) return text("No such feed.", 404);
    return new Response(xml, { status: 200, headers: { "content-type": "application/rss+xml; charset=utf-8", "cache-control": `public, max-age=${maxAge}` } });
  };
}

/** The line a preview page carries so a click in the editor's split view selects the block (plugin 0.17.0). */
export const previewBridge = (cms) => `${String(cms).replace(/\/+$/, "").replace(/\/wp-json$/, "")}/wp-content/plugins/clientnav-headless/assets/preview-bridge.js`;
