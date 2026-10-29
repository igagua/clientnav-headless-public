// @clientnav/headless, the front-end kit:  npm test
import { test } from "node:test";
import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, writeFileSync, existsSync, statSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { seoOf, createDoor, DoorDown, exitPreviewHandler, feedHandler, metadata, jsonLd, previewBridge, previewHandler, previewToken, revalidateHandler, signPreviewToken, verifyPreviewToken } from "../packages/headless/src/index.mjs";
import { pair } from "../packages/headless/bin/pair.mjs";
import { withCacheLimit } from "../packages/headless/src/next.mjs";

const SECRET = "s".repeat(44);
const answer = (status, body, type = "application/json") => new Response(typeof body === "string" ? body : JSON.stringify(body), { status, headers: { "content-type": type } });
function fakeFetch(reply) {
  const calls = [];
  const f = async (url, init = {}) => {
    calls.push({ url: String(url), init });
    return reply(String(url), init);
  };
  f.calls = calls;
  return f;
}

test("door: addresses, the key on every request, Next's cache options", async () => {
  const f = fakeFetch(() => answer(200, { ok: 1 }));
  const door = createDoor({ cms: "https://cms.example.test/wp-json/", key: SECRET, fetch: f, next: { revalidate: 300, tags: ["wp"] } });
  await door.posts({ category: ["news", "world"], per_page: 5, search: "", page: undefined });
  await door.resolve("/about-us/", { with: "meta,sizes" });
  assert.equal(f.calls[0].url, "https://cms.example.test/wp-json/clientnav-headless/v1/posts?category=news%2Cworld&per_page=5");
  assert.equal(f.calls[1].url, "https://cms.example.test/wp-json/clientnav-headless/v1/resolve?path=%2Fabout-us%2F&with=meta%2Csizes");
  assert.equal(f.calls[0].init.headers.authorization, `Bearer ${SECRET}`);
  assert.deepEqual(f.calls[0].init.next, { revalidate: 300, tags: ["wp"] });
});

test("door: previews and the search feed are never kept", async () => {
  const f = fakeFetch(() => answer(200, {}));
  const door = createDoor({ cms: "https://cms.example.test", fetch: f, next: { revalidate: 300 } });
  await door.preview("t.k");
  await door.index({ after: 10 });
  for (const c of f.calls) {
    assert.equal(c.init.cache, "no-store");
    assert.equal(c.init.next, undefined);
    assert.equal(c.init.headers.authorization, undefined);
  }
});

test("door: not found is null, a refusal and a failure throw DoorDown", async () => {
  const codes = [404, 401, 500];
  const door = createDoor({ cms: "https://cms.example.test", fetch: fakeFetch(() => answer(codes.shift(), {})) });
  assert.equal(await door.resolve("/nothing/"), null);
  await assert.rejects(door.site(), (e) => e instanceof DoorDown && /paired/.test(e.message));
  await assert.rejects(door.site(), DoorDown);
  const down = createDoor({ cms: "https://cms.example.test", fetch: async () => { throw new Error("ECONNREFUSED"); } });
  await assert.rejects(down.posts(), /did not answer/);
});

test("door: a preview token no longer accepted is no preview, not a fault", async () => {
  const codes = [401, 403, 404, 500];
  const door = createDoor({ cms: "https://cms.example.test", fetch: fakeFetch(() => answer(codes.shift(), {})) });
  for (let i = 0; i < 3; i++) assert.equal(await door.preview("t"), null);
  await assert.rejects(door.preview("t"), DoorDown);
});

test("door: live blocks go by POST with the key and the token in the body, never kept; a refused token is null", async () => {
  const codes = [200, 401, 500];
  const f = fakeFetch(() => answer(codes.shift(), { type: "entry", entry: { title: "T" } }));
  const door = createDoor({ cms: "https://cms.example.test", key: SECRET, fetch: f, next: { revalidate: 300 } });
  assert.deepEqual(await door.live("t.k", { title: "T", content: "<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->" }), { type: "entry", entry: { title: "T" } });
  const c = f.calls[0];
  assert.equal(c.url, "https://cms.example.test/wp-json/clientnav-headless/v1/live");
  assert.equal(c.init.method, "POST");
  assert.equal(c.init.cache, "no-store");
  assert.equal(c.init.next, undefined);
  assert.equal(c.init.headers.authorization, `Bearer ${SECRET}`);
  assert.deepEqual(JSON.parse(c.init.body), { token: "t.k", title: "T", content: "<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->" });
  assert.equal(await door.live("t", { content: "" }), null);
  await assert.rejects(door.live("t", { content: "" }), DoorDown);
});

