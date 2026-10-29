"use server";

import { verifyPreviewToken } from "@clientnav/headless/token";
import { door, SECRET, type Raw } from "@/lib/cms";

/** The unsaved blocks drawn by the CMS as the entry they belong to; null once the window's token is no longer good. */
export async function draw(token: string, title: string, content: string): Promise<Raw | null> {
  if (!verifyPreviewToken(token, "live", SECRET) || content.length > 1048576) return null;
  const found = await door.live(token, { title, content });
  return found?.entry ?? null;
}
