/**
 * Client Navigator Anti-Spam for a headless front end: the browser half.
 *
 * Does what the connector's own script does on a page WordPress draws: notes when the page was read
 * and whether the visitor pressed a key, clicked or touched, and gives every form an empty field people
 * cannot see. Nothing here talks to a server; the front end's own form route passes the values on.
 *
 *   import { antiSpam } from "./antispam-client.js";
 *   const guard = antiSpam(config);          // config: what the server half's frontendConfig() returned
 *   guard.protect(formElement);              // adds the hidden trap field to a <form>
 *   const extra = guard.values(formElement); // { cnas_k, cnas_t, cnas_i, cnas_h_xxxxxx } to send with the answers
 *
 * `config` may be null (anti-spam off, or the CMS could not be asked): then nothing is added and
 * values() returns {}.
 */
export function antiSpam(config, doc = typeof document !== "undefined" ? document : null) {
  const off = !config || !config.enabled || !doc;
  const started = Date.now();
  let acted = 0;

  if (!off) {
    const mark = (event) => {
      if (event.isTrusted !== false) acted = 1;
    };
    for (const name of ["click", "keydown", "pointerdown", "touchstart"]) doc.addEventListener(name, mark, { capture: true, passive: true });
  }

  /** The hidden trap: off-screen, not display:none (some senders skip those), out of the tab order, hidden from screen readers. */
  function protect(form) {
    if (off || !form || form.querySelector(`input[name="${config.honeypot}"]`)) return;
    const wrap = doc.createElement("div");
    wrap.setAttribute("aria-hidden", "true");
    wrap.style.cssText = "position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;overflow:hidden!important";
    const input = doc.createElement("input");
    input.type = "text";
    input.name = config.honeypot;
    input.tabIndex = -1;
    input.autocomplete = "off";
    input.setAttribute("aria-label", "Leave this field empty");
    wrap.appendChild(input);
    form.appendChild(wrap);
  }

  /** The check fields for one submission. Call it at the moment of sending. */
  function values(form) {
    if (off) return {};
    const trap = form ? form.querySelector(`input[name="${config.honeypot}"]`) : null;
    return {
      [config.fields.key]: config.key,
      [config.fields.time]: String(Date.now() - started),
      [config.fields.acted]: String(acted),
      [config.honeypot]: trap ? trap.value : "",
    };
  }

  return { protect, values, enabled: !off };
}