test("door: a page make-up by name; an unknown name is null", async () => {
  const codes = [200, 404];
  const f = fakeFetch(() => answer(codes.shift(), { name: "home", groups: { lead: null, news: [] } }));
  const door = createDoor({ cms: "https://cms.example.test", key: SECRET, fetch: f, next: { revalidate: 300 } });
  assert.deepEqual(await door.layout("home", { lang: "ru" }), { name: "home", groups: { lead: null, news: [] } });
  assert.equal(f.calls[0].url, "https://cms.example.test/wp-json/clientnav-headless/v1/layout?lang=ru&name=home");
  assert.deepEqual(f.calls[0].init.next, { revalidate: 300 });
  assert.equal(await door.layout("nope"), null);
});

test("door: the feed comes back as text", async () => {
  const door = createDoor({ cms: "https://cms.example.test", fetch: fakeFetch((u) => answer(u.includes("category=gone") ? 404 : 200, "<rss/>", "application/rss+xml")) });
  assert.equal(await door.feed(), "<rss/>");
  assert.equal(await door.feed({ category: "gone" }), null);
});

test("tokens: as the CMS signs them; scope, expiry and a short secret checked", async () => {
  const now = 1_800_000_000;
  const t = signPreviewToken({ pid: 7, uid: 1, scope: "preview", exp: now + 60 }, SECRET);
  assert.equal(verifyPreviewToken(t, "preview", SECRET, now).pid, 7);
  assert.equal(verifyPreviewToken(t, "live", SECRET, now), null);
  assert.equal(verifyPreviewToken(t, "preview", SECRET, now + 61), null);
  assert.equal(verifyPreviewToken(t, "preview", "x".repeat(44), now), null);
  assert.equal(verifyPreviewToken(t, "preview", "s".repeat(20), now), null);
  // The CMS's own: PHP's base64url of the JSON and of the raw HMAC (includes/preview.php).
  const php = "eyJwaWQiOjQyLCJ1aWQiOjMsInNjb3BlIjoicHJldmlldyIsImV4cCI6MTgwMDAwMDA2MH0";
  const mac = (await import("node:crypto")).createHmac("sha256", SECRET).update(php).digest("base64url");
  assert.equal(verifyPreviewToken(`${php}.${mac}`, "preview", SECRET, now).pid, 42);
});

test("preview route: a good token sets the cookie for the draft's page and goes there", async () => {
  const t = signPreviewToken({ pid: 12, uid: 1, scope: "preview", exp: Math.floor(Date.now() / 1000) + 600 }, SECRET);
  const res = await previewHandler({ secret: SECRET })(new Request(`https://www.example.test/api/preview/?token=${t}`));
  assert.equal(res.status, 307);
  assert.equal(res.headers.get("location"), "/preview/12/");
  const c = res.headers.get("set-cookie");
  assert.match(c, /^cn_preview=[^;]+; Path=\/preview; Max-Age=\d+; HttpOnly; SameSite=Lax; Secure$/);
  assert.equal(res.headers.get("cache-control"), "no-store");
  assert.equal(previewToken(`a=1; ${c.split(";")[0]}`, 12, SECRET), t);
  assert.equal(previewToken(c.split(";")[0], 13, SECRET), null);
  const bad = await previewHandler({ secret: SECRET })(new Request("https://www.example.test/api/preview/?token=nope"));
  assert.equal(bad.status, 401);
  const behind = await previewHandler({ secret: SECRET, secure: true })(new Request(`http://localhost:3100/api/preview/?token=${t}`));
  assert.match(behind.headers.get("set-cookie"), /; Secure$/);
  assert.equal(behind.headers.get("location"), "/preview/12/");
  const out = await exitPreviewHandler()(new Request("https://www.example.test/api/exit-preview/"));
  assert.equal(out.headers.get("location"), "/");
  assert.match(out.headers.get("set-cookie"), /^cn_preview=; Path=\/preview; Max-Age=0/);
});

