/**
 * The "seo" every door answer carries, for a page's head. With PR Register on the CMS (source "pr-register")
 * it is that plugin's head; without it (source "door") the entry's own title and description.
 *
 *   import { metadata, jsonLd } from "@clientnav/headless/seo";
 *   export async function generateMetadata() { return metadata(page.seo, { site: "https://www.example.com", cmsFront: [...] }); }
 *
 * `site` is this front end's public address, `path` the page's (for a canonical when the CMS gives none); `cmsFront` lists addresses the CMS may use for it instead (a
 * preview or staging address), which are written as `site`. Titles and descriptions come as plain text.
 */
import { jsonLd as prrJsonLd, toNextMetadata } from "../vendor/pr-register.mjs";

const ENTITIES = { "&amp;": "&", "&#038;": "&", "&#38;": "&", "&#8217;": "’", "&rsquo;": "’", "&#8216;": "‘", "&lsquo;": "‘", "&#8211;": "–", "&ndash;": "–", "&#8212;": "—", "&mdash;": "—", "&quot;": '"', "&#034;": '"', "&#34;": '"', "&#039;": "'", "&#39;": "'", "&hellip;": "…", "&#8230;": "…", "&nbsp;": " " };
export const plain = (s) => (typeof s === "string" ? s.replace(/&(?:#0?\d+|[a-z]+);/gi, (m) => ENTITIES[m.toLowerCase()] ?? m) : s);

/** The answer in this site's terms: its own address everywhere, plain words. */
export function localize(seo, { site, cmsFront = [] } = {}) {
  if (!seo || typeof seo !== "object") return seo;
  let raw = JSON.stringify(seo);
  if (site) for (const from of [].concat(cmsFront)) if (from && from !== site) raw = raw.split(String(from).replace(/\/+$/, "")).join(String(site).replace(/\/+$/, ""));
  const out = JSON.parse(raw);
  for (const part of [out, out.og, out.twitter]) {
    if (!part || typeof part !== "object" || Array.isArray(part)) continue;
    if (part.title) part.title = plain(part.title);
    if (part.description) part.description = plain(part.description);
  }
  return out;
}

/** The "seo" of a door answer, wherever it sits: the answer's own, or its entry's, term's or author's. */
export const seoOf = (answer) => answer?.seo ?? answer?.entry?.seo ?? answer?.term?.seo ?? answer?.author?.seo;

/** For Next.js's generateMetadata. A door answer without PR Register gives title and description only. */
export function metadata(seo, opts = {}) {
  const s = localize(seo, opts);
  if (!s) return {};
  const out = s.source === "pr-register" || "found" in s ? toNextMetadata({ found: true, ...s }) : { title: s.title ? { absolute: s.title } : undefined, description: s.description || undefined };
  // Without a canonical from the CMS (PR Register absent, or not in headless mode), the page's own address.
  if (!out.alternates?.canonical && opts.site && opts.path) out.alternates = { ...out.alternates, canonical: String(opts.site).replace(/\/+$/, "") + opts.path };
  return out;
}

/** The structured data as the text of one <script type="application/ld+json">, or "". */
export const jsonLd = (seo, opts = {}) => prrJsonLd(localize(seo, opts));
