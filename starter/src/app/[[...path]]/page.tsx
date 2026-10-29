import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { jsonLd, metadata, seoOf } from "@clientnav/headless/seo";
import { Designed } from "@/components/designed";
import { Article, Cards, JsonLd, Layout, Pager } from "@/components/parts";
import { CMS_URL, INDEXABLE, door, seoOpts } from "@/lib/cms";

type Props = { params: Promise<{ path?: string[] }> };

/** Whatever lives at an address on the CMS: an entry, a section, a tag, an author, a type's list. */
async function at(params: Props["params"]) {
  const parts = (await params).path ?? [];
  const path = parts.length ? `/${parts.map(encodeURIComponent).join("/")}/` : "/";
  // Pages made in Elementor come as parts with their styles; everything else as text.
  return { path, found: await door.resolve(path, { with: "author,archive,elementor" }) };
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { path, found } = await at(params);
  if (!found) return { title: "Not found" };
  const meta = metadata(seoOf(found), { ...(await seoOpts()), path }) as Metadata;
  // The CMS's own robots line would let a beta address be listed: off until the switch.
  return INDEXABLE ? meta : { ...meta, robots: { index: false, follow: false } };
}

export default async function Page({ params }: Props) {
  const { path, found } = await at(params);
  if (!found) notFound();
  const ld = <JsonLd text={jsonLd(seoOf(found), await seoOpts())} />;
  if (found.type === "entry") {
    const built = found.entry?.elementor;
    return (
      <>
        {ld}
        {/* A page on the page builder's blank canvas is drawn without the site's header and footer (see globals.css). */}
        {built?.parts?.length ? (
          <Designed parts={built.parts} css={built.css} scope={built.scope} assets={built.assets} scripts={built.scripts} cms={CMS_URL} as="article" className={found.entry?.template === "elementor_canvas" ? "page cnh-canvas" : "page"} />
        ) : (
          <Templated where="single" path={path} fallback={<Article entry={found.entry} />} />
        )}
      </>
    );
  }
  // The home page as a list of the newest posts: drawn from the site's "home" make-up when it describes one (plugin 0.23.0).
  if (path === "/") {
    const home = await door.layout("home").catch(() => null);
    if (home?.groups && Object.keys(home.groups).length) {
      return (
        <>
          {ld}
          <h1 className="cnh-hidden">{found.title ?? ""}</h1>
          <Layout groups={home.groups} />
        </>
      );
    }
  }
  // A list drawn by the site's own theme-builder template, when it has one for this list.
  const listed = await door.part("archive", { path }).catch(() => null);
  if (listed?.parts?.length) {
    return (
      <>
        {ld}
        <Designed parts={listed.parts} css={listed.css} scope={listed.scope} assets={listed.assets} scripts={listed.scripts} cms={CMS_URL} as="section" className="page" />
      </>
    );
  }
  // A list: a section, tag or other term, an author, a type's list or the posts page.
  const title = found.term?.name ?? found.author?.name ?? found.title ?? "";
  const base = path.replace(/page\/\d+\/$/, "");
  return (
    <>
      {ld}
      {title ? <h1>{title}</h1> : null}
      {found.term?.description ? <p>{found.term.description}</p> : null}
      <Cards items={found.items ?? []} />
      <Pager base={base} page={found.page ?? 1} more={!!found.more} />
    </>
  );
}

/** An entry drawn by the site's own theme-builder template for its kind (plugin 0.22.0), or the fallback without one. */
async function Templated({ where, path, fallback }: { where: "single"; path: string; fallback: React.ReactNode }) {
  const part = await door.part(where, { path }).catch(() => null);
  return part?.parts?.length ? <Designed parts={part.parts} css={part.css} scope={part.scope} assets={part.assets} scripts={part.scripts} cms={CMS_URL} as="article" className="page" /> : <>{fallback}</>;
}
