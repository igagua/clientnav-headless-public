import type { ReactNode } from "react";
import { Anchor } from "./link";
import { ArrowUpRight } from "./icons";
import type { Card, LinkAs, Picture } from "./types";

const day = (iso: string) => new Date(iso).toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric", timeZone: "UTC" });

export function Img({ picture, sizes, eager, className }: { picture: Picture; sizes?: string; eager?: boolean; className?: string }) {
  return (
    // eslint-disable-next-line @next/next/no-img-element -- the library is not tied to one framework's image component
    <img className={className} src={picture.src} srcSet={picture.srcset || undefined} sizes={picture.srcset ? sizes : undefined} width={picture.width} height={picture.height} alt={picture.alt} loading={eager ? "eager" : "lazy"} decoding="async" fetchPriority={eager ? "high" : undefined} />
  );
}

export function Button({ href, children, variant = "solid", linkAs }: { href: string; children: ReactNode; variant?: "solid" | "outline" | "light"; linkAs?: LinkAs }) {
  return (
    <Anchor as={linkAs} href={href} className={`cnu-button cnu-button-${variant}`}>
      {children}
      <ArrowUpRight />
    </Anchor>
  );
}

/** The opening of a section page: small line, large title (the part after "|" is tinted), a paragraph. */
export function PageIntro({ eyebrow, title, text, crumbs, linkAs, children }: { eyebrow?: string; title: string; text?: string; crumbs?: { title: string; href?: string }[]; linkAs?: LinkAs; children?: ReactNode }) {
  const [first, second] = title.split("|");
  return (
    <section className="cnu-intro">
      <div className="cnu-wrap">
        {crumbs?.length ? (
          <nav className="cnu-crumbs" aria-label="Breadcrumb">
            {crumbs.map((c, i) => (
              <span key={i}>
                {c.href ? (
                  <Anchor as={linkAs} href={c.href}>
                    {c.title}
                  </Anchor>
                ) : (
                  <span aria-current="page">{c.title}</span>
                )}
              </span>
            ))}
          </nav>
        ) : null}
        {eyebrow ? <p className="cnu-eyebrow">{eyebrow}</p> : null}
        <h1>
          {first}
          {second ? <span>{second}</span> : null}
        </h1>
        {text ? <p className="cnu-intro-text">{text}</p> : null}
        {children}
      </div>
    </section>
  );
}

export function ArticleCard({ card, linkAs, eager }: { card: Card; linkAs?: LinkAs; eager?: boolean }) {
  return (
    <article className="cnu-card">
      {card.image ? (
        <Anchor as={linkAs} href={card.path} className="cnu-card-image" label={card.title}>
          <Img picture={card.image} sizes="(max-width: 640px) 100vw, (max-width: 1000px) 50vw, 400px" eager={eager} />
        </Anchor>
      ) : null}
      <div className="cnu-card-body">
        <p className="cnu-card-meta">
          {card.category ? (
            <Anchor as={linkAs} href={card.category.path}>
              {card.category.name}
            </Anchor>
          ) : null}
          <span>{card.minutes} min read</span>
        </p>
        <h2>
          <Anchor as={linkAs} href={card.path}>
            {card.title}
          </Anchor>
        </h2>
        <p className="cnu-card-excerpt">{card.excerpt}</p>
        <div className="cnu-card-foot">
          <time dateTime={card.date}>{day(card.date)}</time>
          <Anchor as={linkAs} href={card.path} className="cnu-text-link" label={`Read: ${card.title}`}>
            Read the article
            <ArrowUpRight />
          </Anchor>
        </div>
      </div>
    </article>
  );
}

export function CardGrid({ cards, linkAs, empty = "Nothing here yet." }: { cards: Card[]; linkAs?: LinkAs; empty?: string }) {
  if (!cards.length) return <p className="cnu-empty">{empty}</p>;
  return (
    <div className="cnu-grid">
      {cards.map((c, i) => (
        <ArticleCard key={c.id} card={c} linkAs={linkAs} eager={i < 3} />
      ))}
    </div>
  );
}

export function Pagination({ page, pages, base, linkAs }: { page: number; pages: number; base: string; linkAs?: LinkAs }) {
  if (pages < 2) return null;
  const at = (n: number) => (n === 1 ? base : `${base}page/${n}/`);
  return (
    <nav className="cnu-pages" aria-label="Pages">
      {page > 1 ? (
        <Anchor as={linkAs} href={at(page - 1)} className="cnu-pages-step">
          ← Newer
        </Anchor>
      ) : (
        <span />
      )}
      <span className="cnu-pages-list">
        {Array.from({ length: pages }, (_, i) => i + 1).map((n) =>
          n === page ? (
            <span key={n} aria-current="page">
              {n}
            </span>
          ) : (
            <Anchor key={n} as={linkAs} href={at(n)}>
              {n}
            </Anchor>
          ),
        )}
      </span>
      {page < pages ? (
        <Anchor as={linkAs} href={at(page + 1)} className="cnu-pages-step">
          Older →
        </Anchor>
      ) : (
        <span />
      )}
    </nav>
  );
}

