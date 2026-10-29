"use client";

import { useCallback, useEffect, useLayoutEffect, useRef, useState, type ReactNode } from "react";
import { Anchor } from "./link";
import { ArrowUpRight, Bars, ChevronDown, Close, Moon, Sun } from "./icons";
import type { Brand, LinkAs, MenuItem } from "./types";

export type SiteHeaderProps = {
  brand: Brand;
  /** The small line at the left of the top bar. */
  tagline?: string;
  /** The top bar's links. An entry with the class "arrow" (set in WordPress) carries a small arrow. */
  utility?: MenuItem[];
  /**
   * The main menu. An entry with children opens a panel: columns when the children have children of their own,
   * otherwise a list beside a picture (see panelMedia).
   */
  menu: MenuItem[];
  cta?: { label: string; href: string };
  /** The closing line of a panel that has columns. */
  panelFooter?: { text: string; label: string; href: string };
  /** The picture and its words for a list panel, by the address of the menu entry that opens it. */
  panelMedia?: Record<string, { src: string; alt: string; caption: string }>;
  mobileTitle?: string;
  mobileText?: string;
  /** The host's own buttons beside the light/dark switch (a search button, for example). */
  tools?: ReactNode;
  linkAs?: LinkAs;
};

function ThemeToggle() {
  const [dark, setDark] = useState(false);
  // The class is set before React starts (themeScript); read it once the page is live.
  useEffect(() => setDark(document.documentElement.classList.contains("cn-dark")), []);
  const flip = () => {
    const next = !document.documentElement.classList.contains("cn-dark");
    document.documentElement.classList.toggle("cn-dark", next);
    try {
      localStorage.setItem("cn-theme", next ? "dark" : "light");
    } catch {}
    setDark(next);
  };
  return (
    <button type="button" className="cnu-icon-button cnu-theme" onClick={flip} aria-label={dark ? "Switch to light mode" : "Switch to dark mode"} aria-pressed={dark}>
      <Moon />
      <Sun />
    </button>
  );
}

export function BrandMark({ brand, linkAs, className }: { brand: Brand; linkAs?: LinkAs; className?: string }) {
  return (
    <Anchor as={linkAs} href={brand.href} className={`cnu-brand ${className ?? ""}`} label={`${brand.small} ${brand.name}`.trim()}>
      {brand.mark ? <span className="cnu-brand-mark">{brand.mark}</span> : null}
      <span className="cnu-brand-text">
        <span className="cnu-brand-small">{brand.small}</span>
        <span className="cnu-brand-name">
          {brand.name}
          <i>.</i>
        </span>
      </span>
    </Anchor>
  );
}

