"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";

/**
 * A form drawn from what the CMS says it asks (the door's /forms/<id>). Answers go to this site's own
 * /api/form/<id>/, which hands them to the CMS; nothing is kept in the browser or on this server. The spam check
 * (Cloudflare Turnstile, when the form has one) is loaded only when the visitor first touches the form.
 */
export type FormField = { name?: string; label?: string; placeholder?: string; required?: boolean; type: string; auto?: string; value?: string; options?: { label: string; value: string }[]; fields?: FormField[]; html?: string };
export type FormDef = { id: number; title: string; fields: FormField[]; button: string; thanks: string; sitekey: string; trap: string };

type Turnstile = { render: (el: HTMLElement, opts: Record<string, unknown>) => string; reset: (id?: string) => void };
declare global {
  interface Window {
    turnstile?: Turnstile;
    __cnhTurnstile?: Promise<Turnstile>;
  }
}

function loadTurnstile(): Promise<Turnstile> {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  window.__cnhTurnstile ??= new Promise((resolve, reject) => {
    const s = document.createElement("script");
    s.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    s.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error("no turnstile")));
    s.onerror = () => {
      window.__cnhTurnstile = undefined;
      reject(new Error("blocked"));
    };
    document.head.appendChild(s);
  });
  return window.__cnhTurnstile;
}

/** Client Navigator Anti-Spam's checks a browser can vouch for: when the page was read and whether the visitor acted. */
type Guard = { enabled: boolean; key?: string; honeypot?: string; fields?: { key: string; time: string; acted: string }; turnstile_sitekey?: string };
const pageRead = Date.now();
let acted = 0;
let guard: Promise<Guard> | undefined;
if (typeof document !== "undefined") {
  for (const name of ["click", "keydown", "pointerdown", "touchstart"]) document.addEventListener(name, (e) => void (e.isTrusted !== false && (acted = 1)), { capture: true, passive: true });
}
const loadGuard = (): Promise<Guard> =>
  (guard ??= fetch("/api/form/guard/")
    .then((r) => r.json() as Promise<Guard>)
    .catch(() => {
      guard = undefined;
      return { enabled: false };
    }));

const WORDS = {
  sending: "Sending…",
  check: "Please complete the security check below, then send again.",
  checkFailed: "The security check could not load. Please try again later.",
  fields: "Please check the marked fields.",
  failed: "Sorry, that did not go through. Please try again.",
};

