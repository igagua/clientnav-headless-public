import type { ReactNode } from "react";
import { ArrowDown, ArrowUpRight } from "./icons";
import { ArticleCard, Img } from "./content";
import type { Block, Card, LinkAs, Picture } from "./types";
import { RoadmapControls } from "./RoadmapControls";
import { ROADMAP_ICONS, STATUS, type Status } from "./roadmap-icons";
import { CARD_ICONS } from "./card-icons";

const CardIcon = ({ name }: { name?: unknown }) =>
  typeof name === "string" && CARD_ICONS[name] ? (
    <svg className="cnu-b-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden dangerouslySetInnerHTML={{ __html: CARD_ICONS[name] }} />
  ) : null;

/**
 * The page blocks. Each is drawn in two places from the same code: by the front end, with the saved
 * values, and inside the WordPress editor, where every text value is an editable field.
 * So text values arrive as ready nodes (`ReactNode`), never as strings to be parsed here.
 *
 * `blocks/<name>/block.json` beside this folder is the contract: attribute names there are the keys of `a` here.
 */
export type LinkSlot = (p: { href: string; className?: string; children?: ReactNode }) => ReactNode;
export type InnerSlot = (p: { className: string }) => ReactNode;
/* eslint-disable @typescript-eslint/no-explicit-any */
export type BlockProps = {
  /** Attributes: text values as nodes (null when empty), addresses as strings, pictures as Picture or null. */
  a: Record<string, any>;
  Link: LinkSlot;
  /** Where the block's child blocks go; the host decides what is drawn there. */
  Inner: InnerSlot;
  /** The host's form, for blocks that can carry one. Without it they show a button or the plain details instead. */
  Form?: (p: { subject?: string }) => ReactNode;
  /** The child blocks' plain values, for a block that counts or sums them (the roadmap). */
  inner?: Pick<Block, "name" | "attrs" | "innerBlocks">[];
  /** Extra attributes for the outer element (the editor needs them to select the block). */
  root?: Record<string, any>;
  linkAs?: LinkAs;
};

const at = (root: BlockProps["root"], className: string) => ({ ...root, className: [className, root?.className].filter(Boolean).join(" ") });

function Head({ a, level = "h2", className = "" }: { a: Record<string, any>; level?: "h1" | "h2"; className?: string }) {
  const H = level;
  return (
    <div className={`cnu-b-head ${className}`}>
      {a.eyebrow ? <p className="cnu-eyebrow">{a.eyebrow}</p> : null}
      <H>
        {a.title}
        {a.accent ? <span className="cnu-accent">{a.accent}</span> : null}
      </H>
    </div>
  );
}

const Pic = ({ picture, sizes, eager }: { picture?: Picture | null; sizes: string; eager?: boolean }) => (picture?.src ? <Img picture={picture} sizes={sizes} eager={eager} /> : <span className="cnu-b-nopic" aria-hidden />);

export function Hero({ a, Link, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-hero")}>
      <div className="cnu-wrap cnu-b-hero-in">
        <div className="cnu-b-hero-copy">
          <Head a={a} level="h1" />
          {a.text ? <p className="cnu-b-hero-text">{a.text}</p> : null}
          <div className="cnu-b-actions">
            {a.buttonLabel ? (
              <Link href={a.buttonHref} className="cnu-button">
                {a.buttonLabel}
                <ArrowUpRight />
              </Link>
            ) : null}
            {a.linkLabel ? (
              <Link href={a.linkHref} className="cnu-text-link">
                {a.linkLabel}
                {typeof a.linkHref === "string" && a.linkHref.startsWith("#") ? <ArrowDown /> : null}
              </Link>
            ) : null}
          </div>
          {a.badgeTitle || a.badgeText ? (
            <p className="cnu-b-badge">
              <strong>{a.badgeTitle}</strong>
              <span>{a.badgeText}</span>
            </p>
          ) : null}
        </div>
        <figure className="cnu-b-hero-media">
          <Pic picture={a.image} sizes="(max-width: 1000px) 100vw, 640px" eager />
          {a.mediaEyebrow ? <span className="cnu-b-hero-label">{a.mediaEyebrow}</span> : null}
          <figcaption>
            <span className="cnu-b-hero-caption">
              {a.mediaTitle}
              {a.mediaAccent ? <span>{a.mediaAccent}</span> : null}
            </span>
            {a.mediaNote ? <span className="cnu-b-hero-note">{a.mediaNote}</span> : null}
          </figcaption>
        </figure>
      </div>
    </section>
  );
}

