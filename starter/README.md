# Client Navigator Headless: starter front end

A whole front end for a WordPress CMS that runs Client Navigator Headless, built only on `@clientnav/headless`
(Next.js 16, React 19, no other packages). The starting point for a new headless site: copy this folder, give it
the site's own look, keep the parts below.

| Address | What |
|---|---|
| any page (`[[...path]]`) | whatever lives there on the CMS: entry, section, tag, author, type's list, with paging; SEO from the CMS (PR Register when it is there), a canonical of its own otherwise, structured data |
| `/preview/<id>/` | a draft as its editor left it; carries the preview bridge, so a click in the editor's split view selects the block; only the CMS may frame it |
| `/api/preview/`, `/api/exit-preview/` | where WordPress's Preview button leads, and the way out |
| `/api/revalidate/` | the CMS's refresh call on publish and change |
| `/feed/` | the CMS's RSS feed with this site's addresses |
| `/sitemap.xml`, `/robots.txt` | every public address (`/paths`), and the rules |

Menus (main, footer, legal) and the site's name come from WordPress.

## Running it

```bash
cp .env.example .env.local      # CMS_URL and SITE_URL; SITE_INDEXABLE=true only at the switch (noindex until then)
npx clientnav-headless-pair --cms https://cms.example.com CODE --env .env.local
npm run build && npm run start  # http://localhost:3100
```

The pairing code comes from WordPress (Settings > Headless > Make a pairing code). Without it the site still reads an
open door; previews and refresh need the secret. Tried on 2026-10-14 against a site (read only): every
address above answered, 3,201 addresses in the sitemap.

Not in the starter yet: Elementor pages drawn from parts (`with=elementor`, see the plugin's README), languages
(`?lang=`), forms, search. A big archive (tens of thousands of entries) should give sitemaps per month from
`/months` and `/month` instead of one `/sitemap.xml`.