function Field({ field, uid, errors }: { field: FormField; uid: string; errors: Record<string, string> }) {
  if (field.type === "note") return <div className="ff-note" dangerouslySetInnerHTML={{ __html: field.html ?? "" }} />;
  if (field.type === "hidden") return <input type="hidden" name={field.name} value={field.value ?? ""} readOnly />;
  if (field.type === "group")
    return (
      <div className="ff-group">
        {(field.fields ?? []).map((f, i) => (
          <Field key={f.name ?? i} field={f} uid={uid} errors={errors} />
        ))}
      </div>
    );
  const id = `${uid}-${field.name ?? "x"}`;
  const label = field.label || field.placeholder || field.name || "";
  const error = field.name ? errors[field.name] : undefined;
  const common = { id, name: field.name, required: field.required, "aria-invalid": error ? true : undefined, "aria-describedby": error ? `${id}-e` : undefined };
  let control;
  if (field.type === "textarea") control = <textarea {...common} rows={4} placeholder={field.placeholder} />;
  else if (field.type === "select")
    control = (
      <select {...common} defaultValue="">
        <option value="">{field.placeholder || "Choose…"}</option>
        {(field.options ?? []).map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    );
  else if (field.type === "checks" || field.type === "radio")
    control = (
      <div className="ff-options">
        {(field.options ?? []).map((o, i) => (
          <label key={o.value} htmlFor={`${id}-${i}`}>
            <input id={`${id}-${i}`} type={field.type === "radio" ? "radio" : "checkbox"} name={field.type === "radio" ? field.name : `${field.name}[]`} value={o.value} /> {o.label}
          </label>
        ))}
      </div>
    );
  else control = <input {...common} type={["text", "tel", "email", "date", "url", "number"].includes(field.type) ? field.type : "text"} placeholder={field.placeholder} autoComplete={field.auto} inputMode={field.type === "tel" ? "tel" : undefined} />;
  return (
    <div className="ff-field">
      <label htmlFor={id}>
        {label.replace(/\*$/, "")}
        {field.required ? <span aria-hidden="true"> *</span> : null}
      </label>
      {control}
      {error ? (
        <p className="ff-error" id={`${id}-e`}>
          {error}
        </p>
      ) : null}
    </div>
  );
}

export function FormBox({ form, compact }: { form: FormDef; compact?: boolean }) {
  const uid = `f${form.id}`;
  const [state, setState] = useState<"idle" | "sending" | "sent">("idle");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState("");
  const [touched, setTouched] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const token = useRef("");
  const widget = useRef<string | undefined>(undefined);
  const trap = useRef<HTMLInputElement>(null);
  const [trapName, setTrapName] = useState("");
  // Turnstile comes from the form itself or, when its own Turnstile is on, from Anti-Spam: either way the CMS refuses without it.
  const [guardKey, setGuardKey] = useState("");
  const sitekey = form.sitekey || guardKey;

  useEffect(() => {
    if (!touched) return;
    let gone = false;
    loadGuard().then((g) => {
      if (gone || !g.enabled) return;
      if (g.honeypot) setTrapName(g.honeypot);
      if (g.turnstile_sitekey) setGuardKey(g.turnstile_sitekey);
    });
    return () => {
      gone = true;
    };
  }, [touched]);

  useEffect(() => {
    if (!touched || !sitekey || !box.current || widget.current !== undefined) return;
    let gone = false;
    loadTurnstile()
      .then((t) => {
        if (gone || !box.current || widget.current !== undefined) return;
        widget.current = t.render(box.current, { sitekey, theme: "light", callback: (value: string) => (token.current = value), "expired-callback": () => (token.current = ""), "error-callback": () => (token.current = "") });
      })
      .catch(() => setNotice(WORDS.checkFailed));
    return () => {
      gone = true;
    };
  }, [touched, sitekey]);

  async function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (state === "sending") return;
    setErrors({});
    setNotice("");
    const g = await loadGuard();
    if ((form.sitekey || (g.enabled && g.turnstile_sitekey)) && !token.current) {
      setTouched(true);
      setNotice(WORDS.check);
      return;
    }
    const data = new FormData(event.currentTarget);
    const fields: [string, string][] = [];
    data.forEach((value, name) => {
      if (typeof value === "string" && !name.startsWith("cf-")) fields.push([name, value]);
    });
    if (g.enabled && g.key && g.honeypot && g.fields) {
      fields.push([g.fields.key, g.key], [g.fields.time, String(Date.now() - pageRead)], [g.fields.acted, String(acted)]);
      if (!fields.some(([name]) => name === g.honeypot)) fields.push([g.honeypot, trap.current?.value ?? ""]);
    }
    setState("sending");
    try {
      const res = await fetch(`/api/form/${form.id}/`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ fields, token: token.current, page: location.pathname }) });
      const out = (await res.json().catch(() => ({}))) as { ok?: boolean; errors?: Record<string, string>; message?: string };
      if (res.ok && out.ok) {
        setState("sent");
        return;
      }
      setErrors(out.errors ?? {});
      setNotice(out.message || (out.errors ? WORDS.fields : WORDS.failed));
    } catch {
      setNotice(WORDS.failed);
    }
    token.current = "";
    if (widget.current !== undefined) window.turnstile?.reset(widget.current);
    setState("idle");
  }

  if (state === "sent")
    return (
      <p className="ff-thanks" role="status">
        {form.thanks}
      </p>
    );
  return (
    <form className={compact ? "ff ff-compact" : "ff"} onSubmit={send} onFocus={() => setTouched(true)} onPointerDown={() => setTouched(true)}>
      {form.fields.map((field, i) => (
        <Field key={field.name ?? i} field={field} uid={uid} errors={errors} />
      ))}
      {form.trap ? (
        <div className="ff-trap" aria-hidden="true">
          <label>
            Leave this empty
            <input type="text" name={form.trap} tabIndex={-1} autoComplete="off" defaultValue="" />
          </label>
        </div>
      ) : null}
      {trapName ? (
        <div className="ff-trap" aria-hidden="true">
          <label>
            Leave this empty too
            <input ref={trap} type="text" name={trapName} tabIndex={-1} autoComplete="off" defaultValue="" />
          </label>
        </div>
      ) : null}
      <div ref={box} className="ff-check" />
      {notice ? (
        <p className="ff-notice" role="alert">
          {notice}
        </p>
      ) : null}
      <button type="submit" className="ff-send" disabled={state === "sending"}>
        {state === "sending" ? WORDS.sending : form.button}
      </button>
    </form>
  );
}
