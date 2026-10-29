/** Types for Client Navigator Anti-Spam's server half (vendor/antispam-server.mjs, copied unchanged from the Connector). */
export const HEADER_TIME: string;
export const HEADER_IP: string;
export const HEADER_SIGNATURE: string;
export type GuardConfig = { enabled: boolean; key: string; honeypot: string; fields: { key: string; time: string; acted: string }; min_seconds?: number; require_interaction?: boolean;
  /** Set when Anti-Spam's own Turnstile is on: the front end must draw it and pass its answer under turnstile_field. */
  turnstile_sitekey?: string; turnstile_field?: string };
export function sign(secret: string, time: string, ip: string, body: string): string;
export function signedHeaders(secret: string, ip: string, body?: string, now?: number): Record<string, string>;
export function frontendConfig(cmsOrigin: string, secret: string, options?: { maxAge?: number; fetchImpl?: typeof fetch }): Promise<GuardConfig | null>;
export function checkNames(config: GuardConfig | null): string[];
export function check(cmsOrigin: string, secret: string, ip: string, fields: [string, string][] | Record<string, string>, page?: string, options?: { fetchImpl?: typeof fetch }): Promise<{ ok: boolean; message?: string; unchecked?: boolean }>;
