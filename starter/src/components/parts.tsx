import Link from "next/link";
import type { Card, MenuItem, Picture, Raw } from "@/lib/cms";

const day = (iso: string) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }) : "");

export function Menu({ items, className }: { items: MenuItem[] | undefined; className?: string }) {
  if (!items?.length) return null;
  return (
    <ul className={className}>
      {items.map((m) => (
        <li key={m.id}>
          <Link href={m.href} target={m.newTab ? "_blank" : undefined}>
            {m.title}
          </Link>
          <Menu items={m.children} />
        </li>
      ))}
    </ul>
  );
}

export function Img({ p, sizes }: { p: Picture | null | undefined; sizes: string }) {
  if (!p?.src) return null;
  // eslint-disable-next-line @next/next/no-img-element -- the CMS gives every size in srcset.
  return <img src={p.src} srcSet={p.srcset || undefined} sizes={sizes} width={p.width || undefined} height={p.height || undefined} alt={p.alt} loading="lazy" />;
}

export function Cards({ items }: { items: Card[] }) {
  if (!items.length) return <p>Nothing here yet.</p>;
  return (
    <ul className="cards">
      {items.map((c) => (
        <li key={c.id}>
          <Link href={c.path}>
            <Img p={c.image} sizes="(max-width: 700px) 100vw, 320px" />
            <h3>{c.title}</h3>
          </Link>
          <p className="meta">
            {c.section ? <Link href={c.section.path}>{c.section.name}</Link> : null} {day(c.date)}
          </p>
          <p>{c.excerpt}</p>
        </li>
      ))}
    </ul>
  );
}

/**
 * A page made of groups of cards the site describes (GET /layout, plugin 0.23.0): a news home page or side column.
 * A group is a list of cards, one card (or null) or a set of groups; each is a section named after its key
 * (cnh-group-<key>) for the site's own styles. Empty groups are left out.
 */
export function Layout({ groups, name = "home" }: { groups: Record<string, unknown>; name?: string }) {
  return (
    <div className={`cnh-layout cnh-layout-${name}`}>
      <Groups groups={groups} />
    </div>
  );
}

function Groups({ groups }: { groups: Record<string, unknown> }) {
  return (
    <>
      {Object.entries(groups ?? {}).map(([key, g]) => {
        if (!g) return null;
        if (Array.isArray(g)) {
          return g.length ? (
            <section key={key} className={`cnh-group cnh-group-${key}`}>
              <Cards items={g as Card[]} />
            </section>
          ) : null;
        }
        const one = g as Partial<Card>;
        if (typeof one.id === "number" && typeof one.path === "string") {
          return (
            <section key={key} className={`cnh-group cnh-group-${key} cnh-one`}>
              <Cards items={[one as Card]} />
            </section>
          );
        }
        return (
          <div key={key} className={`cnh-groups cnh-group-${key}`}>
            <Groups groups={g as Record<string, unknown>} />
          </div>
        );
      })}
    </>
  );
}

/** Older and newer pages of a list, at the CMS's addresses (/category/news/page/2/). */
export function Pager({ base, page, more }: { base: string; page: number; more: boolean }) {
  const at = (n: number) => (n <= 1 ? base : `${base}page/${n}/`);
  if (page <= 1 && !more) return null;
  return (
    <nav className="pager">
      {page > 1 ? <Link href={at(page - 1)}>← Newer</Link> : <span />}
      {more ? <Link href={at(page + 1)}>Older →</Link> : null}
    </nav>
  );
}

export function Article({ entry }: { entry: Raw }) {
  return (
    <article className="article">
      <h1>{entry.title}</h1>
      {entry.type === "post" ? (
        <p className="meta">
          {entry.by?.path ? <Link href={entry.by.path}>{entry.author}</Link> : entry.author} · {day(entry.date)}
        </p>
      ) : null}
      {entry.type === "post" ? <Img p={entry.image} sizes="(max-width: 760px) 100vw, 760px" /> : null}
      {/* The CMS's own HTML: the content of the people who edit this site. */}
      <div className="body" dangerouslySetInnerHTML={{ __html: entry.html }} />
    </article>
  );
}

export const JsonLd = ({ text }: { text: string }) => (text ? <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: text }} /> : null);
