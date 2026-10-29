import type { Metadata } from "next";
import { cookies } from "next/headers";
import { previewBridge, previewToken } from "@clientnav/headless/routes";
import { Article } from "@/components/parts";
import { CMS_URL, SECRET, door } from "@/lib/cms";

export const dynamic = "force-dynamic";
export const metadata: Metadata = { title: "Preview", robots: { index: false, follow: false } };

type Props = { params: Promise<{ id: string }> };

/**
 * The unsaved state of one entry, for the browser that holds its signed token (set by /api/preview/).
 * The bridge lets the editor's split view on the CMS select a block by a click here (plugin 0.17.0).
 */
export default async function PreviewPage({ params }: Props) {
  const { id } = await params;
  const token = previewToken((await cookies()).toString(), id, SECRET);
  const found = token ? await door.preview(token) : null;
  if (!found?.entry) {
    return (
      <div className="article">
        <h1>This preview has run out.</h1>
        <p>Click Preview in WordPress again to open a new one.</p>
      </div>
    );
  }
  return (
    <>
      <Article entry={found.entry} />
      <script src={previewBridge(CMS_URL)} defer />
    </>
  );
}
