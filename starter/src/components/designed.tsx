import Link from "next/link";
import Script from "next/script";
import type { CSSProperties, ElementType, ReactNode } from "react";
import type { Raw } from "@/lib/cms";
import { FormBox } from "./form";

/**
 * A page or a site part made in Elementor, as the door hands it over (`with=elementor`, `/part`): plain parts
 * (box, heading, paragraph, button, picture, icon, video, divider, text, list, icon box, quote) with the classes
 * the page builder gave them, and the page's own style rules scoped under the wrapper class the CMS names.
 * A part the plugin does not know ("x") comes with its own markup, drawn as it is. Everything here is the CMS's
 * own content, already cleaned by the plugin; nothing is read from the visitor.
 */
export type Part = Raw;

const BOX_TAGS = new Set(["div", "section", "header", "footer", "article", "aside", "nav", "main", "details", "summary", "span"]);
const TEXT_TAGS = new Set(["h1", "h2", "h3", "h4", "h5", "h6", "p", "span", "div"]);
const colour = (value?: string) => (value && /^(#[0-9a-f]{3,8}|rgba?\([0-9., %]+\))$/i.test(value) ? value : undefined);
const cx = (part: Part, ...more: (string | false | undefined)[]) => [...(part.cls ?? []), ...more].filter(Boolean).join(" ") || undefined;
/** The attributes an editor gave an element that name or describe it (aria-*, role, title), as the plugin hands them over. */
const named = (part: Part): Record<string, string> => Object.fromEntries(Object.entries((part.attrs ?? {}) as Record<string, string>).filter(([k, v]) => /^(aria-[a-z-]+|role|title)$/.test(k) && typeof v === "string"));

/** A link inside the site goes through the router; anything else is a plain link. */
function To({ to, className, children, style, attrs }: { to: { href: string; blank?: boolean }; className?: string; children: ReactNode; style?: CSSProperties; attrs?: Record<string, string> }) {
  const outside = !to.href.startsWith("/") || to.href.startsWith("/wp-content/");
  const extra = { ...(attrs ?? {}), ...(to.blank ? { target: "_blank", rel: "noopener" } : {}) };
  return outside ? (
    <a href={to.href} className={className} style={style} {...extra}>
      {children}
    </a>
  ) : (
    <Link href={to.href} className={className} style={style} {...extra}>
      {children}
    </Link>
  );
}

/** A page's style rules, as the CMS scoped them; rules for phones get the weight to win over the base rules. */
export function Styles({ css, scope }: { css?: string; scope?: string }) {
  if (!css) return null;
  const s = scope || ".cnh-el";
  const safe = css.replace(/</g, "").replace(new RegExp(`@media\\(max-width:767px\\)\\{${s.replace(".", "\\.")} `, "g"), `@media(max-width:767px){${s}${s}${s} `);
  return <style dangerouslySetInnerHTML={{ __html: safe }} />;
}

/** The stylesheets site-made widgets register on the CMS, as the plugin lists them: paths on this site or the CMS's own address only. */
export function Assets({ assets, cms }: { assets?: string[]; cms?: string }) {
  const ok = (a: string) => a.startsWith("/") || (!!cms && a.startsWith(cms.replace(/\/+$/, "") + "/"));
  return (
    <>
      {/* With a precedence React hoists each stylesheet into the head once, however many designed blocks name it. */}
      {(assets ?? []).filter(ok).map((href) => (
        <link key={href} rel="stylesheet" href={href} precedence="site" />
      ))}
    </>
  );
}

/**
 * The scripts site-made widgets bring (a menu's toggle, a carousel): loaded after the page is interactive, each once,
 * from this site's paths or the CMS only. They run as on the CMS; what they expect of the page is the site's own affair.
 */
export function Scripts({ scripts, cms }: { scripts?: string[]; cms?: string }) {
  const ok = (a: string) => a.startsWith("/") || (!!cms && a.startsWith(cms.replace(/\/+$/, "") + "/"));
  return (
    <>
      {/* "#module" marks a script WordPress prints as a module (type="module"); it must load as one. */}
      {(scripts ?? []).filter(ok).map((src) =>
        src.endsWith("#module") ? <Script key={src} src={src.slice(0, -7)} type="module" strategy="afterInteractive" /> : <Script key={src} src={src} strategy="afterInteractive" />,
      )}
    </>
  );
}

/** The page builder's popups that links in these parts open ("#elementor-action…popup:open", settings {"id":…}). */
function popupIds(parts: Part[], out: Set<number> = new Set()): Set<number> {
  const scan = (text?: string) => {
    for (const m of (text ?? "").matchAll(/elementor-action(?:%3A|:)action(?:%3D|=)popup(?:%3A|:)open(?:%26|&|&amp;)settings(?:%3D|=)([A-Za-z0-9+/=%]+)/g)) {
      try {
        const id = Number(JSON.parse(atob(decodeURIComponent(m[1]))).id);
        if (id > 0) out.add(id);
      } catch {}
    }
  };
  for (const p of parts ?? []) {
    scan(p.link?.href);
    if (p.t === "x") scan(p.html);
    if (Array.isArray(p.kids)) popupIds(p.kids, out);
  }
  return out;
}

/** The popups a designed block's links open, drawn once as hidden dialogs (components/actions.tsx opens them). */
async function Popups({ parts, cms }: { parts: Part[]; cms?: string }) {
  const ids = [...popupIds(parts)];
  if (!ids.length) return null;
  const { door } = await import("@/lib/cms");
  const found = await Promise.all(ids.map((id) => door.part("popup", { id }).catch(() => null)));
  return (
    <>
      {found.map((p: Raw, i) =>
        p?.parts?.length ? (
          <dialog key={ids[i]} className="cnh-popup" data-popup={ids[i]} aria-label="Dialog">
            <button type="button" className="cnh-popup-close" aria-label="Close" data-popup-close="">
              ×
            </button>
            <Assets assets={p.assets} cms={cms} />
            <Styles css={p.css} scope={p.scope} />
            <div className={(p.scope || ".cnh-el").replace(/^\./, "")}>
              <Parts parts={p.parts} />
            </div>
          </dialog>
        ) : null,
      )}
    </>
  );
}

/** A designed page or site part: its styles, the widgets' stylesheets and scripts, and its parts under the scope class. */
export function Designed({ parts, css, scope, assets, scripts, cms, as: Tag = "div", className }: { parts: Part[]; css?: string; scope?: string; assets?: string[]; scripts?: string[]; cms?: string; as?: ElementType; className?: string }) {
  const s = (scope || ".cnh-el").replace(/^\./, "");
  return (
    <>
      <Assets assets={assets} cms={cms} />
      <Scripts scripts={scripts} cms={cms} />
      <Styles css={css} scope={scope} />
      <Popups parts={parts} cms={cms} />
      <Tag className={[s, className].filter(Boolean).join(" ")}>
        <Parts parts={parts} />
      </Tag>
    </>
  );
}

export function Parts({ parts, group }: { parts: Part[]; group?: string }) {
  return (
    <>
      {parts.map((part, i) => (
        <One key={i} part={part} group={group} />
      ))}
    </>
  );
}

/** A part the page builder shows only on some screens is wrapped so the same screens hide it here. */
function One({ part, group }: { part: Part; group?: string }) {
  const hide: string[] = (part.hide ?? []).filter((on: string) => ["desktop", "tablet", "mobile"].includes(on));
  return hide.length ? (
    <div className={hide.map((on) => `hide-${on}`).join(" ")}>
      <Drawn part={part} group={group} />
    </div>
  ) : (
    <Drawn part={part} group={group} />
  );
}

function Drawn({ part, group }: { part: Part; group?: string }) {
  switch (part.t) {
    case "box": {
      const Tag = (BOX_TAGS.has(part.el ?? "") ? part.el : "div") as ElementType;
      const kids = <Parts parts={part.kids ?? []} group={part.base === "e-accordion" && part.one ? `acc-${part.cls?.[0] ?? "x"}` : group} />;
      const props: Record<string, unknown> = { className: cx(part, part.base && `${part.base}-base`), id: part.id, ...named(part) };
      // Questions of one accordion share a name, so the browser keeps only one open.
      if (Tag === "details") Object.assign(props, { open: part.open || undefined, name: group });
      return part.link && Tag === "div" ? (
        <To to={part.link} className={props.className as string} attrs={named(part)}>
          {kids}
        </To>
      ) : (
        <Tag {...props}>{kids}</Tag>
      );
    }
    case "h":
    case "p": {
      const Tag = (TEXT_TAGS.has(part.el ?? "") ? part.el : part.t === "h" ? "h2" : "p") as ElementType;
      const base = part.t === "h" ? "e-heading" : "e-paragraph";
      const style = part.align && ["left", "center", "right"].includes(part.align) ? { textAlign: part.align as "left" | "center" | "right" } : undefined;
      const words = <span dangerouslySetInnerHTML={{ __html: part.html ?? "" }} />;
      return (
        <Tag className={cx(part, `${base}-base`)} id={part.id} style={style} {...named(part)}>
          {part.link ? (
            <To to={part.link} className={`${base}-link-base`}>
              {words}
            </To>
          ) : (
            words
          )}
        </Tag>
      );
    }
    case "btn": {
      const html = (part.icon ? `<i class="ico">${part.icon}</i>` : "") + (part.html ?? "");
      const className = cx(part, "e-button-base");
      const paint = part.bg || part.ink ? { backgroundColor: colour(part.bg), color: colour(part.ink) } : undefined;
      const wrap = (node: ReactNode) => (part.align ? <div style={{ textAlign: part.align as "left" | "center" | "right" }}>{node}</div> : node);
      return wrap(
        part.link ? (
          <To to={part.link} className={className} style={paint} attrs={named(part)}>
            <span dangerouslySetInnerHTML={{ __html: html }} />
          </To>
        ) : (
          <span className={className} style={paint} dangerouslySetInnerHTML={{ __html: html }} />
        ),
      );
    }
    case "img": {
      if (!part.src) return null;
      const img = (
        <img {...(part.link ? {} : named(part))} className={cx(part, "e-image-base")} src={part.src} srcSet={part.srcset || undefined} sizes={part.srcset ? `(max-width: 767px) 100vw, ${part.width || 600}px` : undefined} alt={part.alt ?? ""} width={part.width || undefined} height={part.height || undefined} loading="lazy" decoding="async" />
      );
      return part.link ? (
        <To to={part.link} className="e-image-link-base" attrs={named(part)}>
          {img}
        </To>
      ) : (
        img
      );
    }
    case "svg": {
      const inner = part.html ? (
        <span className={cx(part, "e-svg-base")} aria-hidden="true" dangerouslySetInnerHTML={{ __html: part.html }} />
      ) : part.src ? (
        <img className={cx(part, "e-svg-base")} src={part.src} alt="" loading="lazy" />
      ) : null;
      return inner && part.link ? (
        <To to={part.link} attrs={named(part)}>
          {inner}
        </To>
      ) : (
        inner
      );
    }
    case "yt":
      return part.video && /^[A-Za-z0-9_-]+$/.test(part.video) ? (
        <div className={cx(part, "e-youtube-base")}>
          <iframe src={`https://www.youtube-nocookie.com/embed/${part.video}?rel=0`} title="Video" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" referrerPolicy="strict-origin-when-cross-origin" allowFullScreen />
        </div>
      ) : null;
    case "hr":
      return <hr className={cx(part, "e-divider-base")} />;
    case "rich":
      return <div className={cx(part, "rich")} dangerouslySetInnerHTML={{ __html: part.html ?? "" }} />;
    case "list":
      return (
        <ul className={cx(part, part.inline ? "plain-list plain-list-row" : "plain-list")} style={{ color: colour(part.ink) }}>
          {(part.items ?? []).map((item: Raw, i: number) => {
            const inner = (
              <>
                {item.icon ? <i className="ico" style={{ color: colour(part.tint) }} aria-hidden="true" dangerouslySetInnerHTML={{ __html: item.icon }} /> : null}
                <span dangerouslySetInnerHTML={{ __html: item.html ?? "" }} />
              </>
            );
            return <li key={i}>{item.link ? <To to={item.link}>{inner}</To> : inner}</li>;
          })}
        </ul>
      );
    case "ibox": {
      const inner = (
        <>
          {part.picture ? <img src={part.picture.src} alt={part.picture.alt ?? ""} width={part.picture.width} height={part.picture.height} loading="lazy" /> : null}
          {part.icon ? <i className="ico" aria-hidden="true" dangerouslySetInnerHTML={{ __html: part.icon }} /> : null}
          {part.title ? <h3 dangerouslySetInnerHTML={{ __html: part.title }} /> : null}
          {part.html ? <div dangerouslySetInnerHTML={{ __html: part.html }} /> : null}
        </>
      );
      return part.link ? (
        <To to={part.link} className={cx(part, "ibox")}>
          {inner}
        </To>
      ) : (
        <div className={cx(part, "ibox")}>{inner}</div>
      );
    }
    case "quote":
      return (
        <figure className={cx(part, "quote")}>
          <blockquote dangerouslySetInnerHTML={{ __html: part.html ?? "" }} />
          <figcaption>
            {part.picture ? <img src={part.picture.src} alt="" width={48} height={48} loading="lazy" /> : null}
            <b>{part.name}</b>
            {part.role ? <span>{part.role}</span> : null}
          </figcaption>
        </figure>
      );
    case "cards":
      // Lists inside a page come as the door's cards: titles linked, nothing more until the site gives them a look.
      return (
        <ul className={cx(part, "cards")}>
          {(part.items ?? []).map((c: Raw) => (
            <li key={c.id ?? c.path}>
              <Link href={c.path}>{c.title}</Link>
            </li>
          ))}
        </ul>
      );
    case "form":
      return part.form ? <FormBox form={part.form} /> : null;
    case "x":
      // A widget the plugin does not know: its own markup, as the CMS draws it.
      return part.html ? <div className={cx(part, `x-${String(part.widget ?? "").replace(/[^a-z0-9-]/gi, "")}`)} dangerouslySetInnerHTML={{ __html: part.html }} /> : null;
    default:
      return null;
  }
}
