/** Types for PR Register's headless kit (vendor/pr-register.mjs, copied unchanged from PR Register). */
export type PrrHead = {
  found: boolean;
  status: number;
  kind?: string;
  id?: number;
  title?: string;
  description?: string;
  canonical?: string;
  robots?: string;
  og?: Record<string, string>;
  twitter?: Record<string, string>;
  schema?: unknown[];
  breadcrumbs?: { name: string; url: string }[];
  summary?: string;
  faq?: { question: string; answer: string }[];
  html?: string;
  redirect?: string;
};
export type PrrRedirect = { source: string; match: string; target: string; code: number; ignore_case: boolean };
export type PrRegister = {
  head: (pathOrWhat: string | { id?: number; term?: number; type?: string }) => Promise<PrrHead>;
  sitemap: (file?: string) => Promise<string | null>;
  robots: () => Promise<string>;
  llms: () => Promise<string | null>;
  redirects: () => Promise<PrrRedirect[]>;
  site: () => Promise<Record<string, unknown>>;
};
export function prRegister(backOffice: string, options?: { fetch?: typeof fetch; revalidate?: number }): PrRegister;
export function matchRedirect(rules: PrrRedirect[], path: string): string | null;
export function toNextMetadata(head: PrrHead): Record<string, unknown>;
export function jsonLd(head: PrrHead): string;
