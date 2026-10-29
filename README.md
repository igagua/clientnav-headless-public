# Client Navigator Headless

Keep WordPress as the place where people write, and draw the site with a front end of your own (Next.js or anything that speaks HTTP).

- **The plugin** (`plugin/clientnav-headless`, GPL-2.0-or-later) turns WordPress into the content office of a separate front end: a read-only content door over REST, the site's menus, Elementor and block-editor pages drawn for the front end, forms, search, feeds, SEO words, languages, signed draft previews, a live preview beside the editor, and a refresh call to the front end when something is published.
- **The kit** `@clientnav/headless` (`packages/headless`, MIT): the door's client for a front end, preview and refresh handlers, SEO helpers for the head of a page, and the pairing command. Plain ES modules, no dependencies.
- **The starter** (`starter/`, MIT): a Next.js front end built only on the kit. Copy it to start a new headless site.

## How it fits together

```
 WordPress + plugin                      front end (starter or your own)
 ───────────────────                     ───────────────────────────────
 /wp-json/clientnav-headless/v1  ◀────── door.resolve("/about/"), door.posts({ category: "news" }), ...
 Preview button  ───────────────────────▶ /api/preview/?token=…   (signed, short-lived)
 publish  ──────────────────────────────▶ /api/revalidate/        (refresh call, shared secret)
 editor's live preview  ────────────────▶ /live-preview/          (unsaved blocks, drawn by the door)
```

The front end and WordPress share one secret, handed over by a one-time pairing code (Settings › Headless › Make a pairing code); nobody copies it by hand. With the door closed, only the front end (and signed-in editors) can read it.

## Getting started

1. Install the plugin on the WordPress site (zip from `npm run pack:plugin`) and set the front end's address under Settings › Headless.
2. Copy `starter/`, set `CMS_URL` and `SITE_URL`, then `npm install && npm run build && npm start`.
3. Pair: make a code under Settings › Headless › Make a pairing code and run `npx clientnav-headless-pair --cms https://cms.example.com <code>` in the front end; the secret is written to its `.env` as `REVALIDATE_SECRET`.

The routes of the door, with real answers, are in [docs/api.md](docs/api.md). A site with fields, old addresses or widgets of its own keeps them in a small add-on beside the plugin: [docs/addons.md](docs/addons.md).

## Development

```bash
npm install
npm run build:plugin            # the editor script and the block library into plugin/clientnav-headless/build
npm test                        # the kit and the editor script
bin/test-remote.sh user@host    # PHP syntax and the door inside a throwaway WordPress, on a test machine (Ubuntu)
```

`bin/test-remote.sh` installs nothing outside its own folder on the test machine and removes it afterwards. A change to the door comes with a check in `tests/wordpress*.php`.

## Licence

The plugin and the block library it ships (`packages/ui`): GPL-2.0-or-later. The kit and the starter: MIT. Each folder carries its licence.
