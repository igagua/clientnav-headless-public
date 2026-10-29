#!/usr/bin/env node
/**
 * Does the content door tell the same as WordPress's own REST API? Asks one CMS both ways and reports every
 * difference, grouped by kind. Reads only; works on any CMS with Client Navigator Headless.
 *
 *   npx clientnav-headless-parity --cms https://cms.example.com [--key SECRET] [--pages 3] [--singles 20]
 *
 * For every public type the REST API lists: the newest pages of the list (id order, title, address, dates,
 * author, terms, picture), the total, a sample of single entries (title, HTML, excerpt); for every public
 * taxonomy: its terms (name, address, count, parent). Words are compared as plain text, HTML with its spaces
 * evened out, addresses as paths. Exit code 1 when anything differs, so a front end's switch can be held on it.
 * --key: the shared secret, for a CMS whose door is closed (the REST API must still answer this machine).
 */
export async function parity({ cms, key = "", pages = 3, singles = 20, fetch: doFetch = globalThis.fetch, log = () => {} }) {
  const base = String(cms).replace(/\/+$/, "").replace(/\/wp-json$/, "");
  const auth = key ? { authorization: `Bearer ${key}` } : {};
  const differences = [];
  let checks = 0;

  async function get(url) {
    const res = await doFetch(url, { headers: { accept: "application/json", ...auth }, signal: AbortSignal.timeout(30000) });
    if (res.status === 404 || res.status === 400) return { status: res.status, body: null, total: null };
    if (!res.ok) throw new Error(`${res.status} from ${url}`);
    return { status: res.status, body: await res.json(), total: res.headers.get("x-wp-total") };
  }
  const rest = (route, q = "") => get(`${base}/wp-json/wp/v2/${route}${q ? `?${q}` : ""}`);
  const door = (route, q = "") => get(`${base}/wp-json/clientnav-headless/v1/${route}${q ? `?${q}` : ""}`);

  const ENT = { amp: "&", lt: "<", gt: ">", quot: '"', apos: "'", nbsp: " ", hellip: "…", ndash: "–", mdash: "—", lsquo: "‘", rsquo: "’", ldquo: "“", rdquo: "”" };
  const decode = (s) =>
    String(s ?? "").replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e) => (e[0] === "#" ? String.fromCodePoint(e[1] === "x" || e[1] === "X" ? parseInt(e.slice(2), 16) : Number(e.slice(1))) : (ENT[e.toLowerCase()] ?? m)));
  const text = (s) => decode(String(s ?? "").replace(/<[^>]*>/g, " ")).replace(/\s+/g, " ").trim();
  // HTML with its spaces evened out, the CMS's own links as paths (the door hands them out so), and the form
  // field WordPress fills with the address of the request itself.
  const origin = new URL(base).origin;
  const html = (s) =>
    String(s ?? "")
      .split(`href="${origin}/`).join('href="/')
      .replace(/(name="_wp_http_referer" value=")[^"]*"/g, '$1"')
      .replace(/\s+/g, " ")
      .replace(/> </g, "><")
      .trim();
  const path = (u) => {
    try {
      return new URL(u, base).pathname;
    } catch {
      return String(u ?? "");
    }
  };
  const gmt = (s) => (s ? `${String(s).replace(/Z$/, "")}Z` : null);

  function same(what, a, b) {
    checks++;
    if (JSON.stringify(a) === JSON.stringify(b)) return;
    // Long texts are shown around the first place where they part.
    let from = 0;
    if (typeof a === "string" && typeof b === "string") {
      while (from < a.length && a[from] === b[from]) from++;
      from = Math.max(0, from - 50);
    }
    const show = (v) => {
      const s = typeof v === "string" ? v.slice(from) : (JSON.stringify(v) ?? "undefined");
      return JSON.stringify(s.length > 140 ? `${from ? "…" : ""}${s.slice(0, 140)}…` : (from ? `…${s}` : s));
    };
    differences.push({ kind: what.replace(/ [^ ]*$/, "").replace(/\d+/g, "N"), what, rest: typeof a === "string" ? show(a) : show(a).slice(1, -1).replace(/\\"/g, '"'), door: typeof b === "string" ? show(b) : show(b).slice(1, -1).replace(/\\"/g, '"') });
  }

  // What is public: types and taxonomies as the REST API names them.
  const types = Object.values((await rest("types")).body).filter((t) => t.rest_base && t.viewable !== false && !["attachment", "nav_menu_item", "wp_block", "wp_template", "wp_template_part", "wp_navigation", "wp_font_family", "wp_font_face", "wp_global_styles"].includes(t.slug));
  const taxes = Object.values((await rest("taxonomies")).body).filter((t) => t.rest_base && t.visibility?.public !== false && !["nav_menu", "wp_pattern_category", "wp_template_part_area", "wp_theme"].includes(t.slug));
  log(`types: ${types.map((t) => t.slug).join(", ")}; taxonomies: ${taxes.map((t) => t.slug).join(", ")}`);
  const taxBase = Object.fromEntries(taxes.map((t) => [t.slug, t.rest_base]));

  // A type or taxonomy the door does not serve (not public to visitors) answers 400 there: noted, not compared.
  const skipped = [];
  const kept = [];
  const changing = [];
  for (const type of types) {
    const sample = [];
    if ((await door("posts", `type=${type.slug}&per_page=1`)).status === 400) {
      skipped.push(type.slug);
      continue;
    }
    for (let page = 1; page <= pages; page++) {
      const r = await rest(type.rest_base, `per_page=50&page=${page}&status=publish&_fields=id,slug,link,title,date_gmt,modified_gmt,author,featured_media,${taxes.map((t) => t.rest_base).join(",")}`).catch((e) => ({ error: e }));
      if (r.error || !r.body) break;
      const d = await door("posts", `type=${type.slug}&per_page=50&page=${page}&count=1`);
      const items = d.body?.items ?? [];
      if (page === 1) same(`total ${type.slug}`, Number(r.total), d.body?.total ?? null);
      same(`order ${type.slug} page ${page}`, r.body.map((p) => p.id), items.map((p) => p.id));
      const byId = new Map(items.map((p) => [p.id, p]));
      for (const p of r.body) {
        const c = byId.get(p.id);
        if (!c) continue;
        // A page kept on WordPress (account, checkout...): the door names its WordPress address on purpose.
        if (/^https?:/.test(c.path ?? "") && path(c.path) === path(p.link)) {
          kept.push(`${type.slug}:${p.slug}`);
          continue;
        }
        same(`title ${type.slug}:${p.slug}`, text(p.title?.rendered), text(c.title));
        same(`address ${type.slug}:${p.slug}`, path(p.link), c.path);
        same(`date ${type.slug}:${p.slug}`, gmt(p.date_gmt), c.date);
        same(`modified ${type.slug}:${p.slug}`, gmt(p.modified_gmt), c.modified);
        if (p.author !== undefined) same(`author ${type.slug}:${p.slug}`, p.author, c.by?.id ?? null);
        for (const t of taxes) {
          if (!Array.isArray(p[t.rest_base])) continue;
          same(`terms ${t.slug} ${type.slug}:${p.slug}`, [...p[t.rest_base]].sort((x, y) => x - y), (c.terms ?? []).filter((x) => x.taxonomy === t.slug).map((x) => x.id).sort((x, y) => x - y));
        }
        same(`picture ${type.slug}:${p.slug}`, !!p.featured_media, !!c.image);
        if (sample.length < singles && sample.length * 3 <= r.body.indexOf(p)) sample.push(p);
      }
      if (items.length < 50 || r.body.length < 50) break;
    }
    // Single entries: the whole text, as the REST API renders it and as the door hands it out.
    for (const p of sample) {
      const [r, d] = await Promise.all([rest(type.rest_base, `slug=${encodeURIComponent(p.slug)}&_fields=content,excerpt,title`), door("resolve", `path=${encodeURIComponent(path(p.link))}&with=elementor`)]);
      const e = d.body?.entry;
      const w = r.body?.[0];
      // The page that lists the posts is answered as a list (type "posts"), not as an entry: found, nothing more to compare.
      if (w && !e && d.body?.type === "posts") {
        checks++;
        continue;
      }
      if (!e || !w) {
        same(`single found ${type.slug}:${p.slug}`, !!w, !!e);
        continue;
      }
      same(`single title ${type.slug}:${p.slug}`, text(w.title?.rendered), text(e.title));
      if (html(w.content?.rendered) !== html(e.html)) {
        // Something on the page is drawn anew on every request (a random id, a nonce): asked again, WordPress
        // itself answers differently. Noted, not counted.
        const again = (await rest(type.rest_base, `slug=${encodeURIComponent(p.slug)}&_fields=content`)).body?.[0];
        if (html(again?.content?.rendered) !== html(w.content?.rendered)) {
          changing.push(`${type.slug}:${p.slug}`);
          checks++;
        } else same(`single html ${type.slug}:${p.slug}`, html(w.content?.rendered), html(e.html));
      } else checks++;
      // The door's excerpt may be the shorter one, cut at a word: it must be how WordPress's begins. An entry designed
      // in Elementor is left out: WordPress's own excerpt for it comes from the rendered layout, whose list widgets
      // change with every publish, while the door's comes from the saved text; no front end draws a page's excerpt.
      if (e.elementor) {
        checks++;
      } else {
        const cut = (s) => text(s).replace(/ ?(\[…\]|…|\.\.\.)$/, "");
        const [we, de] = [cut(w.excerpt?.rendered), cut(e.excerpt)];
        same(`single excerpt ${type.slug}:${p.slug}`, we.startsWith(de) && de.length > 0 ? de : we, de);
      }
    }
  }

  for (const t of taxes) {
    const all = [];
    for (let page = 1; page <= 500; page++) {
      const r = await rest(t.rest_base, `per_page=100&page=${page}&hide_empty=true&_fields=id,name,slug,link,count,parent`).catch(() => ({ body: null }));
      if (!r.body?.length) break;
      all.push(...r.body);
      if (r.body.length < 100) break;
    }
    const items = [];
    let refused = false;
    for (let offset = 0; offset < 20000; offset += 500) {
      const d = await door("terms", `taxonomy=${t.slug}&per_page=500&offset=${offset}`);
      if (d.status === 400) {
        refused = true;
        break;
      }
      const got = d.body?.items ?? [];
      items.push(...got);
      if (got.length < 500) break;
    }
    if (refused) {
      skipped.push(t.slug);
      continue;
    }
    const byId = new Map(items.map((x) => [x.id, x]));
    same(`term list ${t.slug}`, all.map((x) => x.id).sort((a, b) => a - b), items.filter((x) => (x.count ?? 1) > 0).map((x) => x.id).sort((a, b) => a - b));
    for (const w of all) {
      const x = byId.get(w.id);
      if (!x) continue;
      same(`term name ${t.slug}:${w.slug}`, text(w.name), text(x.name));
      same(`term address ${t.slug}:${w.slug}`, path(w.link), x.path);
      same(`term count ${t.slug}:${w.slug}`, w.count, x.count);
      same(`term parent ${t.slug}:${w.slug}`, w.parent ?? 0, x.parent ?? 0);
    }
  }
  return { checks, differences, skipped, kept, changing, types: types.map((t) => t.slug), taxonomies: Object.keys(taxBase) };
}

/** Differences of one kind together, three examples each. */
export function report({ checks, differences, skipped = [], kept = [], changing = [] }) {
  const groups = new Map();
  for (const d of differences) groups.set(d.kind, [...(groups.get(d.kind) ?? []), d]);
  const lines = [`${checks} comparisons, ${differences.length} differences in ${groups.size} kinds`];
  if (skipped.length) lines.push(`Not served by the door, so not compared: ${skipped.join(", ")}`);
  if (changing.length) lines.push(`Drawn anew on every request by WordPress itself, so their HTML is not compared: ${changing.join(", ")}`);
  if (kept.length) lines.push(`Kept on WordPress (the door names their WordPress address on purpose): ${kept.join(", ")}`);
  for (const [kind, list] of [...groups].sort((a, b) => b[1].length - a[1].length)) {
    lines.push("", `${list.length} × ${kind}`);
    for (const d of list.slice(0, 3)) lines.push(`   ${d.what}: REST ${d.rest} | door ${d.door}`);
  }
  return lines.join("\n");
}

const run = process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href;
if (run || process.argv[1]?.endsWith("clientnav-headless-parity")) {
  const args = process.argv.slice(2);
  const opt = (k, d) => {
    const i = args.indexOf(k);
    return i >= 0 ? args[i + 1] : d;
  };
  parity({ cms: opt("--cms", process.env.CMS_URL), key: opt("--key", process.env.REVALIDATE_SECRET ?? ""), pages: Number(opt("--pages", 3)), singles: Number(opt("--singles", 20)), log: (s) => console.error(s) })
    .then((r) => {
      console.log(report(r));
      process.exit(r.differences.length ? 1 : 0);
    })
    .catch((e) => {
      console.error(`The check could not run: ${e.message}`);
      process.exit(2);
    });
}
