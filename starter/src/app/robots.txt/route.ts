import { INDEXABLE, SITE_URL } from "@/lib/cms";
import { hasPrr, prr } from "@/lib/prr";

export const revalidate = 3600;

export async function GET() {
  const headers = { "content-type": "text/plain; charset=utf-8" };
  if (!INDEXABLE) return new Response(`User-agent: *\nDisallow: /\n`, { headers });
  // With PR Register on the CMS, its robots.txt (written for this front end, naming its sitemap index).
  if (await hasPrr()) {
    const text = await prr.robots().catch(() => null);
    if (text) return new Response(text, { headers });
  }
  return new Response(`User-agent: *\nAllow: /\nDisallow: /preview/\nDisallow: /api/\n\nSitemap: ${SITE_URL}/sitemap.xml\n`, { headers });
}
