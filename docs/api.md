# The content door: API reference

Client Navigator Headless turns a WordPress site into the back office of a separate front end. The front end reads everything through one read-only HTTP interface, the **content door**, at

```
https://<the WordPress address>/wp-json/clientnav-headless/v1/
```

Every route answers JSON, with `GET`, and gives only published content. Answers are kept inside WordPress until something public changes, so asking often is cheap. Nothing in a front end ever writes to WordPress except a form submission and the pairing handshake, both described below.

This page lists every route, what it takes and what it answers, with real examples. The shapes are the contract: a field is never renamed; new fields are added with a default.

## Addresses, dates, words

- **Addresses are paths.** Every link the door hands out that leads to the site itself is a path (`/category/technology/`), never a full address, so the front end puts its own name in front. Links to other sites stay as they are. Pictures and files are named on the WordPress address unless the site's setting "Pictures and files" says *paths* (for a front end that passes `/wp-content/` on to WordPress itself).
- **Dates** come twice: `date` and `modified` in UTC (`2026-10-13T16:59:57Z`) and `local`, the site's own wall clock without a zone (`2026-10-13T09:59:57`), as the archive addresses count them.
- **Words are plain text** in `title`, `excerpt`, names and SEO words (no HTML entities: `&`, `’`), with WordPress's own quotes and dashes. `html` is HTML.

## Common shapes

**Card** (an entry in a list):

```json
{
  "id": 2668, "type": "post", "slug": "storage-server-restart",
  "path": "/it-planning/storage-server-restart/",
  "title": "Our Storage Server Restarted. Eight Things Didn’t Come Back",
  "excerpt": "After a routine restart, eight applications on one of our own storage servers …",
  "date": "2026-10-13T16:59:57Z", "modified": "2026-10-13T16:59:57Z", "local": "2026-10-13T09:59:57",
  "author": "Site Admin", "by": { "id": 1, "name": "Site Admin", "slug": "admin", "path": "/author/admin/" },
  "category": { "id": 24, "taxonomy": "category", "name": "IT planning", "slug": "it-planning", "path": "/category/it-planning/", "count": 6, "parent": 0 },
  "section": { "…the term to show above the headline, the editor's choice or the first section" },
  "terms": [ { "…every term of the entry, any public taxonomy" } ],
  "image": { "src": "…/uploads/2026/10/picture.webp", "width": 1280, "height": 720, "alt": "", "srcset": "…" },
  "minutes": 3
}
```

`image` is `null` without a featured picture. With `?with=meta`, a card also carries `meta`: every field the entry's type shows in WordPress's own REST API, other plugins' included. With `?with=sizes`, `image` carries `sizes`, every registered size by name.

**Entry** (one piece of content, as `/resolve` answers it): a card plus

```json
{
  "fields": { "…the entry's own public fields (what its type registers for REST)" },
  "blocks": [ { "name": "core/paragraph", "attrs": {}, "html": "<p>…</p>", "innerBlocks": [] } ],
  "html": "<p>…the whole text, rendered by WordPress (shortcodes, embeds), links as paths…</p>",
  "crumbs": [ { "title": "Home", "href": "/" }, { "title": "The entry's title" } ],
  "template": "", "parent": 0,
  "seo": { "…see SEO below" }
}
```

