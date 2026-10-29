/**
 * Client Navigator Anti-Spam for a headless front end: the server half (Node 18 or newer, no dependencies).
 *
 * The front end's server holds the same secret as the CMS (CLIENTNAV_ANTISPAM_SECRET here; "Anti-Spam
 * front-end secret" under Client Navigator → Connection there) and signs every request it passes on.
 * The CMS then knows the request came through the front end, and which visitor it was.
 *
 *   import { frontendConfig, signedHeaders, checkNames, check } from "./antispam-server.mjs";
 *
 *   const config = await frontendConfig(cmsOrigin, secret);        // give this to the browser half
 *   // Passing a form on to a form plugin on the CMS (Fluent Forms, for example):
 *   const body = new URLSearchParams({ action: "fluentform_submit", form_id, data }).toString();
 *   await fetch(cmsOrigin + "/wp-admin/admin-ajax.php", { method: "POST", body,
 *     headers: { "Content-Type": "application/x-www-form-urlencoded", ...signedHeaders(secret, visitorIp, body) } });
 *   // A form of the front end's own (no form plugin behind it):
 *   const verdict = await check(cmsOrigin, secret, visitorIp, fields, "/contact/");   // { ok, message }
 *
 * The body that is signed must be exactly the body that is sent: build it as a string first. Send
 * URL-encoded or JSON bodies; a multipart body cannot be checked by the CMS.
 */
import { createHash, createHmac } from "node:crypto";

export const HEADER_TIME = "X-ClientNav-Frontend-Time";
export const HEADER_IP = "X-ClientNav-Visitor-IP";
export const HEADER_SIGNATURE = "X-ClientNav-Frontend-Signature";

/** The signature the CMS expects: HMAC-SHA256 over the time, the visitor's address and the body's SHA-256. */
export function sign(secret, time, ip, body) {
  const bodyHash = createHash("sha256").update(body ?? "").digest("hex");
  return createHmac("sha256", secret).update(`${time}\n${ip}\n${bodyHash}`).digest("hex");
}

/** Headers to add to a request passed on to the CMS. `ip` is the visitor's address as the front end saw it. */
export function signedHeaders(secret, ip, body = "", now = Date.now()) {
  const time = String(Math.floor(now / 1000));
  const address = String(ip ?? "");
  return { [HEADER_TIME]: time, [HEADER_IP]: address, [HEADER_SIGNATURE]: sign(secret, time, address, body) };
}

let cached = null;

/**
 * What the browser half needs (key, field names, the trap's name, Turnstile's site key when it is on).
 * Kept for five minutes. Null when anti-spam's headless mode is off on the CMS or the CMS cannot be asked:
 * the forms then work without the checks rather than not at all.
 */
export async function frontendConfig(cmsOrigin, secret, { maxAge = 300000, fetchImpl = fetch } = {}) {
  if (cached && cached.origin === cmsOrigin && Date.now() - cached.at < maxAge) return cached.value;
  let value = null;
  try {
    const res = await fetchImpl(`${cmsOrigin}/wp-json/clientnav/v1/antispam/frontend`, {
      headers: signedHeaders(secret, "", ""),
      cache: "no-store",
      signal: AbortSignal.timeout(8000),
    });
    if (res.ok) value = await res.json();
  } catch {
    value = null;
  }
  cached = { origin: cmsOrigin, at: Date.now(), value };
  return value;
}

/** The names of the check fields, to let them through a route that passes on only fields it knows. */
export function checkNames(config) {
  return config && config.enabled ? [config.fields.key, config.fields.time, config.fields.acted, config.honeypot] : [];
}

/**
 * Ask the CMS about a form of the front end's own. `fields` holds the answers and the check fields.
 * Returns { ok: true } for a person; { ok: false, message } when the submission must be refused.
 * If the CMS cannot be asked the submission goes through: a fault must not lose an enquiry.
 */
export async function check(cmsOrigin, secret, ip, fields, page = "/", { fetchImpl = fetch } = {}) {
  const body = JSON.stringify({ fields, page });
  try {
    const res = await fetchImpl(`${cmsOrigin}/wp-json/clientnav/v1/antispam/check`, {
      method: "POST",
      headers: { "Content-Type": "application/json", ...signedHeaders(secret, ip, body) },
      body,
      cache: "no-store",
      signal: AbortSignal.timeout(8000),
    });
    if (!res.ok) return { ok: true, unchecked: true };
    const out = await res.json();
    return out && out.ok === false ? { ok: false, message: String(out.message ?? "") } : { ok: true };
  } catch {
    return { ok: true, unchecked: true };
  }
}
