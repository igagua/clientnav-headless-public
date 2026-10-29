import { previewHandler } from "@clientnav/headless/routes";
import { SECRET } from "@/lib/cms";

// Where WordPress's Preview button leads.
export const dynamic = "force-dynamic";
export const GET = previewHandler({ secret: SECRET });
