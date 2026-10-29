import { prRegister } from "@clientnav/headless/pr-register";
import { CMS_URL } from "./cms";

/** PR Register on the CMS, when it is there: sitemaps, robots.txt, llms.txt and redirects written for this front end. */
export const prr = prRegister(CMS_URL, { revalidate: 300 });

/** Whether the CMS runs PR Register in headless mode (asked once per answer life, cheap). */
export async function hasPrr(): Promise<boolean> {
  try {
    const s = await prr.site();
    return !!s && typeof s === "object" && !("code" in s);
  } catch {
    return false;
  }
}