test("refresh route: secret, paths with their slash and the home page, everything, gone ids", async () => {
  const seen = [];
  const h = revalidateHandler({ secret: SECRET, refresh: (w) => seen.push(w) });
  const post = (body, auth = `Bearer ${SECRET}`) => h(new Request("https://www.example.test/api/revalidate/", { method: "POST", headers: { authorization: auth, "content-type": "application/json" }, body: JSON.stringify(body) }));
  assert.equal((await post({ paths: ["/a/"] }, "Bearer wrong")).status, 401);
  assert.equal(seen.length, 0);
  let r = await (await post({ paths: ["/a", "//evil.test/", "/b/"], path: "/c/", gone: [5, "x"] })).json();
  assert.deepEqual(seen[0], { paths: ["/a/", "/b/", "/c/", "/"], all: false, gone: [5] });
  assert.deepEqual(r.gone, [5]);
  await post({ all: true, paths: ["/a/"] });
  await post({});
  assert.deepEqual([seen[1].all, seen[2].all], [true, true]);
  assert.equal((await revalidateHandler({ secret: "" })(new Request("https://x.test/", { method: "POST" }))).status, 503);
});

test("feed route: passed on as RSS; 404 and 503 when there is none or no answer", async () => {
  const door = { feed: async ({ category }) => (category === "none" ? null : category === "down" ? Promise.reject(new DoorDown("x")) : "<rss/>") };
  const h = feedHandler({ door, category: (req) => new URL(req.url).searchParams.get("c") ?? undefined });
  const ok = await h(new Request("https://w.test/feed/"));
  assert.equal(ok.headers.get("content-type"), "application/rss+xml; charset=utf-8");
  assert.equal(await ok.text(), "<rss/>");
  assert.equal((await h(new Request("https://w.test/feed/?c=none"))).status, 404);
  assert.equal((await h(new Request("https://w.test/feed/?c=down"))).status, 503);
});

test("SEO: PR Register's head in this site's terms, the door's own words, structured data", () => {
  const prr = { source: "pr-register", title: "Cloud &amp; collaboration", description: "It&#8217;s here", canonical: "https://preview.example.test/cloud/", robots: "index, follow", og: { type: "article", title: "Cloud &amp; co", url: "https://preview.example.test/cloud/", image: { url: "https://preview.example.test/p.jpg", width: 1200, height: 630 } }, twitter: { card: "summary_large_image" }, schema: [{ "@type": "Article", name: "</script>" }] };
  const m = metadata(prr, { site: "https://www.example.test", cmsFront: "https://preview.example.test" });
  assert.deepEqual(m.title, { absolute: "Cloud & collaboration" });
  assert.equal(m.description, "It’s here");
  assert.equal(m.alternates.canonical, "https://www.example.test/cloud/");
  assert.equal(m.openGraph.type, "article");
  assert.equal(m.openGraph.images[0].url, "https://www.example.test/p.jpg");
  assert.ok(!jsonLd(prr).includes("</script>"));
  assert.deepEqual(metadata({ source: "door", title: "About &amp; us", description: "" }), { title: { absolute: "About & us" }, description: undefined });
  assert.deepEqual(metadata(undefined), {});
  assert.equal(metadata({ source: "pr-register", found: true, title: "T" }, { site: "https://www.example.test/", path: "/a/" }).alternates.canonical, "https://www.example.test/a/");
  assert.equal(metadata(prr, { site: "https://www.example.test", cmsFront: "https://preview.example.test", path: "/other/" }).alternates.canonical, "https://www.example.test/cloud/");
  assert.equal(seoOf({ type: "entry", entry: { seo: prr } }), prr);
  assert.equal(seoOf({ type: "term", term: { seo: prr } }), prr);
  assert.equal(seoOf({ type: "home", seo: prr }), prr);
  assert.equal(previewBridge("https://cms.example.test/wp-json"), "https://cms.example.test/wp-content/plugins/clientnav-headless/assets/preview-bridge.js");
});

