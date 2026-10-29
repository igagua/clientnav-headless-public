# @clientnav/headless

What every front end of a Client Navigator Headless CMS needs, in one package: plain JavaScript modules (Node 18.17+),
no dependencies, types in `index.d.ts`. Works in Next.js, Astro, Nuxt, SvelteKit and workers; the examples are Next.js.

| Import | What |
|---|---|
| `@clientnav/headless/door` | `createDoor({ cms, key, next })`: the content door (`site`, `resolve`, `posts`, `terms`, `authors`, `paths`, `months`, `month`, `day`, `index`, `part`, `layout`, `form`, `preview`, `live`, `feed`). Sends the shared secret, so the CMS can close its door to everyone else. `null` for "not found", `DoorDown` for everything else |
| `@clientnav/headless/routes` | `previewHandler`, `exitPreviewHandler`, `revalidateHandler`, `feedHandler`: Request → Response handlers; `previewToken(cookies, id, secret)`; `previewBridge(cms)` |
| `@clientnav/headless/token` | `verifyPreviewToken`, `signPreviewToken`, `PREVIEW_COOKIE` (`cn_preview`) |
| `@clientnav/headless/seo` | `metadata(seo, { site, cmsFront })` for `generateMetadata`, `jsonLd(seo)`, `seoOf(answer)` (the seo of any door answer), `localize`, `plain` |
| `@clientnav/headless/antispam`, `/antispam-client` | Client Navigator Anti-Spam's kit (signing forms passed on to the CMS), copied unchanged from the Client Navigator platform |
| `@clientnav/headless/pr-register` | PR Register's kit (sitemaps, robots.txt, llms.txt, redirects), copied unchanged |
| `clientnav-headless-parity` | `npx clientnav-headless-parity --cms https://cms.example.com [--key SECRET]`: asks the CMS both ways, through the door and through WordPress's REST API, and reports every difference (lists, totals, terms, single entries of every public type; exit code 1 when anything differs). Run it before and after a front end's switch |
| `clientnav-headless-pair` | `npx clientnav-headless-pair --cms https://cms.example.com CODE`: the one-time code from WordPress for the shared secret, written into `.env` unseen |

The copied kits come in with `bin/sync-vendor.sh` (sources and checksums in `vendor/SOURCES.md`); never edit them here.

## A Next.js front end, step by step

Settings: `CMS_URL` (the CMS's address), `SITE_URL` (this site's public address), `REVALIDATE_SECRET` (from pairing).

```ts
// next.config.ts: the page cache in memory only (64 MB, or { memoryMB }), never on disk, where a crawler would fill it
import { withCacheLimit } from "@clientnav/headless/next";
export default withCacheLimit({ trailingSlash: true });

// lib/cms.ts
import { createDoor } from "@clientnav/headless/door";
export const door = createDoor({ cms: process.env.CMS_URL!, key: process.env.REVALIDATE_SECRET, next: { revalidate: 300, tags: ["wp"] } });

// app/[[...path]]/page.tsx
import { notFound } from "next/navigation";
import { metadata, jsonLd, seoOf } from "@clientnav/headless/seo";
const at = async (params) => door.resolve(`/${((await params).path ?? []).join("/")}${(await params).path ? "/" : ""}`);
export async function generateMetadata({ params }) { return metadata(seoOf(await at(params)), { site: process.env.SITE_URL }); }
export default async function Page({ params }) {
  const page = await at(params);
  if (!page) notFound();
  // page.type: "entry" | "term" | "author" | "home" ...; an entry has page.entry.html and page.entry.blocks
}

// app/api/preview/route.ts         WordPress's Preview button
export const dynamic = "force-dynamic";
export const GET = previewHandler({ secret: process.env.REVALIDATE_SECRET });

// app/preview/[id]/page.tsx         the draft
import { cookies } from "next/headers";
const token = previewToken((await cookies()).toString(), id, process.env.REVALIDATE_SECRET);
const draft = token && (await door.preview(token));
// ...draw draft.entry like a published one, and add the bridge for the editor's split view (click-to-edit):
// <script src={previewBridge(process.env.CMS_URL!)} defer />

// app/api/exit-preview/route.ts
export const GET = exitPreviewHandler();

// app/api/revalidate/route.ts      called by WordPress on publish and change
import { revalidatePath, revalidateTag } from "next/cache";
export const POST = revalidateHandler({ secret: process.env.REVALIDATE_SECRET, refresh: ({ paths, all }) => {
  revalidateTag("wp", { expire: 0 });
  if (all) revalidatePath("/", "layout"); else for (const p of paths) revalidatePath(p);
} });

// app/feed/route.ts
export const GET = feedHandler({ door });
```

The preview page must be allowed inside a frame on the CMS's address (no `X-Frame-Options: DENY`; with a CSP,
`frame-ancestors` names the CMS). Pairing, once, on the machine that runs the front end:
`npx clientnav-headless-pair --cms https://cms.example.com CODE`, then restart it. After that the CMS can close its
door (Settings > Headless > Door), since every request from here carries the key.

## Getting it into a front end

`npm run pack:headless` in this repository makes `dist/clientnav-headless-<version>.tgz`; the front end keeps it
in `vendor/` and installs it as `"@clientnav/headless": "file:vendor/clientnav-headless-<version>.tgz"`, the way
`@clientnav/ui` is installed. Raise `version` in `package.json` with every change.
