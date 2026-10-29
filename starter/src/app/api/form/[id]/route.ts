import { checkNames, frontendConfig, signedHeaders } from "@clientnav/headless/antispam";
import { CMS_URL, door } from "@/lib/cms";
import type { FormDef, FormField } from "@/components/form";

/**
 * POST /api/form/<id>/   A visitor's answers to one Fluent Forms form, handed to the CMS.
 *
 * The CMS does the real work: it checks the answers and the spam check, saves the entry, sends the notice and runs
 * the CRM rules. This route only passes on the fields the form really has, signed for Client Navigator Anti-Spam
 * when this site holds its secret (CLIENTNAV_ANTISPAM_SECRET). Nothing of a request is logged or kept here.
 */
export const dynamic = "force-dynamic";

const ANTISPAM_SECRET = (process.env.CLIENTNAV_ANTISPAM_SECRET ?? "").trim();
const WINDOW = 10 * 60 * 1000;
const MOST = 8;
const seen = new Map<string, number[]>();

/** A few tries per address in ten minutes; the spam check does the rest. */
function tooMany(ip: string): boolean {
  const now = Date.now();
  const times = (seen.get(ip) ?? []).filter((t) => now - t < WINDOW);
  times.push(now);
  seen.set(ip, times);
  if (seen.size > 5000) for (const [key, list] of seen) if (!list.some((t) => now - t < WINDOW)) seen.delete(key);
  return times.length > MOST;
}

const names = (fields: FormField[]): string[] => fields.flatMap((f) => (f.type === "group" ? names(f.fields ?? []) : f.name && f.type !== "note" ? [f.type === "checks" ? `${f.name}[]` : f.name] : []));
const say = (data: unknown, status: number) => Response.json(data, { status, headers: { "Cache-Control": "no-store" } });
const FAILED = "Sorry, we could not take your message right now. Please try again later.";

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^[1-9]\d{0,5}$/.test(id)) return say({ ok: false }, 404);
  const ip = (request.headers.get("cf-connecting-ip") ?? request.headers.get("x-real-ip") ?? (request.headers.get("x-forwarded-for") ?? "").split(",")[0]).trim() || "unknown";
  if (tooMany(ip)) return say({ ok: false, message: "Too many tries. Please wait a few minutes." }, 429);

  const body = (await request.json().catch(() => null)) as { fields?: unknown; token?: unknown; page?: unknown } | null;
  if (!body || !Array.isArray(body.fields) || body.fields.length > 60) return say({ ok: false }, 400);
  const def = (await door.form(Number(id)).catch(() => null)) as FormDef | null;
  if (!def) return say({ ok: false }, 404);

  // Only the fields this form has, each of a sane length; a hidden field keeps the value the CMS set.
  const known = new Set(names(def.fields));
  if (def.trap) known.add(def.trap);
  const guard = ANTISPAM_SECRET ? await frontendConfig(CMS_URL, ANTISPAM_SECRET) : null;
  for (const name of checkNames(guard)) known.add(name);
  const fixed = new Map(def.fields.filter((f) => f.type === "hidden" && f.name).map((f) => [f.name as string, f.value ?? ""]));
  const data = new URLSearchParams();
  for (const pair of body.fields) {
    if (!Array.isArray(pair) || typeof pair[0] !== "string" || typeof pair[1] !== "string" || !known.has(pair[0])) continue;
    data.append(pair[0], fixed.has(pair[0]) ? (fixed.get(pair[0]) as string) : pair[1].slice(0, 5000));
  }
  if (def.trap && !data.has(def.trap)) data.append(def.trap, "");
  if (typeof body.token === "string" && body.token.length < 4000) data.append("cf-turnstile-response", body.token);
  const page = typeof body.page === "string" && body.page.startsWith("/") && body.page.length < 300 ? body.page : "/";
  data.append("_wp_http_referer", page);

  const sent = new URLSearchParams({ action: "fluentform_submit", form_id: String(def.id), data: data.toString() }).toString();
  let res: Response;
  try {
    res = await fetch(`${CMS_URL}/wp-admin/admin-ajax.php`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded", "X-Forwarded-For": ip, "X-Real-IP": ip, "X-Forwarded-Proto": "https", ...(ANTISPAM_SECRET ? signedHeaders(ANTISPAM_SECRET, ip === "unknown" ? "" : ip, sent) : {}) },
      body: sent,
      signal: AbortSignal.timeout(30000),
      cache: "no-store",
    });
  } catch {
    return say({ ok: false, message: FAILED }, 502);
  }
  const out = (await res.json().catch(() => null)) as { success?: boolean; errors?: Record<string, unknown> | string; data?: { result?: { message?: string } } } | null;
  if (res.ok && out && out.success !== false && !out.errors) return say({ ok: true }, 200);

  const errors: Record<string, string> = {};
  let message = typeof out?.errors === "string" ? FAILED : "";
  for (const [name, value] of Object.entries(out?.errors && typeof out.errors === "object" ? out.errors : {})) {
    const first = Array.isArray(value) ? value[0] : value && typeof value === "object" ? Object.values(value)[0] : value;
    const text = String(first ?? "").replace(/<[^>]+>/g, "").slice(0, 200);
    if (name === "cf-turnstile-response") message = "The security check did not pass. Please try it again.";
    else if (name === "cnas") message = "Sorry, this form could not be sent. Please reload the page and try again.";
    else if (text) errors[name] = text;
  }
  if (!message && !Object.keys(errors).length) return say({ ok: false, message: FAILED }, 502);
  return say({ ok: false, errors, message }, 422);
}