test("pair: the secret goes into .env unseen, the file before is kept; a refused code changes nothing", async () => {
  const dir = mkdtempSync(join(tmpdir(), "cnh-pair-"));
  const env = join(dir, ".env");
  writeFileSync(env, "WP_API=https://cms.example.test/wp-json\nREVALIDATE_SECRET=old\n");
  const f = fakeFetch((u, init) => (JSON.parse(init.body).code === "GOOD" ? answer(200, { ok: true, secret: SECRET }) : answer(403, { ok: false })));
  await assert.rejects(pair({ cms: "https://cms.example.test", code: "BAD", envFile: env, fetch: f }), /did not accept/);
  assert.equal(existsSync(`${env}.before-pair`), false);
  const r = await pair({ cms: "https://cms.example.test/", code: " GOOD ", envFile: env, fetch: f });
  assert.equal(f.calls[1].url, "https://cms.example.test/wp-json/clientnav-headless/v1/pair");
  assert.equal(readFileSync(env, "utf8"), `WP_API=https://cms.example.test/wp-json\nREVALIDATE_SECRET=${SECRET}\n`);
  assert.equal(readFileSync(r.kept, "utf8"), "WP_API=https://cms.example.test/wp-json\nREVALIDATE_SECRET=old\n");
  const fresh = join(dir, "new.env");
  await pair({ cms: "https://cms.example.test", code: "GOOD", envFile: fresh, name: "CMS_SECRET", fetch: f });
  assert.equal(readFileSync(fresh, "utf8"), `CMS_SECRET=${SECRET}\n`);
  assert.equal(statSync(fresh).mode & 0o777, 0o600);
});

test("the copied kits load and keep their own words", async () => {
  const as = await import("../packages/headless/vendor/antispam-server.mjs");
  const prr = await import("../packages/headless/vendor/pr-register.mjs");
  assert.equal(typeof as.signedHeaders, "function");
  assert.equal(typeof prr.prRegister, "function");
  assert.equal(Object.keys(as.signedHeaders(SECRET, "1.2.3.4", "a=1")).length, 3);
});

