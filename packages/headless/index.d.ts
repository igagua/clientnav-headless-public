// Types for @clientnav/headless (the code is plain JavaScript modules).
export declare class DoorDown extends Error {}
export declare const NS: string;
type Query = Record<string, string | number | boolean | string[] | undefined | null>;
/* eslint-disable @typescript-eslint/no-explicit-any */
export interface Door {
  url(route: string, query?: Query): string;
  get<T = any>(route: string, query?: Query, opts?: { fresh?: boolean }): Promise<T | null>;
  site<T = any>(q?: Query): Promise<T | null>;
  resolve<T = any>(path: string, q?: Query): Promise<T | null>;
  posts<T = any>(q?: Query): Promise<T | null>;
  terms<T = any>(q?: Query): Promise<T | null>;
  authors<T = any>(q?: Query): Promise<T | null>;
  paths<T = any>(q?: Query): Promise<T | null>;
  months<T = any>(q?: Query): Promise<T | null>;
  month<T = any>(month: string, q?: Query): Promise<T | null>;
  day<T = any>(day: string, q?: Query): Promise<T | null>;
  index<T = any>(q?: Query): Promise<T | null>;
  /** A theme-builder part: the header or footer, or the template for a kind of page in that page's context
   *  (plugin 0.22.0: "single" and "archive" with { path }, "search" with { search }, "404"), or a popup with { id }. */
  part<T = any>(where: "header" | "footer" | "single" | "archive" | "search" | "404" | "popup", q?: Query): Promise<T | null>;
  form<T = any>(idOrName: number | string, q?: Query): Promise<T | null>;
  preview<T = any>(token: string, q?: Query): Promise<T | null>;
  layout<T = any>(name?: string, q?: Query): Promise<T | null>;
  /** A podcast show and its episodes (plugin 0.25.0); { shows: true } lists the shows. */
  podcast<T = any>(q?: { show?: string; page?: number; perPage?: number; shows?: boolean; lang?: string }): Promise<T | null>;
  /** A show's podcast feed as RSS text; null when there is none. */
  podcastFeed(q?: { show?: string }): Promise<string | null>;
  live<T = any>(token: string, body: { title?: string; content: string }): Promise<T | null>;
  feed(q?: Query): Promise<string | null>;
}
export declare function createDoor(opts: { cms: string; key?: string; fetch?: typeof fetch; next?: { revalidate?: number | false; tags?: string[] }; timeout?: number }): Door;

export type PreviewScope = "preview" | "live";
export type PreviewClaims = { pid: number; uid: number; scope: PreviewScope; exp: number };
export declare const PREVIEW_COOKIE: "cn_preview";
export declare function verifyPreviewToken(token: string | null | undefined, scope: PreviewScope, secret: string | undefined, now?: number): PreviewClaims | null;
export declare function signPreviewToken(claims: PreviewClaims, secret: string): string;
export declare function sameSecret(a: string | null | undefined, b: string | null | undefined): boolean;

type Handler = (request: Request) => Promise<Response>;
export declare function previewHandler(opts: { secret: string | undefined; page?: (id: number) => string; cookiePath?: string; sameSite?: "Lax" | "Strict" | "None"; secure?: boolean }): Handler;
export declare function previewToken(cookieHeader: string | null | undefined, id: number | string | undefined, secret: string | undefined): string | null;
export declare function exitPreviewHandler(opts?: { to?: string; cookiePath?: string; sameSite?: "Lax" | "Strict" | "None"; secure?: boolean }): Handler;
export declare function revalidateHandler(opts: { secret: string | undefined; refresh?: (what: { paths: string[]; all: boolean; gone: number[] }) => unknown; max?: number }): Handler;
export declare function feedHandler(opts: { door: Door; category?: (request: Request) => string | undefined; maxAge?: number }): Handler;
export declare function previewBridge(cms: string): string;

export declare function plain(s: string): string;
export declare function seoOf(answer: any): any;
export declare function localize<T>(seo: T, opts?: { site?: string; cmsFront?: string | string[] }): T;
export declare function metadata(seo: any, opts?: { site?: string; cmsFront?: string | string[]; path?: string }): Record<string, any>;
export declare function jsonLd(seo: any, opts?: { site?: string; cmsFront?: string | string[] }): string;

/** next.config settings: Next's page and fetch cache in memory only, capped at memoryMB (default 64), never on disk. */
export declare function withCacheLimit<T extends Record<string, any>>(config?: T, opts?: { memoryMB?: number }): T & { cacheMaxMemorySize: number; experimental: Record<string, any> & { isrFlushToDisk: false } };

/** Matomo for a headless front end: counts only on the site's own address (www and bare), decided in the browser. */
export interface MatomoTracker {
  /** Whether visits are counted on this page's host. */
  on: boolean;
  /** Counts the page now shown (first page and after every client-side navigation). */
  page(title?: string): boolean;
  /** Counts the page once its own title is set (waits up to 1.5 s after a client-side move). */
  pageWhenReady(): Promise<boolean>;
  /** A site search, counted as Matomo's site search. */
  search(keyword: string, count?: number): boolean;
}
export declare function matomo(opts: { url?: string; siteId?: string | number; site?: string; cookies?: boolean; doNotTrack?: boolean; win?: any; doc?: any }): MatomoTracker;
export declare function countedHosts(site: string): string[];
/** The page's title once a navigation has set it (differs from before), or whatever it is after wait ms. */
export declare function newTitle(before: string, opts?: { doc?: any; wait?: number }): Promise<string>;
export declare function counts(site: string, host: string): boolean;
