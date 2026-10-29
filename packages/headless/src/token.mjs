/**
 * Preview tokens signed by the plugin on the CMS (includes/preview.php):
 *   base64url(JSON claims) "." base64url(HMAC-SHA256(first part, shared secret))
 * Claims: pid (entry), uid (user), scope ("preview" for the Preview button, "live" for the live window), exp.
 * The scope keeps a token for the live window from opening a draft and the other way round.
 */
import { createHmac, timingSafeEqual } from "node:crypto";

export const PREVIEW_COOKIE = "cn_preview";

const sign = (payload, secret) => createHmac("sha256", secret).update(payload).digest("base64url");

/** The claims of a valid, unexpired token of that scope, or null. A secret under 32 characters opens nothing. */
export function verifyPreviewToken(token, scope, secret, now = Date.now() / 1000) {
  if (!token || !secret || String(secret).length < 32) return null;
  const parts = String(token).split(".");
  if (parts.length !== 2) return null;
  const given = Buffer.from(parts[1]);
  const expected = Buffer.from(sign(parts[0], secret));
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) return null;
  let c;
  try {
    c = JSON.parse(Buffer.from(parts[0], "base64url").toString("utf8"));
  } catch {
    return null;
  }
  if (!c || !Number.isInteger(c.pid) || !Number.isInteger(c.uid) || typeof c.exp !== "number" || c.scope !== scope || c.exp < now) return null;
  return c;
}

/** Signs as the CMS does. For tests. */
export function signPreviewToken(claims, secret) {
  const payload = Buffer.from(JSON.stringify(claims)).toString("base64url");
  return `${payload}.${sign(payload, secret)}`;
}

/** Compares two secrets in constant time. */
export function sameSecret(a, b) {
  const x = Buffer.from(String(a ?? ""));
  const y = Buffer.from(String(b ?? ""));
  return x.length === y.length && x.length > 0 && timingSafeEqual(x, y);
}