test("parity: finds a difference, leaves out what the door does not serve, notes pages kept on WordPress", async () => {
  const { parity, report } = await import("../packages/headless/bin/parity.mjs");
  const B = "https://cms.example.test";
  const posts = [
    { id: 2, slug: "two", link: `${B}/two/`, title: { rendered: "Two &amp; more" }, date_gmt: "2026-01-02T00:00:00", modified_gmt: "2026-01-02T00:00:00", author: 1, featured_media: 0, categories: [5] },
    { id: 1, slug: "one", link: `${B}/one/`, title: { rendered: "One" }, date_gmt: "2026-01-01T00:00:00", modified_gmt: "2026-01-01T00:00:00", author: 1, featured_media: 0, categories: [5] },
  ];
  const cards = [
    { id: 2, path: "/two/", title: "Two & more", date: "2026-01-02T00:00:00Z", modified: "2026-01-02T00:00:00Z", by: { id: 1 }, terms: [{ id: 5, taxonomy: "category" }], image: null },
    { id: 1, path: "/one/", title: "One, changed", date: "2026-01-01T00:00:00Z", modified: "2026-01-01T00:00:00Z", by: { id: 1 }, terms: [{ id: 5, taxonomy: "category" }], image: null },
  ];
  const pages = [
    { id: 9, slug: "cart", link: `${B}/cart/`, title: { rendered: "Cart" }, date_gmt: "2026-01-01T00:00:00", modified_gmt: "2026-01-01T00:00:00", author: 1, featured_media: 0 },
    { id: 10, slug: "blog", link: `${B}/blog/`, title: { rendered: "Blog" }, date_gmt: "2026-01-01T00:00:00", modified_gmt: "2026-01-01T00:00:00", author: 1, featured_media: 0 },
  ];
  const pageCards = [
    { id: 9, path: `${B}/cart/`, title: "Cart", date: "2026-01-01T00:00:00Z", modified: "2026-01-01T00:00:00Z", by: { id: 1 }, terms: [], image: null },
    { id: 10, path: "/blog/", title: "Blog", date: "2026-01-01T00:00:00Z", modified: "2026-01-01T00:00:00Z", by: { id: 1 }, terms: [], image: null },
  ];
  const routes = {
    "/wp/v2/types": { post: { slug: "post", rest_base: "posts" }, page: { slug: "page", rest_base: "pages" }, elementor_library: { slug: "elementor_library", rest_base: "elementor_library" } },
    "/wp/v2/taxonomies": { category: { slug: "category", rest_base: "categories" } },
    "/wp/v2/posts": posts,
    "/wp/v2/pages": pages,
    "/wp/v2/categories": [{ id: 5, name: "News", slug: "news", link: `${B}/category/news/`, count: 2, parent: 0 }],
    "/clientnav-headless/v1/terms": { items: [{ id: 5, name: "News", path: "/category/news/", count: 2, parent: 0 }] },
  };
  const f = async (url) => {
    const u = new URL(url);
    const route = u.pathname.replace("/wp-json", "");
    const json = (body, headers = {}) => new Response(JSON.stringify(body), { status: 200, headers: { "content-type": "application/json", ...headers } });
    if (route === "/clientnav-headless/v1/posts") {
      const type = u.searchParams.get("type");
      if (type === "elementor_library") return new Response("{}", { status: 400 });
      const items = type === "page" ? pageCards : cards;
      return json({ items, total: items.length });
    }
    if (route === "/clientnav-headless/v1/resolve") return json(u.searchParams.get("path") === "/blog/" ? { type: "posts", title: "Blog", items: [] } : { type: "entry", entry: { title: "One", html: "<p>x</p>", excerpt: "x" } });
    if (route === "/wp/v2/posts" && u.searchParams.get("slug")) return json([{ title: { rendered: "One" }, content: { rendered: "<p>x</p>" }, excerpt: { rendered: "<p>x</p>" } }]);
    if (route === "/wp/v2/elementor_library") return json([]);
    return json(routes[route] ?? [], { "x-wp-total": String((routes[route] ?? []).length ?? 0) });
  };
  const r = await parity({ cms: B, fetch: f, pages: 1, singles: 5 });
  assert.deepEqual(r.differences.map((d) => d.what), ["title post:one"]);
  assert.deepEqual(r.skipped, ["elementor_library"]);
  assert.deepEqual(r.kept, ["page:cart"]);
  assert.match(report(r), /1 differences in 1 kinds[\s\S]*1 × title\n   title post:one: REST "One" \| door "One, changed"/);
});

test("door: a podcast show with its episodes, the list of shows, and its feed as text", async () => {
  const f = fakeFetch((u) => {
    if (u.includes("episodes/feed")) return answer(u.includes("show=gone") ? 404 : 200, "<rss>pod</rss>", "application/rss+xml");
    if (u.includes("show=gone")) return answer(404, { code: "clientnav_no_show" });
    return answer(200, u.includes("shows=1") ? { shows: [{ slug: "podcast" }] } : { show: { slug: "podcast" }, items: [], total: 0 });
  });
  const door = createDoor({ cms: "https://cms.example.test", key: SECRET, fetch: f, next: { revalidate: 300 } });
  assert.equal((await door.podcast({ show: "podcast", page: 2, perPage: 10 })).show.slug, "podcast");
  assert.equal(f.calls[0].url, "https://cms.example.test/wp-json/clientnav-headless/v1/episodes?page=2&per_page=10&show=podcast");
  assert.equal(f.calls[0].init.headers.authorization, `Bearer ${SECRET}`);
  assert.deepEqual(await door.podcast({ shows: true }), { shows: [{ slug: "podcast" }] });
  assert.equal(f.calls[1].url, "https://cms.example.test/wp-json/clientnav-headless/v1/episodes?shows=1");
  assert.equal(await door.podcast({ show: "gone" }), null);
  assert.equal(await door.podcastFeed({ show: "podcast" }), "<rss>pod</rss>");
  assert.equal(f.calls[3].init.headers.accept, "application/rss+xml");
  assert.equal(f.calls[3].init.redirect, "manual");
  assert.equal(await door.podcastFeed({ show: "gone" }), null);
});