export type Heading = { id: string; text: string };

/** The head of an article: section, title, summary, byline and the cover picture. */
export function ArticleHeader({ card, cover, linkAs }: { card: Card; cover?: Picture | null; linkAs?: LinkAs }) {
  return (
    <header>
      <div className="cnu-article-head">
        <div className="cnu-article-head-in">
          {card.category ? (
            <p className="cnu-article-term">
              <svg viewBox="0 0 640 512" aria-hidden>
                <path d="M497.941 225.941L286.059 14.059A48 48 0 0 0 252.118 0H48C21.49 0 0 21.49 0 48v204.118a48 48 0 0 0 14.059 33.941l211.882 211.882c18.744 18.745 49.136 18.746 67.882 0l204.118-204.118c18.745-18.745 18.745-49.137 0-67.882zM112 160c-26.51 0-48-21.49-48-48s21.49-48 48-48 48 21.49 48 48-21.49 48-48 48zm513.941 133.823L421.823 497.941c-18.745 18.745-49.137 18.745-67.882 0l-.36-.36L527.64 323.522c16.999-16.999 26.36-39.6 26.36-63.64s-9.362-46.641-26.36-63.64L331.397 0h48.721a48 48 0 0 1 33.941 14.059l211.882 211.882c18.745 18.745 18.745 49.137 0 67.882z" />
              </svg>
              <Anchor as={linkAs} href={card.category.path}>
                {card.category.name}
              </Anchor>
            </p>
          ) : null}
          <h1>{card.title}</h1>
          {card.excerpt ? <p className="cnu-article-summary">{card.excerpt}</p> : null}
          <p className="cnu-byline">
            {card.author ? `By ${card.author} · ` : ""}Published <time dateTime={card.date}>{day(card.date)}</time>
          </p>
        </div>
      </div>
      {cover ? (
        <div className="cnu-article-cover">
          <Img picture={cover} sizes="(max-width: 1100px) 100vw, 1000px" eager />
        </div>
      ) : null}
    </header>
  );
}

/** An article's text with its list of headings beside it. `html` must already be cleaned by the host. */
export function ArticleBody({ html, headings, aside, children }: { html: string; headings: Heading[]; aside?: string; children?: ReactNode }) {
  return (
    <div className="cnu-article-layout">
      <aside className="cnu-toc">
        <h2>{aside ?? "In this article"}</h2>
        <ol>
          {headings.length ? (
            headings.map((h) => (
              <li key={h.id}>
                <a href={`#${h.id}`}>{h.text}</a>
              </li>
            ))
          ) : (
            <li>No headings were found on this page.</li>
          )}
        </ol>
      </aside>
      <div>
        <div className="cnu-prose" dangerouslySetInnerHTML={{ __html: html }} />
        {children}
      </div>
    </div>
  );
}

/** The coloured closing band with one call to action, for pages that are not made of blocks. */
export function ContactBand({ eyebrow, title, accent, text, button, note, linkAs }: { eyebrow?: string; title: string; accent?: string; text?: string; button: { label: string; href: string }; note?: { label: string; href: string }; linkAs?: LinkAs }) {
  return (
    <section className="cnu-band">
      <div className="cnu-wrap cnu-band-in">
        <div className="cnu-b-head">
          {eyebrow ? <p className="cnu-eyebrow">{eyebrow}</p> : null}
          <h2>
            {title}
            {accent ? <span className="cnu-accent">{accent}</span> : null}
          </h2>
        </div>
        <div>
          {text ? <p>{text}</p> : null}
          <div className="cnu-band-actions">
            <Button href={button.href} variant="light" linkAs={linkAs}>
              {button.label}
            </Button>
            {note ? (
              <Anchor as={linkAs} href={note.href} className="cnu-band-note">
                {note.label}
              </Anchor>
            ) : null}
          </div>
        </div>
      </div>
    </section>
  );
}

/** A plain centred message, for "not found" and pages that are not built yet. */
export function Notice({ eyebrow, title, text, children }: { eyebrow?: string; title: string; text?: string; children?: ReactNode }) {
  return (
    <section className="cnu-notice">
      <div className="cnu-wrap">
        {eyebrow ? <p className="cnu-eyebrow">{eyebrow}</p> : null}
        <h1>{title}</h1>
        {text ? <p>{text}</p> : null}
        <div className="cnu-notice-actions">{children}</div>
      </div>
    </section>
  );
}
