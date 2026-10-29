import { SITE_URL, door } from "@/lib/cms";

/**
 * Every public address the CMS names (/paths leaves out entries marked noindex). Enough for a small or middle
 * site; a big archive gives a sitemap per month from /months and /month instead.
 */
export const revalidate = 3600;

const xml = (s: string) => s.replace(/&/g, "&amp;").replace(/</g, "&lt;");

export async function GET() {
  const rows: { path: string; modified?: string }[] = [];
  for (let page = 1; page <= 20; page++) {
    const answer = await door.paths({ page, per_page: 500, with: page === 1 ? "terms,authors" : undefined });
    rows.push(...(answer?.items ?? []));
    if (!answer || page >= (answer.pages ?? 1)) break;
  }
  const body = rows.map((r) => `<url><loc>${xml(SITE_URL + r.path)}</loc>${r.modified ? `<lastmod>${xml(r.modified)}</lastmod>` : ""}</url>`).join("");
  return new Response(`<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${body}</urlset>`, { headers: { "content-type": "application/xml; charset=utf-8" } });
}