test("next config: the page cache in memory only, capped; the site's own settings kept", () => {
  const c = withCacheLimit({ trailingSlash: true, experimental: { optimizeCss: true } });
  assert.equal(c.trailingSlash, true);
  assert.equal(c.cacheMaxMemorySize, 64 * 1024 * 1024);
  assert.deepEqual(c.experimental, { optimizeCss: true, isrFlushToDisk: false });
  assert.equal(withCacheLimit({}, { memoryMB: 128 }).cacheMaxMemorySize, 128 * 1024 * 1024);
  assert.equal(withCacheLimit().experimental.isrFlushToDisk, false);
});

test("matomo: counts only on the site's own address, decided in the browser", async () => {
  const { matomo, counts, countedHosts } = await import("../packages/headless/src/matomo.mjs");
  assert.deepEqual(countedHosts("https://www.example.com"), ["www.example.com", "example.com"]);
  assert.equal(counts("https://www.example.com/", "example.com"), true);
  assert.equal(counts("https://www.example.com", "beta.example.com"), false);
  assert.equal(counts("https://www.example.com", "127.0.0.1"), false);
  const fakeDom = (host, href) => {
    const added = [];
    return { added, win: { location: { hostname: host, href } }, doc: { title: "T", head: { appendChild: (s) => added.push(s) }, createElement: () => ({}) } };
  };
  const b = fakeDom("beta.example.com", "https://beta.example.com/");
  const off = matomo({ url: "https://stats.example.com/", siteId: "12", site: "https://www.example.com", win: b.win, doc: b.doc });
  assert.equal(off.on, false);
  assert.equal(off.page(), false);
  assert.equal(b.win._paq, undefined);
  assert.equal(b.added.length, 0);
  const w = fakeDom("www.example.com", "https://www.example.com/");
  const m = matomo({ url: "https://stats.example.com/", siteId: 12, site: "https://www.example.com", win: w.win, doc: w.doc });
  assert.equal(m.on, true);
  m.page();
  assert.equal(m.page(), false, "the same address straight after itself is not counted again");
  w.win.location.href = "https://www.example.com/politics/";
  m.page("Politics");
  const paq = w.win._paq.map((c) => c[0]);
  assert.deepEqual(paq.slice(0, 5), ["disableCookies", "setDoNotTrack", "setTrackerUrl", "setSiteId", "enableLinkTracking"]);
  assert.deepEqual(w.win._paq[2], ["setTrackerUrl", "https://stats.example.com/matomo.php"]);
  assert.deepEqual(w.win._paq[3], ["setSiteId", "12"]);
  assert.equal(w.added.length, 1, "matomo.js loaded once");
  assert.equal(w.added[0].src, "https://stats.example.com/matomo.js");
  assert.equal(paq.filter((c) => c === "trackPageView").length, 2);
  assert.deepEqual(w.win._paq.find((c) => c[0] === "setReferrerUrl"), ["setReferrerUrl", "https://www.example.com/"]);
  assert.deepEqual(w.win._paq.filter((c) => c[0] === "setDocumentTitle").map((c) => c[1]), ["T", "Politics"]);
  assert.equal(matomo({ url: "", siteId: "1", site: "https://www.example.com", win: w.win, doc: w.doc }).on, false);
});

test("matomo: after a client-side move the page is counted with its own title, not an empty or old one", async () => {
  const { matomo } = await import("../packages/headless/src/matomo.mjs");
  const doc = { title: "Home", head: { appendChild() {} }, createElement: () => ({}) };
  const win = { location: { hostname: "www.example.com", href: "https://www.example.com/" } };
  const m = matomo({ url: "https://stats.example.test", siteId: "1", site: "https://www.example.com", win, doc });
  await m.pageWhenReady();
  win.location.href = "https://www.example.com/news/";
  doc.title = "";
  const counting = m.pageWhenReady();
  setTimeout(() => (doc.title = "News"), 120);
  await counting;
  assert.deepEqual(win._paq.filter((c) => c[0] === "setDocumentTitle").map((c) => c[1]), ["Home", "News"]);
  // A page that keeps the same title is still counted, after the wait.
  win.location.href = "https://www.example.com/news/2/";
  const t0 = Date.now();
  await m.pageWhenReady();
  assert.ok(Date.now() - t0 >= 1400);
  assert.equal(win._paq.filter((c) => c[0] === "trackPageView").length, 3);
});
