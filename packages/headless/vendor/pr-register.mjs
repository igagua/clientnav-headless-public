// PR Register headless kit: what a front end needs to print the SEO of a WordPress back office that runs
// PR Register in headless mode. No dependencies; works in Node 18+, Next.js, Astro, Nuxt, SvelteKit, workers.
//
//   import { prRegister } from './pr-register.mjs'
//   const seo = prRegister('https://cms.example.com')        // the back office's address
//   const head = await seo.head('/about-us/')                // { found, status, title, description, canonical,
//                                                            //   robots, og, twitter, schema, breadcrumbs,
//                                                            //   summary, faq, html }  or  { status: 301, redirect }
//
// See README.md beside this file for the page, sitemap, robots.txt, llms.txt and redirect recipes.

export function prRegister(backOffice, { fetch: doFetch = fetch, revalidate = 300 } = {}) {
  const base = String(backOffice).replace(/\/+$/, '') + '/wp-json/prregister/v1'

  async function get(route, params = {}) {
    const url = new URL(base + route)
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value))
    }
    // `next` is read by Next.js and ignored everywhere else.
    const answer = await doFetch(url, { headers: { accept: 'application/json' }, next: { revalidate } })
    if (!answer.ok && answer.status !== 404) {
      throw new Error(`PR Register answered ${answer.status} for ${route}`)
    }
    return answer.json()
  }

  return {
    /** One page. Give a path ("/about-us/"), or { id }, { term } or { type } for a post, a term or a type's list. */
    head: (pathOrWhat) => get('/head', typeof pathOrWhat === 'string' ? { path: pathOrWhat } : pathOrWhat),
    /** A sitemap's XML: "sitemap_index.xml" first, then the files it names. Null when there is none by that name. */
    sitemap: async (file = 'sitemap_index.xml') => (await get('/sitemap', { file })).xml || null,
    /** The text of robots.txt for the front end. */
    robots: async () => (await get('/robots')).text,
    /** The text of llms.txt, or null when it is switched off. */
    llms: async () => (await get('/llms')).text || null,
    /** The redirects in force: [{ source, match, target, code, ignore_case }]. */
    redirects: async () => (await get('/redirects')).rules,
    /** Who publishes the site and what is switched on. */
    site: () => get('/site'),
  }
}

/** Whether a redirect rule catches a path ("old-page", no slashes at the ends). Returns the target, '' for gone, or null. */
export function matchRedirect(rules, path) {
  const clean = decodeURIComponent(String(path).split('?')[0]).replace(/^\/+|\/+$/g, '')
  for (const rule of rules) {
    const subject = rule.ignore_case && rule.match !== 'regex' ? clean.toLowerCase() : clean
    const source = rule.ignore_case && rule.match !== 'regex' ? rule.source.toLowerCase() : rule.source
    let fits = false
    let target = rule.target
    if (rule.match === 'exact') fits = subject === source
    else if (rule.match === 'start') fits = subject.startsWith(source)
    else if (rule.match === 'end') fits = source !== '' && subject.endsWith(source)
    else if (rule.match === 'contains') fits = subject.includes(source)
    else if (rule.match === 'regex') {
      try {
        const found = new RegExp(rule.source, rule.ignore_case ? 'i' : '').exec(clean)
        fits = !!found
        if (found) target = target.replace(/\$(\d)/g, (_, n) => found[Number(n)] ?? '')
      } catch {
        fits = false
      }
    }
    if (fits) return { target, code: rule.code }
  }
  return null
}

/** For Next.js: a page's answer as the object `generateMetadata` returns. Structured data is printed separately (see README). */
export function toNextMetadata(head) {
  if (!head || !head.found) return { title: head?.title, robots: 'noindex, follow' }
  const image = head.og?.image?.url ? [{ url: head.og.image.url, width: head.og.image.width, height: head.og.image.height }] : undefined
  return {
    title: { absolute: head.title },
    description: head.description || undefined,
    alternates: head.canonical ? { canonical: head.canonical } : undefined,
    robots: head.robots || undefined,
    openGraph: {
      type: head.og?.type === 'article' ? 'article' : 'website',
      title: head.og?.title,
      description: head.og?.description,
      url: head.og?.url,
      images: image,
      publishedTime: head.og?.published || undefined,
      modifiedTime: head.og?.modified || undefined,
    },
    twitter: { card: head.twitter?.card, title: head.twitter?.title, description: head.twitter?.description, images: head.twitter?.image ? [head.twitter.image] : undefined },
  }
}

/** The page's structured data as the text of one <script type="application/ld+json">, or '' when there is none. */
export function jsonLd(head) {
  return head?.schema?.length ? JSON.stringify({ '@context': 'https://schema.org', '@graph': head.schema }).replace(/</g, '\\u003c') : ''
}
