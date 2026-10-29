import { frontendConfig } from "@clientnav/headless/antispam";
import { CMS_URL } from "@/lib/cms";

/** What the browser half of Client Navigator Anti-Spam needs: the check fields' names and the key. Off when there is no secret. */
export const dynamic = "force-dynamic";

const ANTISPAM_SECRET = (process.env.CLIENTNAV_ANTISPAM_SECRET ?? "").trim();

export async function GET() {
  const config = ANTISPAM_SECRET ? await frontendConfig(CMS_URL, ANTISPAM_SECRET) : null;
  return Response.json(config && config.enabled ? config : { enabled: false }, { headers: { "Cache-Control": "private, max-age=300" } });
}
