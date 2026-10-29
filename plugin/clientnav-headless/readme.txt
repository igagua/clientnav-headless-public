=== Client Navigator Headless ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later

Makes WordPress the content office of a separate front end.

== What it does ==
* Content door (read only): /wp-json/clientnav-headless/v1/site, /resolve?path=, /posts, /terms, /authors, /paths.
  Lists by type, category, tag, any public taxonomy, author; cards carry all terms, the main section and the author;
  entries carry the extra fields their type shows in WordPress's own REST API. On request (with=meta, with=sizes)
  also other plugins' fields and every size of a picture; /terms?slug= for one term; /paths?type= for one type
* Four menu places for the front end: main menu, top bar, footer columns, footer bottom line
* Tells the front end what to refresh when something is published or changed
* SEO for the front end: every entry, term and list the door hands out carries "seo". With PR Register 0.21 or
  later on this WordPress it holds that plugin's title, description, canonical, robots, og, twitter, schema,
  breadcrumbs, summary and faq (source "pr-register"); otherwise the entry's own title and description (source "door")

* Summaries cut by character count on request (excerpt=<chars>), for lists in languages where a word count is no measure
* Speed for big archives: every answer kept inside WordPress until something public changes (one request works it
  out, the others get the last one), lists on very big types without a full count, big sections the fast way,
  no page past 5,000
* /feed: RSS with front end addresses for the home page and any section
* Archives /months, /month, /day; /index, a cursor-based feed of plain text for a search engine (Typesense)
* For site add-ons: local wall-clock dates, card and picture filters, an old-address filter, several-section and
  "everything except" lists, lookups by id and by type folder; noindex entries out of /paths and /month;
  pictures as paths (setting "Pictures and files"); REST and preview links kept on the WordPress Address
* Client Navigator abilities: clientnav/headless-plugin-status, clientnav/headless-pair, clientnav/headless-check
* Closed door (Settings > Headless > Door: "closed"): the read routes answer only the front end with the shared secret
  (Authorization: Bearer, or X-ClientNav-Door-Key) and signed-in editors; previews and pairing keep their own locks.
  "WordPress REST API: closed" does the same for /wp-json/wp/v2 and every other route, leaving Client Navigator,
  PR Register, Translate, oEmbed and this door open (filter clientnav_headless_open_rest)
* Editor live preview: beside the editor (split view) or in a window; "Instant" sends unsaved blocks to a front end
  with /live-preview/, "Draft" autosaves after each pause and reloads the front end's own preview (every front end).
  Click-to-edit: previews mark each block (data-cnh-block); the front end's preview page loads
  assets/preview-bridge.js from WordPress, and a click there selects the block in the editor
* Pair check: Settings > Headless > Check the front end, the ability clientnav/headless-check or
  wp clientnav-headless check: is the front end there, and does it hold the same secret (a signed test preview it
  must accept); the last check and the last ten refresh calls are shown on the settings page and in the status
* Elementor pages: /resolve?with=elementor gives a page designed in Elementor as plain parts (V4 atomic elements with
  their CSS under a wrapper class, classic widgets, loops, FAQ lists, Fluent Forms inside), /part?where=header|footer
  the theme builder's header and footer (header with its mega menu); a site add-on resolves its own widgets
  (clientnav_headless_elementor_part)
* Languages through Client Navigator Translate (0.10.4+): ?lang=es on /site, /resolve, /posts, /terms, /part, and
  /es/... addresses on /resolve; entries from Translate's own fields, lists, terms, menus, SEO words and Elementor
  pieces translated with their roles, addresses in the language, "versions" for hreflang, "lang" with what is missing
* Languages, more: ?with=versions on /resolve gives an entry's address in every language; GET /where?path= gives any address
  in every language and the one WordPress knows (Translate 0.10.4+)
* Forms: GET /forms/<number> describes a Fluent Forms form (fields, button, thank-you words, honeypot name, Turnstile's
  site key, how to send it), GET /forms/<name> one of the plugin's own; the plugin's own forms are judged by Client
  Navigator Anti-Spam (headless mode) before anything is kept

== Setting up ==
The front end's address comes from the Client Navigator Connector (0.45.0 or later): constant CLIENTNAV_FRONT_URL
in wp-config.php or the ability clientnav/headless-set. Without such a Connector: the constant CLIENTNAV_FRONT_URL,
then Settings > Headless (or the constant CLIENTNAV_HEADLESS_FRONTEND), then the filter clientnav_front_url.
Connecting a front end: Settings > Headless > Make a pairing code (one use, 15 minutes); the front end's machine
sends it to /wp-json/clientnav-headless/v1/pair and gets the shared secret back, so nobody copies it by hand.
Settings > Headless also holds the shared secret (the front end's REVALIDATE_SECRET). Values can be fixed in
wp-config.php instead: CLIENTNAV_HEADLESS_REFRESH, CLIENTNAV_HEADLESS_HOSTS, CLIENTNAV_HEADLESS_SECRET.
A site with its own design keeps the Client Navigator page blocks out of its editor: "off" under Client Navigator
blocks, or CLIENTNAV_HEADLESS_BLOCKS set to 'off'. The content door, previews and the refresh call work the same.
The search index (/search-index, for small sites) is kept for ten minutes and can be switched off the same way:
"off" under Search index, or CLIENTNAV_HEADLESS_SEARCH_INDEX set to 'off'.

== Filters ==
clientnav_headless_own_hosts, clientnav_headless_post_types, clientnav_headless_entry, clientnav_headless_seo,
clientnav_headless_section, clientnav_headless_author, clientnav_headless_fields, clientnav_headless_card, clientnav_headless_keep_words,
clientnav_headless_image, clientnav_headless_old_path, clientnav_headless_noindex, clientnav_headless_index_item,
clientnav_headless_media_paths, clientnav_headless_big, clientnav_headless_keep, clientnav_headless_door_closed,
clientnav_headless_rest_closed, clientnav_headless_open_rest, clientnav_headless_orderby, clientnav_headless_query, clientnav_headless_lang_fields