export function Strip({ a, root }: BlockProps) {
  const items = [a.one, a.two, a.three, a.four].filter(Boolean);
  return (
    <section {...at(root, "cnu-b-strip")}>
      <div className="cnu-wrap cnu-b-strip-in">
        {items.map((t, i) => (
          <p key={i}>{t}</p>
        ))}
      </div>
    </section>
  );
}

export function Cards({ a, Link, Inner, root }: BlockProps) {
  return (
    <section {...at(root, `cnu-b-cards cnu-b-cards-${a.variant === "compact" ? "compact" : a.variant === "tiles" ? "service cnu-b-cards-tiles" : "service"}`)} id={a.anchor || undefined}>
      <div className="cnu-wrap">
        <div className="cnu-b-split">
          <Head a={a} />
          {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
        </div>
        <Inner className="cnu-b-cards-grid" />
        {a.moreLabel ? (
          <p className="cnu-b-more">
            <Link href={a.moreHref} className="cnu-text-link">
              {a.moreLabel}
              <ArrowUpRight />
            </Link>
          </p>
        ) : null}
      </div>
    </section>
  );
}

export function CardItem({ a, Link, root }: BlockProps) {
  return (
    <article {...at(root, "cnu-b-card")}>
      <div className="cnu-b-card-image">
        <Pic picture={a.image} sizes="(max-width: 640px) 100vw, (max-width: 1000px) 50vw, 410px" />
      </div>
      <div className="cnu-b-card-body">
        <div className="cnu-b-card-top">
          <CardIcon name={a.icon} />
          <span className="cnu-b-number" aria-hidden />
        </div>
        <h3>
          <Link href={a.href}>{a.title}</Link>
        </h3>
        {a.text ? <p className="cnu-b-card-text">{a.text}</p> : null}
        {a.tags ? <p className="cnu-b-card-tags">{a.tags}</p> : null}
        {a.linkLabel ? (
          <Link href={a.href} className="cnu-text-link cnu-b-card-link">
            {a.linkLabel}
            <ArrowUpRight />
          </Link>
        ) : null}
      </div>
    </article>
  );
}

export function Feature({ a, Link, Inner, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-feature")}>
      <div className="cnu-wrap cnu-b-feature-in">
        <div>
          <Head a={a} />
          {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
          {a.buttonLabel ? (
            <Link href={a.buttonHref} className="cnu-button cnu-button-light">
              {a.buttonLabel}
              <ArrowUpRight />
            </Link>
          ) : null}
        </div>
        <div>
          <div className="cnu-b-feature-image">
            <Pic picture={a.image} sizes="(max-width: 1000px) 100vw, 600px" />
          </div>
          <Inner className="cnu-b-points" />
        </div>
      </div>
    </section>
  );
}

export function Point({ a, root }: BlockProps) {
  return (
    <div {...at(root, "cnu-b-point")}>
      <span className="cnu-b-number" aria-hidden />
      <div>
        <h3>{a.title}</h3>
        {a.text ? <p>{a.text}</p> : null}
      </div>
    </div>
  );
}

/** `a.cards` is filled by the host with the newest articles. */
export function Posts({ a, Link, root, linkAs }: BlockProps) {
  const cards: Card[] = Array.isArray(a.cards) ? a.cards : [];
  return (
    <section {...at(root, "cnu-b-posts")}>
      <div className="cnu-wrap">
        <div className="cnu-b-split cnu-b-split-end">
          <Head a={a} />
          {a.linkLabel ? (
            <Link href={a.linkHref} className="cnu-text-link">
              {a.linkLabel}
              <ArrowUpRight />
            </Link>
          ) : null}
        </div>
        <div className="cnu-grid">
          {cards.map((c) => (
            <ArticleCard key={c.id} card={c} linkAs={linkAs} />
          ))}
        </div>
      </div>
    </section>
  );
}

export function Promo({ a, Link, Inner, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-promo")}>
      <div className="cnu-wrap cnu-b-promo-in">
        <div>
          {a.eyebrow ? <p className="cnu-eyebrow cnu-eyebrow-tint">{a.eyebrow}</p> : null}
          <h2>{a.title}</h2>
          {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
          <div className="cnu-b-actions">
            {a.buttonLabel ? (
              <Link href={a.buttonHref} className="cnu-button">
                {a.buttonLabel}
              </Link>
            ) : null}
            {a.secondLabel ? (
              <Link href={a.secondHref} className="cnu-button cnu-button-outline">
                {a.secondLabel}
              </Link>
            ) : null}
          </div>
          {a.note ? <p className="cnu-b-note">{a.note}</p> : null}
        </div>
        <Inner className="cnu-b-promo-links" />
      </div>
    </section>
  );
}

export function PromoLink({ a, Link, root }: BlockProps) {
  return (
    <div {...at(root, "cnu-b-promo-link")}>
      <Link href={a.href}>
        <span>
          {a.label ? <span className="cnu-eyebrow cnu-eyebrow-tint">{a.label}</span> : null}
          <strong>{a.title}</strong>
        </span>
        <span aria-hidden>→</span>
      </Link>
    </div>
  );
}

export function Quote({ a, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-quote")}>
      <div className="cnu-wrap">
        <figure>
          <span className="cnu-b-quote-mark" aria-hidden>
            “
          </span>
          <div>
            {a.eyebrow ? <p className="cnu-eyebrow">{a.eyebrow}</p> : null}
            <blockquote>{a.quote}</blockquote>
            <figcaption>
              {a.image?.src ? <Img picture={a.image} /> : null}
              <span>
                <strong>{a.name}</strong>
                <span>{a.role}</span>
              </span>
            </figcaption>
          </div>
        </figure>
      </div>
    </section>
  );
}

export function Band({ a, Link, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-band")}>
      <div className="cnu-wrap cnu-band-in">
        <Head a={a} />
        <div>
          {a.text ? <p>{a.text}</p> : null}
          <div className="cnu-band-actions">
            {a.buttonLabel ? (
              <Link href={a.buttonHref} className="cnu-button cnu-button-light">
                {a.buttonLabel}
                <ArrowUpRight />
              </Link>
            ) : null}
            {a.noteLabel ? (
              <Link href={a.noteHref} className="cnu-band-note">
                {a.noteLabel}
              </Link>
            ) : null}
          </div>
        </div>
      </div>
    </section>
  );
}

/** `a.crumbs` (the path back to the home page) is filled by the host. */
export function PageHero({ a, Link, root }: BlockProps) {
  const crumbs: { title: string; href?: string }[] = Array.isArray(a.crumbs) ? a.crumbs : [];
  return (
    <section {...at(root, `cnu-b-phero ${a.image?.src ? "" : "cnu-b-phero-plain"}`)}>
      {crumbs.length ? (
        <div className="cnu-b-crumbs">
          <nav className="cnu-wrap" aria-label="Breadcrumb">
            {crumbs.map((c, i) => (
              <span key={i}>{c.href ? <Link href={c.href}>{c.title}</Link> : <span aria-current="page">{c.title}</span>}</span>
            ))}
          </nav>
        </div>
      ) : null}
      <div className="cnu-b-phero-body">
        <div className="cnu-wrap cnu-b-phero-in">
          <div>
            <Head a={a} level="h1" />
            {a.text ? <p className="cnu-b-phero-text">{a.text}</p> : null}
            {a.buttonLabel || a.linkLabel ? (
              <div className="cnu-b-actions">
                {a.buttonLabel ? (
                  <Link href={a.buttonHref} className="cnu-button">
                    {a.buttonLabel}
                  </Link>
                ) : null}
                {a.linkLabel ? (
                  <Link href={a.linkHref} className="cnu-text-link">
                    {a.linkLabel}
                    {typeof a.linkHref === "string" && a.linkHref.startsWith("#") ? <ArrowDown /> : null}
                  </Link>
                ) : null}
              </div>
            ) : null}
          </div>
          {a.image?.src || root ? (
            <figure className="cnu-b-phero-media">
              <Pic picture={a.image} sizes="(max-width: 1000px) 100vw, 570px" eager />
              {a.imageLabel ? <figcaption>{a.imageLabel}</figcaption> : null}
            </figure>
          ) : null}
        </div>
      </div>
    </section>
  );
}

export function Subnav({ a, Link, Inner, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-subnav")}>
      <div className="cnu-wrap cnu-b-subnav-in">
        {a.title ? <p className="cnu-b-subnav-title">{a.title}</p> : null}
        <Inner className="cnu-b-subnav-links" />
        {a.ctaLabel ? (
          <Link href={a.ctaHref} className="cnu-text-link cnu-b-subnav-cta">
            {a.ctaLabel}
            <ArrowUpRight />
          </Link>
        ) : null}
      </div>
    </section>
  );
}

export function SubnavLink({ a, Link, root }: BlockProps) {
  return (
    <span {...at(root, "cnu-b-subnav-link")}>
      <Link href={a.href}>{a.label}</Link>
    </span>
  );
}

export function Overview({ a, Link, Inner, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-overview")} id={a.anchor || undefined}>
      <div className="cnu-wrap cnu-b-overview-in">
        <div>
          <Head a={a} />
          {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
          {a.image?.src || root ? (
            <figure className="cnu-b-overview-media">
              <Pic picture={a.image} sizes="(max-width: 1000px) 100vw, 600px" />
              {a.caption ? <figcaption>{a.caption}</figcaption> : null}
            </figure>
          ) : null}
        </div>
        <div className="cnu-b-checks">
          {a.listTitle ? <p className="cnu-eyebrow">{a.listTitle}</p> : null}
          <Inner className="cnu-b-checks-list" />
          {a.buttonLabel ? (
            <Link href={a.buttonHref} className="cnu-button cnu-b-checks-button">
              {a.buttonLabel}
              <ArrowUpRight />
            </Link>
          ) : null}
        </div>
      </div>
    </section>
  );
}

export function Check({ a, root }: BlockProps) {
  return <p {...at(root, "cnu-b-check")}>{a.text}</p>;
}

export function Points({ a, Inner, root }: BlockProps) {
  return (
    <section {...at(root, `cnu-b-pointset cnu-b-pointset-${a.variant === "dark" ? "dark" : "grid"}`)} id={a.anchor || undefined}>
      <div className="cnu-wrap">
        {a.title || a.eyebrow || a.text ? (
          <div className="cnu-b-split">
            <Head a={a} />
            {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
          </div>
        ) : null}
        <Inner className="cnu-b-pointset-list" />
      </div>
    </section>
  );
}

export function Faq({ a, Link, Inner, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-faq")} id={a.anchor || undefined}>
      <div className="cnu-wrap cnu-b-faq-in">
        <div>
          <Head a={a} />
          {a.asideText ? <p className="cnu-b-faq-aside">{a.asideText}</p> : null}
          {a.asideLabel ? (
            <Link href={a.asideHref} className="cnu-b-faq-link">
              {a.asideLabel}
            </Link>
          ) : null}
        </div>
        <Inner className="cnu-b-faq-list" />
      </div>
    </section>
  );
}

/** Open while it is being edited, so the answer can be typed. */
export function FaqItem({ a, root }: BlockProps) {
  if (root) {
    return (
      <div {...at(root, "cnu-b-faq-item")}>
        <div className="cnu-b-faq-q">{a.question}</div>
        <div className="cnu-b-faq-a">{a.answer}</div>
      </div>
    );
  }
  return (
    <details className="cnu-b-faq-item">
      <summary className="cnu-b-faq-q">{a.question}</summary>
      <div className="cnu-b-faq-a">{a.answer}</div>
    </details>
  );
}

export function Inquiry({ a, Link, Form, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-inquiry")} id={a.anchor || undefined}>
      <div className="cnu-wrap cnu-b-inquiry-in">
        <div>
          <Head a={a} />
          {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
          {Form && a.phoneLabel ? (
            <Link href={a.phoneHref} className="cnu-b-inquiry-phone">
              {a.phoneLabel}
            </Link>
          ) : null}
          {Form && a.topic ? <p className="cnu-b-inquiry-topic">{a.topic}</p> : null}
        </div>
        <div className="cnu-b-inquiry-card">
          {Form ? (
            <Form subject={typeof a.subject === "string" ? a.subject : undefined} />
          ) : (
            <>
              {a.buttonLabel ? (
                <Link href={a.buttonHref} className="cnu-button">
                  {a.buttonLabel}
                  <ArrowUpRight />
                </Link>
              ) : null}
              {a.phoneLabel ? (
                <Link href={a.phoneHref} className="cnu-b-inquiry-phone">
                  {a.phoneLabel}
                </Link>
              ) : null}
              {a.topic ? <p className="cnu-b-inquiry-topic">{a.topic}</p> : null}
            </>
          )}
        </div>
      </div>
    </section>
  );
}

export function Rows({ Inner, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-rows")}>
      <div className="cnu-wrap">
        <Inner className="cnu-b-rows-list" />
      </div>
    </section>
  );
}

export function Row({ a, Link, root }: BlockProps) {
  return (
    <article {...at(root, "cnu-b-row")}>
      <div className="cnu-b-row-image">
        <Pic picture={a.image} sizes="(max-width: 1000px) 100vw, 620px" />
      </div>
      <div className="cnu-b-row-body">
        <p className="cnu-eyebrow">
          <span className="cnu-b-number" aria-hidden />
          {a.eyebrow}
        </p>
        <h2>{a.title}</h2>
        {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
        {a.linkLabel ? (
          <Link href={a.href} className="cnu-text-link">
            {a.linkLabel}
            <ArrowUpRight />
          </Link>
        ) : null}
      </div>
    </article>
  );
}

export function Statement({ a, Inner, root }: BlockProps) {
  return (
    <section {...at(root, `cnu-b-statement cnu-b-statement-${a.variant === "split" ? "split" : "center"}`)} id={a.anchor || undefined}>
      <div className="cnu-wrap">
        <div className="cnu-b-statement-top">
          <Head a={a} />
          {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
        </div>
        <Inner className="cnu-b-statement-pictures" />
      </div>
    </section>
  );
}

export function CaptionedPicture({ a, root }: BlockProps) {
  return (
    <figure {...at(root, "cnu-b-picture")}>
      <Pic picture={a.image} sizes="(max-width: 800px) 100vw, 640px" />
      {a.caption ? <figcaption>{a.caption}</figcaption> : null}
    </figure>
  );
}

export function Banner({ a, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-banner")}>
      <Pic picture={a.image} sizes="100vw" />
      <div className="cnu-wrap">
        {a.eyebrow ? <p className="cnu-eyebrow">{a.eyebrow}</p> : null}
        <p className="cnu-b-banner-words">
          {a.title}
          {a.accent ? <span>{a.accent}</span> : null}
        </p>
      </div>
    </section>
  );
}

/** Handed a path line by the host (as the first block of a page), it is the page's opening and carries the page title. */
export function Info({ a, Link, Inner, Form, root }: BlockProps) {
  const form = a.form === "inquiry" && Form;
  const crumbs: { title: string; href?: string }[] = Array.isArray(a.crumbs) ? a.crumbs : [];
  const opening = crumbs.length > 0;
  return (
    <section {...at(root, `cnu-b-info ${form ? "cnu-b-info-form" : ""} ${opening ? "cnu-b-info-opening" : ""}`)} id={a.anchor || undefined}>
      {opening ? (
        <div className="cnu-b-crumbs">
          <nav className="cnu-wrap" aria-label="Breadcrumb">
            {crumbs.map((c, i) => (
              <span key={i}>{c.href ? <Link href={c.href}>{c.title}</Link> : <span aria-current="page">{c.title}</span>}</span>
            ))}
          </nav>
        </div>
      ) : null}
      <div className="cnu-wrap cnu-b-info-in">
        <div>
          <Head a={a} level={opening ? "h1" : undefined} />
          {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
          {a.linkLabel && !form ? (
            <Link href={a.linkHref} className="cnu-text-link">
              {a.linkLabel}
              <ArrowUpRight />
            </Link>
          ) : null}
          {form ? <Inner className="cnu-b-ways" /> : null}
          {a.asideTitle || a.asideText ? (
            <div className="cnu-b-info-aside">
              {a.asideTitle ? <h2>{a.asideTitle}</h2> : null}
              {a.asideText ? <p>{a.asideText}</p> : null}
            </div>
          ) : null}
        </div>
        <div className="cnu-b-info-card">
          {a.cardTitle ? <h3>{a.cardTitle}</h3> : null}
          {a.cardText ? <p className="cnu-b-info-cardtext">{a.cardText}</p> : null}
          {form ? <Form /> : <Inner className="cnu-b-ways" />}
        </div>
      </div>
    </section>
  );
}

export function Way({ a, Link, root }: BlockProps) {
  return (
    <p {...at(root, "cnu-b-way")}>
      {a.label ? <span className="cnu-eyebrow">{a.label}</span> : null}
      <Link href={a.href}>{a.value}</Link>
    </p>
  );
}

export function Options({ a, Inner, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-options")}>
      <div className="cnu-wrap">
        {a.title || a.eyebrow ? <Head a={a} className="cnu-b-options-head" /> : null}
        <Inner className="cnu-b-options-list" />
        {a.image?.src || root ? (
          <div className="cnu-b-options-image">
            <Pic picture={a.image} sizes="(max-width: 1300px) 100vw, 1168px" />
          </div>
        ) : null}
      </div>
    </section>
  );
}

export function Option({ a, Link, root }: BlockProps) {
  return (
    <div {...at(root, "cnu-b-option")}>
      <CardIcon name={a.icon} />
      <h3>{a.title}</h3>
      {a.text ? <p>{a.text}</p> : null}
      {a.buttonLabel ? (
        <Link href={a.buttonHref} className="cnu-button">
          {a.buttonLabel}
          <ArrowUpRight />
        </Link>
      ) : null}
      {a.secondLabel ? (
        <Link href={a.secondHref} className="cnu-b-option-second">
          {a.secondLabel}
        </Link>
      ) : null}
      {a.note ? <p className="cnu-b-option-note">{a.note}</p> : null}
    </div>
  );
}

export function Plans({ a, Inner, inner = [], root }: BlockProps) {
  const n = Math.min(Math.max(inner.filter((b) => b.name === "clientnav/plan").length, 1), 4);
  return (
    <section {...at(root, `cnu-b-plans cnu-b-plans-n${n}`)} id={a.anchor || undefined}>
      <div className="cnu-wrap">
        {a.title || a.eyebrow ? (
          <div className="cnu-b-plans-head">
            <Head a={a} />
            {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
          </div>
        ) : null}
        <Inner className="cnu-b-plans-list" />
      </div>
    </section>
  );
}

export function Plan({ a, Link, Inner, root }: BlockProps) {
  const top = a.recommended === "yes";
  return (
    <div {...at(root, top ? "cnu-b-plan cnu-b-plan-top" : "cnu-b-plan")}>
      {top && a.mark ? <p className="cnu-b-plan-mark">{a.mark}</p> : null}
      <div>
        <h3>{a.title}</h3>
        {a.tagline ? <p className="cnu-b-plan-tagline">{a.tagline}</p> : null}
      </div>
      <div>
        <p className="cnu-b-plan-price">
          <span className="cnu-b-plan-amount">{a.price}</span>
          {a.period ? <span className="cnu-b-plan-period"> {a.period}</span> : null}
        </p>
        {a.included ? <p className="cnu-b-plan-included">{a.included}</p> : null}
      </div>
      {a.buttonLabel ? (
        <Link href={a.buttonHref} className={top ? "cnu-button cnu-b-plan-button" : "cnu-button cnu-button-outline cnu-b-plan-button"}>
          {a.buttonLabel}
        </Link>
      ) : null}
      <Inner className="cnu-b-plan-list" />
    </div>
  );
}

const COLUMNS = [1, 2, 3, 4, 5];
const heading = (v: unknown) => (typeof v === "string" ? v.trim() : "");

/** The column headings are plain values, so a cell can repeat its heading on a phone (CSS variables on the table). */
export function Compare({ a, Inner, root }: BlockProps) {
  const heads = COLUMNS.map((i) => heading(a[`col${i}`]));
  const n = Math.max(heads.reduce((last, h, i) => (h ? i + 1 : last), 0), 1);
  const vars = Object.fromEntries(heads.slice(0, n).map((h, i) => [`--cnu-cmp-h${i + 1}`, JSON.stringify(h)]));
  const hl = COLUMNS.includes(Number(a.highlight)) ? ` cnu-b-compare-hl${a.highlight}` : "";
  return (
    <section {...at(root, `cnu-b-compare cnu-b-compare-n${n}${hl}`)} id={a.anchor || undefined}>
      <div className="cnu-wrap">
        {a.title || a.eyebrow ? (
          <div className="cnu-b-compare-head">
            <Head a={a} />
            {a.text ? <p className="cnu-b-lead">{a.text}</p> : null}
          </div>
        ) : null}
        <div className="cnu-b-cmp" role="table" style={{ ...vars, "--cnu-cmp-n": n } as Record<string, string | number>}>
          <div className="cnu-b-cmp-row cnu-b-cmp-top" role="row">
            <span className="cnu-b-cmp-label" role="columnheader" />
            {heads.slice(0, n).map((h, i) => (
              <span key={i} className={`cnu-b-cmp-cell cnu-b-cmp-c${i + 1}`} role="columnheader">
                {h}
              </span>
            ))}
          </div>
          <Inner className="cnu-b-cmp-body" />
        </div>
      </div>
    </section>
  );
}

export function CompareSection({ a, Inner, root }: BlockProps) {
  return (
    <div role="rowgroup" {...at(root, "cnu-b-cmp-section")}>
      <div className="cnu-b-cmp-sechead" role="row">
        <span role="rowheader">{a.title}</span>
      </div>
      <Inner className="cnu-b-cmp-rows" />
    </div>
  );
}

const YES = ["yes", "y", "✓", "✔", "true", "1"];
const NO = ["", "no", "n", "-", "–", "—", "false", "0"];

function Cell({ value, i }: { value: unknown; i: number }) {
  const v = typeof value === "string" ? value.trim() : "";
  const k = v.toLowerCase();
  const cls = `cnu-b-cmp-cell cnu-b-cmp-c${i}`;
  if (YES.includes(k)) return <span className={`${cls} cnu-b-cmp-yes`} role="cell" aria-label="Yes" />;
  if (NO.includes(k)) return <span className={`${cls} cnu-b-cmp-no`} role="cell" aria-label="No" />;
  return (
    <span className={cls} role="cell">
      {v}
    </span>
  );
}

export function CompareRow({ a, root }: BlockProps) {
  return (
    <div role="row" {...at(root, "cnu-b-cmp-row")}>
      <span className="cnu-b-cmp-label" role="rowheader">
        {a.label}
      </span>
      {COLUMNS.map((i) => (
        <Cell key={i} value={a[`c${i}`]} i={i} />
      ))}
    </div>
  );
}

export function Note({ a, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-b-notebar")}>
      <div className="cnu-wrap">
        <p>{a.text}</p>
      </div>
    </section>
  );
}

const KINDS = Object.keys(STATUS) as Status[];
const kind = (v: unknown): Status => (KINDS.includes(v as Status) ? (v as Status) : "plan");
const tally = (items: BlockProps["inner"] = []) => {
  const n = { live: 0, build: 0, next: 0, plan: 0 };
  for (const i of items) n[kind(i.attrs.status)]++;
  return n;
};

export function RoadmapIntro({ a, Link, Inner, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-rm cnu-rm-intro")}>
      <div className="cnu-rm-wrap">
        {a.eyebrow ? <p className="cnu-rm-eyebrow">{a.eyebrow}</p> : null}
        <h1>{a.title}</h1>
        {a.text ? <p className="cnu-rm-lead">{a.text}</p> : null}
        {a.ctaLabel ? (
          <p className="cnu-rm-cta">
            <Link href={a.ctaHref}>{a.ctaLabel}</Link>
          </p>
        ) : null}
        <Inner className="cnu-rm-facts" />
      </div>
    </section>
  );
}

export function RoadmapFact({ a, root }: BlockProps) {
  return (
    <div {...at(root, "cnu-rm-fact")}>
      <h2>{a.title}</h2>
      {a.text ? <p>{a.text}</p> : null}
    </div>
  );
}

export function RoadmapSteps({ a, Inner, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-rm cnu-rm-stepset")}>
      <div className="cnu-rm-wrap">
        {a.title ? <h2 className="cnu-rm-h2">{a.title}</h2> : null}
        <Inner className="cnu-rm-steps" />
      </div>
    </section>
  );
}

export function RoadmapStep({ a, root }: BlockProps) {
  return (
    <div {...at(root, "cnu-rm-step")}>
      <b>{a.title}</b>
      {a.text ? <span>{a.text}</span> : null}
    </div>
  );
}

export function Roadmap({ a, Inner, inner = [], root }: BlockProps) {
  const total = tally(inner.flatMap((b) => b.innerBlocks ?? []));
  const all = total.live + total.build + total.next + total.plan;
  const stats: [Status, string][] = [
    ["live", "Live today"],
    ["build", "Being built"],
    ["next", "Up next"],
    ["plan", "Planned"],
  ];
  return (
    <section {...at(root, "cnu-rm cnu-rm-main")} data-filter="all">
      <div className="cnu-rm-wrap">
        {a.title ? <h2 className="cnu-rm-h2">{a.title}</h2> : null}
        <div className="cnu-rm-stats">
          {stats.map(([k, label]) => (
            <div className="cnu-rm-stat" key={k}>
              <b>{total[k]}</b>
              <span>
                <span className={`cnu-rm-dot ${k}`} />
                {label}
              </span>
            </div>
          ))}
        </div>
        <RoadmapControls total={all} />
        <div className="cnu-rm-tree">
          <div className="cnu-rm-trunk" aria-hidden>
            <i />
          </div>
          <div className="cnu-rm-root">
            <b>{a.rootLabel}</b>
            {a.rootText ? <> &nbsp;·&nbsp; {a.rootText}</> : null}
          </div>
          <Inner className="cnu-rm-branches" />
          {a.crownLabel || a.crownText ? (
            <div className="cnu-rm-crown">
              <b>{a.crownLabel}</b>
              {a.crownText ? <> &nbsp;·&nbsp; {a.crownText}</> : null}
            </div>
          ) : null}
        </div>
        {a.foot ? <p className="cnu-rm-foot">{a.foot}</p> : null}
      </div>
    </section>
  );
}

/** A branch opens to show its items; while it is being edited it stays open. */
export function RoadmapBranch({ a, Inner, inner = [], root }: BlockProps) {
  const n = tally(inner);
  const count = inner.length || 1;
  const pct = (v: number) => `${((v / count) * 100).toFixed(1)}%`;
  const head = (
    <>
      <span className="cnu-rm-ico" aria-hidden>
        <svg viewBox="0 0 24 24" dangerouslySetInnerHTML={{ __html: ROADMAP_ICONS[a.icon] ?? ROADMAP_ICONS.globe }} />
      </span>
      <span>
        <span className="cnu-rm-title">
          {a.title}
          {a.badge ? <em className="cnu-rm-focus">{a.badge}</em> : null}
        </span>
        {a.sub ? <span className="cnu-rm-sub">{a.sub}</span> : null}
      </span>
      <svg className="cnu-rm-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <path d="M6 9l6 6 6-6" />
      </svg>
      <span className="cnu-rm-meter">
        <span className="cnu-rm-bar" role="img" aria-label={`${n.live} of ${inner.length} live`}>
          <i className="l" style={{ width: pct(n.live) }} />
          <i className="b" style={{ width: pct(n.build) }} />
          <i className="n" style={{ width: pct(n.next) }} />
        </span>
        <span className="cnu-rm-count">
          {n.live} of {inner.length} live
        </span>
      </span>
    </>
  );
  return (
    <section {...at(root, `cnu-rm-branch ${a.badge ? "cnu-rm-branch-focus" : ""}`)}>
      <span className="cnu-rm-node" aria-hidden />
      {root ? (
        <div className="cnu-rm-card">
          <div className="cnu-rm-head">{head}</div>
          <Inner className="cnu-rm-list" />
        </div>
      ) : (
        <details className="cnu-rm-card">
          <summary className="cnu-rm-head">{head}</summary>
          <Inner className="cnu-rm-list" />
        </details>
      )}
    </section>
  );
}

export function RoadmapItem({ a, root }: BlockProps) {
  const k = kind(a.status);
  return (
    <div {...at(root, "cnu-rm-item")} data-s={k}>
      <span className={`cnu-rm-dot ${k}`} />
      <span>
        {a.title}
        {a.note ? <small>{a.note}</small> : null}
      </span>
      <span className={`cnu-rm-tag ${k}`}>{STATUS[k]}</span>
    </div>
  );
}

export function Callout({ a, Link, root }: BlockProps) {
  return (
    <section {...at(root, "cnu-rm-callout")}>
      <div className="cnu-rm-callout-in">
        <div>
          {a.eyebrow ? <p className="cnu-rm-eyebrow">{a.eyebrow}</p> : null}
          <h2>{a.title}</h2>
          {a.text ? <p>{a.text}</p> : null}
        </div>
        {a.buttonLabel ? <Link href={a.href}>{a.buttonLabel}</Link> : null}
      </div>
    </section>
  );
}

/** Every block the library draws, by its WordPress name. */
export const BLOCKS: Record<string, (p: BlockProps) => ReactNode> = {
  "clientnav/hero": Hero,
  "clientnav/strip": Strip,
  "clientnav/cards": Cards,
  "clientnav/card": CardItem,
  "clientnav/feature": Feature,
  "clientnav/point": Point,
  "clientnav/posts": Posts,
  "clientnav/promo": Promo,
  "clientnav/promo-link": PromoLink,
  "clientnav/quote": Quote,
  "clientnav/band": Band,
  "clientnav/page-hero": PageHero,
  "clientnav/subnav": Subnav,
  "clientnav/subnav-link": SubnavLink,
  "clientnav/overview": Overview,
  "clientnav/check": Check,
  "clientnav/points": Points,
  "clientnav/faq": Faq,
  "clientnav/faq-item": FaqItem,
  "clientnav/inquiry": Inquiry,
  "clientnav/rows": Rows,
  "clientnav/row": Row,
  "clientnav/statement": Statement,
  "clientnav/picture": CaptionedPicture,
  "clientnav/banner": Banner,
  "clientnav/info": Info,
  "clientnav/way": Way,
  "clientnav/options": Options,
  "clientnav/option": Option,
  "clientnav/plans": Plans,
  "clientnav/plan": Plan,
  "clientnav/compare": Compare,
  "clientnav/compare-section": CompareSection,
  "clientnav/compare-row": CompareRow,
  "clientnav/note": Note,
  "clientnav/roadmap-intro": RoadmapIntro,
  "clientnav/roadmap-fact": RoadmapFact,
  "clientnav/roadmap-steps": RoadmapSteps,
  "clientnav/roadmap-step": RoadmapStep,
  "clientnav/roadmap": Roadmap,
  "clientnav/roadmap-branch": RoadmapBranch,
  "clientnav/roadmap-item": RoadmapItem,
  "clientnav/callout": Callout,
};
