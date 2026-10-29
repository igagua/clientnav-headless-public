import type { Metadata } from "next";
import Link from "next/link";
import { Actions } from "@/components/actions";
import { Designed } from "@/components/designed";
import { Matomo } from "@/components/matomo";
import { Menu } from "@/components/parts";
import { CMS_URL, INDEXABLE, SITE_URL, door, site, type Raw } from "@/lib/cms";
import { Suspense } from "react";
import "./globals.css";

const MATOMO_URL = process.env.MATOMO_URL ?? "";
const MATOMO_SITE_ID = process.env.MATOMO_SITE_ID ?? "";

export async function generateMetadata(): Promise<Metadata> {
  const s = await site();
  return { metadataBase: SITE_URL ? new URL(SITE_URL) : undefined, title: { default: s.name, template: `%s · ${s.name}` }, description: s.description, ...(INDEXABLE ? {} : { robots: { index: false, follow: false } }) };
}

/** The site's frame: its name and the menus kept in WordPress (main menu, footer, legal line). */
export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const s = await site();
  // A header or footer made in the page builder is drawn as made; otherwise the site's name and menus.
  const [head, foot] = await Promise.all([door.part("header"), door.part("footer")]);
  const designed = (part: Raw) => part?.parts?.length > 0;
  return (
    <html lang={s.language || "en"}>
      {/* The page builder's kit writes its site-wide rules for a class on <body>; it is the CMS's, so it is put here too. */}
      <body className={(designed(head) && head.kit) || (designed(foot) && foot.kit) || undefined}>
        {designed(head) ? (
          <Designed parts={head.parts} css={head.css} scope={head.scope} assets={head.assets} scripts={head.scripts} cms={CMS_URL} as="header" className="site" />
        ) : (
          <header className="site">
            <div className="wrap">
              <Link href="/" className="name">
                {s.name}
              </Link>
              <nav aria-label="Main">
                <Menu items={s.menus?.primary} className="menu" />
              </nav>
            </div>
          </header>
        )}
        <main className={designed(head) ? "main" : "wrap"}>{children}</main>
        {designed(foot) ? (
          <Designed parts={foot.parts} css={foot.css} scope={foot.scope} assets={foot.assets} scripts={foot.scripts} cms={CMS_URL} as="footer" className="site" />
        ) : (
          <footer className="site">
            <div className="wrap">
              <Menu items={s.menus?.footer} className="menu" />
              <Menu items={s.menus?.legal} className="menu" />
            </div>
          </footer>
        )}
        <Actions />
        {/* Visits in Matomo, on the site's own address only (the component decides in the browser). */}
        {MATOMO_URL && MATOMO_SITE_ID && SITE_URL ? (
          <Suspense fallback={null}>
            <Matomo url={MATOMO_URL} siteId={MATOMO_SITE_ID} site={SITE_URL} />
          </Suspense>
        ) : null}
      </body>
    </html>
  );
}
