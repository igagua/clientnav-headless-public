import { prr } from "@/lib/prr";

/** llms.txt as PR Register writes it for this front end (served at /llms.txt by a rewrite in next.config.ts). */
export const revalidate = 300;

export async function GET() {
  let text: string | null = null;
  try {
    text = await prr.llms();
  } catch {
    return new Response("llms.txt cannot be read just now.", { status: 503, headers: { "retry-after": "120" } });
  }
  if (!text) return new Response("Not found", { status: 404 });
  return new Response(text, { headers: { "content-type": "text/plain; charset=utf-8", "cache-control": "public, max-age=300" } });
}
