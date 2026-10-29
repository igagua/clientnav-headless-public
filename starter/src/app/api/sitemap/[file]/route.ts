import { prr } from "@/lib/prr";

/** PR Register's sitemaps, served under their own names (/sitemap_index.xml, /post-sitemap.xml, …) by a rewrite in next.config.ts. */
export const revalidate = 300;

export async function GET(_: Request, { params }: { params: Promise<{ file: string }> }) {
  const { file } = await params;
  if (!/^[a-z0-9_-]+\.xml$/i.test(file)) return new Response("Not found", { status: 404 });
  let xml: string | null = null;
  try {
    xml = await prr.sitemap(file);
  } catch {
    return new Response("The sitemap cannot be read just now.", { status: 503, headers: { "retry-after": "120" } });
  }
  if (!xml) return new Response("Not found", { status: 404 });
  return new Response(xml, { headers: { "content-type": "application/xml; charset=utf-8", "cache-control": "public, max-age=300" } });
}