export function SiteHeader({ brand, tagline, utility = [], menu, cta, panelFooter, panelMedia = {}, mobileTitle = "Menu", mobileText, tools, linkAs }: SiteHeaderProps) {
  const [open, setOpen] = useState<number | null>(null);
  const [place, setPlace] = useState<{ left: number; top: number } | null>(null);
  const root = useRef<HTMLElement>(null);
  const popup = useRef<HTMLDivElement>(null);
  const drawer = useRef<HTMLDialogElement>(null);
  const close = useCallback(() => {
    setOpen(null);
    if (drawer.current?.open) drawer.current.close();
  }, []);

  // A panel hangs under its menu entry, its right edge on the entry's right edge, and never leaves the window.
  const position = useCallback(() => {
    const trigger = root.current?.querySelector<HTMLElement>(`[data-cnu-menu="${open}"]`);
    if (!trigger || !popup.current) return;
    const t = trigger.getBoundingClientRect();
    const width = popup.current.offsetWidth;
    setPlace({ left: Math.max(5, Math.min(t.right - width, window.innerWidth - width - 5)), top: t.bottom + 8 });
  }, [open]);
  useLayoutEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the panel can only be measured once it is on the page
    if (open !== null) position();
  }, [open, position]);

  useEffect(() => {
    const key = (e: KeyboardEvent) => e.key === "Escape" && setOpen(null);
    const click = (e: MouseEvent) => {
      if (root.current && !root.current.contains(e.target as Node)) setOpen(null);
    };
    document.addEventListener("keydown", key);
    document.addEventListener("mousedown", click);
    window.addEventListener("resize", position);
    window.addEventListener("scroll", position, { passive: true });
    return () => {
      document.removeEventListener("keydown", key);
      document.removeEventListener("mousedown", click);
      window.removeEventListener("resize", position);
      window.removeEventListener("scroll", position);
    };
  }, [position]);

  const panel = menu.find((m) => m.id === open);
  const columns = panel ? panel.children.some((c) => c.children.length > 0) : false;
  const media = panel ? panelMedia[panel.href] : undefined;

  return (
    <header className="cnu-header" ref={root}>
      {tagline || utility.length ? (
        <div className="cnu-utility">
          <div className="cnu-utility-in">
            <p>{tagline}</p>
            <nav aria-label="Quick links">
              {utility.map((u) => (
                <Anchor key={u.id} as={linkAs} href={u.href} newTab={u.newTab}>
                  {u.title}
                  {u.classes.includes("arrow") ? <ArrowUpRight /> : null}
                </Anchor>
              ))}
            </nav>
          </div>
        </div>
      ) : null}

      <div className="cnu-bar">
        <div className="cnu-bar-in">
          <BrandMark brand={brand} linkAs={linkAs} />
          <nav className="cnu-nav" aria-label="Main navigation">
            {menu.map((m) =>
              m.children.length ? (
                <button key={m.id} type="button" className="cnu-nav-item" data-cnu-menu={m.id} aria-expanded={open === m.id} aria-controls={`cnu-panel-${m.id}`} onClick={() => setOpen(open === m.id ? null : m.id)}>
                  {m.title}
                  <ChevronDown />
                </button>
              ) : (
                <Anchor key={m.id} as={linkAs} href={m.href} newTab={m.newTab} className="cnu-nav-item" onClick={close}>
                  {m.title}
                </Anchor>
              ),
            )}
          </nav>
          <div className="cnu-tools">
            {tools}
            <ThemeToggle />
          </div>
          {cta ? (
            <Anchor as={linkAs} href={cta.href} className="cnu-button cnu-header-cta" onClick={close}>
              {cta.label}
              <ArrowUpRight />
            </Anchor>
          ) : null}
          <button type="button" className="cnu-burger" aria-label="Open navigation" aria-haspopup="dialog" onClick={() => drawer.current?.showModal()}>
            <Bars />
          </button>
        </div>
      </div>

      {panel ? (
        <div className="cnu-popup" id={`cnu-panel-${panel.id}`} ref={popup} style={place ? { left: place.left, top: place.top } : { left: 5, top: 0, visibility: "hidden" }}>
          {columns ? (
            <div className="cnu-mega">
              <div className="cnu-mega-top">
                <span className="cnu-popup-eyebrow">{panel.description}</span>
                {panel.href && panel.href !== "#" ? (
                  <Anchor as={linkAs} href={panel.href} onClick={close}>
                    {panel.note || panel.title}
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden>
                      <path d="M5 12h14m-6-6 6 6-6 6" />
                    </svg>
                  </Anchor>
                ) : null}
              </div>
              <div className="cnu-mega-grid">
                {panel.children.map((col) => (
                  <div key={col.id}>
                    <h3>{col.title}</h3>
                    {col.description ? <p>{col.description}</p> : null}
                    {col.children.map((l) => (
                      <Anchor key={l.id} as={linkAs} href={l.href} newTab={l.newTab} onClick={close}>
                        {l.title}
                        <ArrowUpRight />
                      </Anchor>
                    ))}
                  </div>
                ))}
              </div>
              {panelFooter ? (
                <div className="cnu-mega-bottom">
                  <span>{panelFooter.text}</span>
                  <Anchor as={linkAs} href={panelFooter.href} onClick={close}>
                    {panelFooter.label}
                    <ArrowUpRight />
                  </Anchor>
                </div>
              ) : null}
            </div>
          ) : (
            <div className={`cnu-list-menu ${media ? "" : "cnu-list-menu-plain"}`}>
              {media ? (
                <div className="cnu-list-menu-image">
                  {/* eslint-disable-next-line @next/next/no-img-element -- the library is not tied to one framework's image component */}
                  <img src={media.src} alt={media.alt} loading="lazy" decoding="async" />
                  <span>{media.caption}</span>
                </div>
              ) : null}
              <div>
                <p className="cnu-popup-eyebrow">{panel.description}</p>
                {panel.children.map((l) => (
                  <Anchor key={l.id} as={linkAs} href={l.href} newTab={l.newTab} onClick={close}>
                    {l.title}
                    <ArrowUpRight />
                  </Anchor>
                ))}
                {panel.href && panel.href !== "#" ? (
                  <Anchor as={linkAs} href={panel.href} className="cnu-list-menu-all" onClick={close}>
                    {panel.note || panel.title}
                    <ArrowUpRight />
                  </Anchor>
                ) : null}
              </div>
            </div>
          )}
        </div>
      ) : null}

      <dialog className="cnu-drawer" ref={drawer} aria-label={mobileTitle} onClick={(e) => e.target === drawer.current && close()}>
        <button type="button" className="cnu-drawer-close" aria-label="Close navigation" onClick={close}>
          <Close />
        </button>
        <h2>{mobileTitle}</h2>
        {mobileText ? <p>{mobileText}</p> : null}
        <div className="cnu-drawer-links">
          {menu.map((m) => (
            <Anchor key={m.id} as={linkAs} href={m.href} onClick={close}>
              {m.children.some((c) => c.children.length) ? `All ${m.title.toLowerCase()}` : m.title}
            </Anchor>
          ))}
          {cta ? (
            <Anchor as={linkAs} href={cta.href} onClick={close}>
              Contact
            </Anchor>
          ) : null}
          {utility
            .filter((u) => u.href.startsWith("/") && !/^\/login\/?$/.test(u.href))
            .map((u) => (
              <Anchor key={u.id} as={linkAs} href={u.href} onClick={close}>
                {u.title}
              </Anchor>
            ))}
        </div>
        {menu
          .flatMap((m) => m.children)
          .filter((c) => c.children.length)
          .map((group) => (
            <div className="cnu-drawer-group" key={group.id}>
              <h3>{group.title}</h3>
              {group.children.map((l) => (
                <Anchor key={l.id} as={linkAs} href={l.href} onClick={close}>
                  {l.title}
                  <ArrowUpRight />
                </Anchor>
              ))}
            </div>
          ))}
        {cta ? (
          <Anchor as={linkAs} href={cta.href} className="cnu-drawer-cta" onClick={close}>
            {cta.label}
            <ArrowUpRight />
          </Anchor>
        ) : null}
      </dialog>
    </header>
  );
}
