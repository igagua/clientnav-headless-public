import type { Metadata } from "next";
import { verifyPreviewToken } from "@clientnav/headless/token";
import { previewBridge } from "@clientnav/headless/routes";
import { CMS_URL, SECRET } from "@/lib/cms";
import { LivePreview } from "./LivePreview";

export const dynamic = "force-dynamic";
export const metadata: Metadata = { title: "Live preview", robots: { index: false, follow: false } };

type Props = { searchParams: Promise<{ token?: string }> };

/**
 * Opened by the editor's live preview ("Instant"), beside the editor or in a window. The unsaved blocks arrive from
 * the editor as they are typed; the CMS draws them (POST /live) and they are shown as a published entry is.
 */
export default async function LivePreviewPage({ searchParams }: Props) {
  const { token } = await searchParams;
  if (!token || !verifyPreviewToken(token, "live", SECRET)) {
    return (
      <div className="article">
        <h1>This window has run out.</h1>
        <p>Open the live preview again from the editor in WordPress.</p>
      </div>
    );
  }
  return (
    <>
      <LivePreview cmsOrigin={new URL(CMS_URL).origin} token={token} />
      {/* Beside the editor: click-to-edit. */}
      <script src={previewBridge(CMS_URL)} data-mode="live" defer />
    </>
  );
}
