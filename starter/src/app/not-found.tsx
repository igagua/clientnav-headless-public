import Link from "next/link";
import { Designed } from "@/components/designed";
import { CMS_URL, door } from "@/lib/cms";

/** Nothing at this address: the site's own "not found" template when it has one (plugin 0.22.0), else a plain line. */
export default async function NotFound() {
  const part = await door.part("404").catch(() => null);
  if (part?.parts?.length) return <Designed parts={part.parts} css={part.css} scope={part.scope} assets={part.assets} scripts={part.scripts} cms={CMS_URL} as="section" className="page" />;
  return (
    <div className="article">
      <h1>Nothing lives at this address</h1>
      <p>
        <Link href="/">Go to the home page</Link>
      </p>
    </div>
  );
}
