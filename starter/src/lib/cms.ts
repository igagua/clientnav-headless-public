import { createDoor } from "@clientnav/headless/door";

export const CMS_URL = (process.env.CMS_URL ?? "").replace(/\/+$/, "");
export const SITE_URL = (process.env.SITE_URL ?? "").replace(/\/+$/, "");
export const SECRET = process.env.REVALIDATE_SECRET;
/** Whether search engines may list this front end. Off until the switch, so a beta address is never indexed. */
export const INDEXABLE = process.env.SITE_INDEXABLE === "true";

/** Every answer is kept five minutes at most, and dropped at once when the CMS calls /api/revalidate/. */
export const door = createDoor({ cms: CMS_URL, key: SECRET, next: { revalidate: 300, tags: ["wp"] } });

/* eslint-disable @typescript-eslint/no-explicit-any */
export type Raw = any;
export type MenuItem = { id: number; title: string; href: string; newTab: boolean; children: MenuItem[] };
export type Card = { id: number; path: string; title: string; excerpt: string; date: string; author: string; image: Picture | null; section: { name: string; path: string } | null };
export type Picture = { src: string; width: number; height: number; alt: string; srcset: string };

/** The site's name, language, menus and the CMS's word for this front end's address. */
export async function site(): Promise<Raw> {
  return (await door.site()) ?? { name: "", menus: {} };
}

/** The SEO addresses the CMS writes for the front end, written as this site's own address. */
export const seoOpts = async () => ({ site: SITE_URL, cmsFront: (await site()).frontend ?? [] });
