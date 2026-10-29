import { feedHandler } from "@clientnav/headless/routes";
import { door } from "@/lib/cms";

// The RSS feed, as the CMS writes it with this site's addresses.
export const revalidate = 300;
export const GET = feedHandler({ door });