**Term** (a category, tag or any public taxonomy's term):

```json
{ "id": 21, "taxonomy": "category", "name": "Technology", "slug": "technology", "path": "/category/technology/", "count": 144, "parent": 0, "description": "" }
```

**List** (wherever entries are listed): `items` (cards), `total`, `pages`, `page`, `more`. On a site with a very big type (above 20,000 entries) `total` and `pages` are left out unless asked for with `count=1`; `more` always says whether another page follows. No page past 5,000: the archives below are the way that deep.

**SEO** (`seo`, on every entry, term, author, type list and the home page): the words for the head of the page.

```json
{ "title": "Technology - a news site", "description": "Technology news and analysis…", "found": true, "source": "pr-register",
  "kind": "term", "id": 21, "robots": "follow, index, max-snippet:-1, …",
  "og": { "type": "website", "title": "…", "description": "…", "image": { "url": "…", "width": 1200, "height": 630 }, "published": "…", "modified": "…", "section": "…" },
  "twitter": { "card": "summary_large_image", "title": "…", "description": "…", "image": "…" },
  "canonical": "https://www.example.com/category/technology/", "schema": [ "…JSON-LD graph…" ], "breadcrumbs": [ "…" ], "summary": "…", "faq": [ "…" ] }
```

`source` is `pr-register` when PR Register (0.21 or later) runs on the WordPress: then the SEO words are what its editors wrote there, and `canonical`, `schema` and `breadcrumbs` are present only when PR Register's headless mode names this front end. Otherwise `source` is `door` and only `title` and `description` are given (the entry's own).

## Routes

### `GET /site`

The site: `name`, `description`, `language` (`en-US`), `frontend` (the front end's address as WordPress knows it), `perPage`, `menus`, `categories`, `door` (the plugin's version).

`menus` holds four places a front end can fill from Appearance > Menus: `primary` (main menu), `utility` (top bar), `footer` (footer columns), `legal` (the bottom line). Each item: `id`, `title`, `href` (a path), `description`, `note`, `classes`, `newTab`, `children` (the same, nested).

`categories`: every category with entries, as terms.

### `GET /resolve?path=/a/b/`

What lives at an address. The answer's `type` says which kind came back, so a front end is never handed a shape it does not expect:

| `type` | Answer |
|---|---|
| `entry` | `{ "type": "entry", "entry": { … }, "front": true }` — `front` only on the home page when it is a page |
| `posts` | the page that lists the posts, or the home page when it lists posts: `title`, `path`, `seo`, and a list |
| `term` | `{ "type": "term", "term": { … }, "seo": { … } }` and a list of its entries; `/category/x/page/2/` gives page 2 |
| `author` | only with `with=author`: `{ "type": "author", "author": { "id", "name", "slug", "path", "description" }, "seo" }` and a list |
| `archive` | only with `with=archive`: a type's own list (`/explainers/`): `of` (the type), `title`, `path`, `seo` and a list |
| `redirect` | `{ "type": "redirect", "to": "/the-right-address/" }` when the address is a near miss (a missing slash, an old permalink); `"outside": true` and a full address when the page is one WordPress keeps for itself (a shop's cart) |
| `none` | HTTP 404, `{ "type": "none" }` |

Other questions on the same route:

- `?id=1503`: the entry by its number.
- `?old=/address/on/the/old/site/`: where a page of the previous site lives now, when the site's add-on keeps a table of old addresses; `redirect` or `none`.
- `?with=elementor`: an entry designed in Elementor also carries `elementor` (see *Designed pages*).
- `?with=versions`: the entry's address in every language (see *Languages*).
- `?lang=es`, or an address in the language (`/es/preguntas-frecuentes/`): the answer in that language.

### `GET /posts`

A list of entries, newest first, at most 50 a page.

| Parameter | Meaning |
|---|---|
| `page`, `per_page` | the page and its size (default: the site's "posts per page") |
| `type` | another public type than `post`; several separated by commas make one list (a search across kinds) |
| `category` | a category by slug; `a,b` several as one list; `not_category=x,y` everything except those |
| `tag` | a tag by slug |
| `tax`, `term` | a term (slug or number) of any public taxonomy, sub-terms included |
| `by` | an author, by number or by the name in the author's address (`author=` means the same, but security plugins refuse requests that carry it) |
| `search` | words; the answer is then in WordPress's order of best match |
| `orderby`, `order` | `date`, `modified`, `title`, or a field the site's add-on offers (events by their start); `order=asc` or `desc` |
| `offset` | leave out that many of the newest, on every page |
| `exclude` | entry numbers to leave out |
| `full=1` | whole entries with their text instead of cards (for feeds) |
| `count=0` / `count=1` | leave out, or force, `total` and `pages` |
| `with` | `meta`, `sizes` as on `/resolve` |
| `lang` | the language |

Answer: a list (`items`, `total`, `pages`, `page`, `more`).

### `GET /terms?taxonomy=category`

The terms of a public taxonomy, by name, with `description`: `{ "taxonomy": "category", "items": [ … ] }`. `&slug=x` gives that one term (404-free: an empty list when there is none); `&hide_empty=0` includes terms without entries; `&per_page=` (up to 500) and `&offset=` page through a big taxonomy.

### `GET /authors`

Everyone with something published: `{ "items": [ { "id", "name", "slug", "path" } ] }`.

### `GET /paths`

Every public address with its last change, for a sitemap: `{ "items": [ { "path", "modified", "type" } ], "total", "pages", "page" }`, 500 a page at most. Entries marked *noindex* in the SEO plugin are left out. `&with=terms,authors,archives` adds the term pages, the author pages and the type lists on page 1. `&type=post` gives one type only, in a fixed order, so a sitemap per type can be built. A site with tens of thousands of entries builds its sitemaps from the archives instead.

### Archives and the search feed

For sites too big to page through.

- `GET /months?type=`: every month with entries: `{ "items": [ { "month": "2026-10", "count": 3, "modified": "…" } ] }`.
- `GET /month?month=2026-10&type=`: that month's entries, up to 5,000: `id`, `type`, `path`, `title`, `date`, `local`, `modified`. Noindex entries left out, so a sitemap can be built from it.
- `GET /day?day=2026-10-13&type=`: that day's entries as cards, up to 400.
- `GET /index?after=<id>&limit=<up to 500>&type=`: entries in number order with their plain `text`, for a search engine the front end keeps (Typesense and the like): `{ "items": [ … ], "next": 20 }`, `next` being the cursor for the following call. Never kept.

Months and days are the site's own wall clock.

### `GET /feed`

The same question as `/posts` (`type`, `category`, `tag`, `tax`, `term`, `per_page`), answered as an RSS 2.0 document whose every address is on the front end, so a front end answers `/feed/` and `/category/<name>/feed/` by passing it on.

### `GET /layout?name=home`

A page made of groups of cards (a news home page, a side column), described by the site and answered in one call: `{"name": "home", "groups": {"lead": {card} | null, "news": [cards], "more": {"articles": [cards], ...}}}`. Without `name`: `{"layouts": ["home", "side"]}`; an unknown name: 404. Kept like every answer; behind the door's rule.

The site describes its make-ups with the filter `clientnav_headless_layouts` (in its add-on). Each group: `sections` and `not_sections` (slugs) in `taxonomy` (default `category`), `type` (default `post`), `count` (default 5, at most 50), `excerpt` (characters), `picture` (`true`: a featured image; a field name: that field is set), `sticky` (`true`: the posts marked "Stick to the top", else the group's sections), `hide` (a field name: posts with it set are left out), `unique` (default `true`: nothing a group above showed), `one` (`true`: one card or `null`). A group holding only `groups` is a set, answered in place. Groups are answered in the order given, so "nothing shown twice" follows the page from top to bottom.

A make-up is code, not content: after changing or removing one, its kept answer is renewed within ten minutes or at the next content change, and the front end's own copy at its next refresh call (or its revalidate time).

### Podcasts: `GET /episodes` and `GET /episodes/feed`

With Client Navigator Podcast on the CMS (plugin 0.25.0; the shape fixed with the podcast plugin author, ``).

- `GET /episodes?show=&page=&per_page=&lang=` (per_page 20 by default, at most 100): `{ show, items, total, pages, page, more }`. `show` is the show (title, plain description, author, language, image, categories, explicit, kind, podcast guid, home and feed as paths, listen links, updated; never the owner e-mail); each item is the usual card plus `episode` (guid as the feed gives it, `audio: { src, type, length, seconds, duration }`, season, number, kind, explicit, image, transcript, chapters). A site without the plugin or without a show: 200 with `show: null`, so a front end can tell "no podcast" from "WordPress down". An unknown `show`: 404.
- `GET /episodes?shows=1`: `{ shows: [ { slug, title, feed, count } ] }`.
- `GET /episodes/feed?show=`: the show's RSS (`application/rss+xml`) with the front end's addresses: channel and episode links, show notes' links, and `atom:link rel="self"` = front end + `show.feed`. Guids, the podcast guid and audio addresses are left exactly as they are. 404 for no show. A front end answers `show.feed` (`/feed/podcast/`) by passing this on; it never follows a redirect and never changes a published feed address.
- The plugin's own `/feed/<show>/` on the CMS names the front end too (filter `clientnav_podcast_feed_args`). A show's settings changing (term meta, the plugin's options) forgets the kept answers.

### `GET /search-index`

For small sites: every entry with its words, in one answer, kept for ten minutes; off on big sites and switchable off in the settings.

### Designed pages: `with=elementor` and `GET /part`

Pages their staff design in Elementor come as a tree of plain parts, not as Elementor's markup, so a front end draws them with its own components and the styles of V4 (atomic) elements come as CSS scoped under a wrapper class.

On `/resolve?path=…&with=elementor` an entry built in Elementor carries:

```json
"elementor": { "parts": [ { "t": "box", "base": "e-flexbox", "el": "div", "cls": [ "…" ], "kids": [ … ] } ], "css": ".cnh-el .e-6374b31{…}", "scope": ".cnh-el", "faq": [ { "question": "…", "answer": "…" } ] }
```

`t` names the part: `box`, `h` (heading), `p`, `btn`, `img`, `svg`, `yt`, `hr`, `rich`, `list`, `ibox`, `quote`, `stars`, `social`, `map`, `search`, `form`, `cards` (a list of entries as cards), `counter`, `gallery`, and `x` for an element the plugin does not know (`widget` says which). Parts carry `cls`, `id`, `link { href, blank }`, `hide [desktop|tablet|mobile]` where they apply, and `kids`. `faq` is the questions and answers of accordions marked for search engines.

`GET /part?where=header` or `footer`: the theme builder's header or footer, the same way: `{ "where", "parts", "css", "scope", "nav" }`, `nav` being a mega menu's items with their panels when the header has one.

### Languages

With Client Navigator Translate (0.10.4 or later) on the WordPress, any answer can be asked in a language the site translates into, with `?lang=es` on `/site`, `/resolve`, `/posts`, `/terms`, `/part`, `/forms/…`, or by an address in the language on `/resolve`. The answer is the same answer in that language: words translated (hand translations as written), every address of the site as that language writes it, and `lang: { "code": "es", "total": 48, "missing": 9 }`, how many pieces were asked and how many have no translation yet, so a front end can hold search engines off a page that is mostly untranslated.

- `/resolve?…&with=versions`: the entry carries `versions`, its address in every language (`{ "en": "/faq/", "es": "/es/preguntas-frecuentes/" }`), for a language switch and `hreflang`; also in the site's own language.
- `GET /where?path=/es/preguntas-frecuentes/`: `{ "path": "/faq/", "alt": { "en": "/faq/", "es": "/es/preguntas-frecuentes/" } }` for any address, list pages included; the address WordPress knows and every language's. 404 without Translate.

Phrases a site keeps as they are in every language (place names) are fixed by its add-on.

### Forms

`GET /forms/<number>` describes a Fluent Forms form, `GET /forms/<name>` one of the plugin's own (`inquiry`, `newsletter`, or a site's own):

```json
{ "id": 4, "title": "Job Application", "fields": [ { "name": "input_text_1", "label": "Your Phone Number", "placeholder": "", "required": true, "type": "tel", "auto": "tel" } ],
  "button": "Send Application", "thanks": "Thank you for your message…", "trap": "item_4__fluent_sf", "sitekey": "0x4AAA…",
  "send": { "via": "fluentform", "url": "https://cms.example.com/wp-admin/admin-ajax.php", "action": "fluentform_submit", "form_id": 4 } }
```

Field `type` is one of `text`, `tel`, `email`, `textarea`, `date`, `hidden`, `select`, `radio`, `checks`, `group` (with `fields`), `note` (with `html`); `options` for choices; `auto` the browser's autocomplete hint. `trap` is the honeypot field the form plugin expects empty; `sitekey` Turnstile's public key when it is on.

Sending stays the form plugin's work: the front end's server posts the visitor's answers where `send` says, with Client Navigator Anti-Spam's signature when that is on (the Anti-Spam kit), so validation, Turnstile, the saved entry, the notice and the CRM rules run as on any WordPress page. The plugin's own forms are posted to `POST /forms/<name>` with `Authorization: Bearer <shared secret>` and `{ "fields": { … }, "ip": "…", "page": "/contact/" }`, and are judged by Anti-Spam before anything is kept. Form answers are never kept in the door's cache.

### Previews

WordPress's Preview button opens `<front end>/api/preview/?token=…`, a signed, short-lived token (`base64url(claims).base64url(HMAC-SHA256)`, claims `pid`, `uid`, `scope`, `exp`, signed with the shared secret). The front end checks it with the same secret and reads the draft from

`GET /preview?token=…`: `{ "type": "entry", "status": "draft", "entry": { … } }`, the entry as its editor last left it (the newest autosave). In a preview every block's first element carries `data-cnh-block="<path>"` and the block tree carries `path`, so a click in the editor's split view can select the block (the plugin's `assets/preview-bridge.js`). A token no longer valid answers 401.

`GET /preview-link?post=<id>` (signed-in editors only): a fresh preview address, for the editor's live preview.

### Pairing and the shared secret

`POST /pair` with `{ "code": "…" }`, the one-time code an administrator makes under Settings > Headless (good for fifteen minutes, one use), answers `{ "ok": true, "secret": "…" }`. The front end keeps the secret in its own settings; nobody reads it. The secret signs previews, protects the refresh call and, with the door closed, is the front end's key.

### The closed door

With the setting "Door: closed" every read route answers only a request carrying the secret, `Authorization: Bearer <secret>` or `X-ClientNav-Door-Key: <secret>`, or a signed-in editor. Anyone else gets

```json
HTTP 401  { "code": "clientnav_headless_closed", "message": "This door answers its front end only.", "data": { "status": 401 } }
```

Previews keep their signed token and `/pair` its code. "WordPress REST API: closed" does the same for `/wp-json/wp/v2/…` and other plugins' routes, leaving Client Navigator's, PR Register's, Translate's and oEmbed open.

### Refresh

When something public changes, the plugin calls the front end: `POST <front end>/api/revalidate/` with `Authorization: Bearer <secret>` and `{ "paths": [ "/a/", "/category/x/" ], "path": "/a/", "gone": [ 123 ], "all": false }`; `all: true` when every page changes (menus, terms, the site's name, sticky entries, a menu put in another place). The last ten calls are on the settings page and in the status ability.

## Errors

- `400` `{ "code": "clientnav_headless_unknown", "message": "No such type." }` — a type or taxonomy that is not public, or a bad question.
- `401` the closed door (above), or a preview token no longer valid.
- `404` `{ "type": "none" }` — nothing at that address; a list page past the end.

Every answer carries `Cache-Control: no-cache`: the door keeps its own answers, nothing on the way may keep them, so a publish shows on the front end's next request.

## What the door does not do

It never writes (except a form submission and the pairing handshake), never answers drafts (except a signed preview), never shows users beyond authors with published entries, and never hands out a field a type does not show to WordPress's own REST API. Elementor's, the SEO plugin's and Translate's own routes stay theirs.

*Plugin 0.20.2, 2026-10-15. The plugin's own settings, the editor's live preview and the front-end kit have their own pages.*
