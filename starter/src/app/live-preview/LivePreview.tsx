"use client";

import { useEffect, useRef, useState } from "react";
import { Article } from "@/components/parts";
import type { Raw } from "@/lib/cms";
import { draw } from "./actions";

/**
 * Listens for the editor's unsaved blocks and shows them as the CMS draws them. Messages count only from the
 * WordPress origin; the drawing is asked for on the server, with the window's token.
 */
export function LivePreview({ cmsOrigin, token }: { cmsOrigin: string; token: string }) {
  const [entry, setEntry] = useState<Raw | null>(null);
  const [lost, setLost] = useState(false);
  const turn = useRef(0);

  useEffect(() => {
    const onMessage = async (event: MessageEvent) => {
      if (event.origin !== cmsOrigin || event.data?.type !== "clientnav:blocks" || typeof event.data.content !== "string") return;
      const mine = ++turn.current;
      const drawn = await draw(token, String(event.data.title ?? ""), event.data.content).catch(() => null);
      if (mine !== turn.current) return; // A newer change arrived meanwhile.
      if (drawn === null) setLost(true);
      else setEntry(drawn);
    };
    window.addEventListener("message", onMessage);
    // Tell the editor this page is listening, in a window of its own or beside the editor; it answers with the blocks.
    const editor = window.opener ?? (window.parent !== window ? window.parent : null);
    editor?.postMessage({ type: "clientnav:ready", mode: "live" }, cmsOrigin);
    return () => window.removeEventListener("message", onMessage);
  }, [cmsOrigin, token]);

  if (lost) {
    return (
      <div className="article">
        <h1>This window has run out.</h1>
        <p>Open the live preview again from the editor in WordPress.</p>
      </div>
    );
  }
  return entry ? <Article entry={entry} /> : <p className="article">Waiting for the editor. Keep the WordPress tab open.</p>;
}
