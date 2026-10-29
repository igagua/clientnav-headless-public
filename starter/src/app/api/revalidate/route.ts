import { revalidatePath, revalidateTag } from "next/cache";
import { revalidateHandler } from "@clientnav/headless/routes";
import { SECRET } from "@/lib/cms";

// Called by the CMS when something is published or changed: the kept answers go, and the pages it names.
export const dynamic = "force-dynamic";
export const POST = revalidateHandler({
  secret: SECRET,
  refresh: ({ paths, all }) => {
    revalidateTag("wp", { expire: 0 });
    if (all) revalidatePath("/", "layout");
    else for (const p of paths) revalidatePath(p);
  },
});
