/**
 * Live preview, editor side. The page as the front end draws it, beside the editor (split view) or in a window,
 * following the editing:
 *
 * - "Instant", when the front end has /live-preview/: the current, unsaved blocks are sent to it on every change
 *   and it draws them with the real site's components. Nothing is saved and WordPress is not asked again.
 *   Message: { type: "clientnav:blocks", title, blocks: [{ name, attrs, innerBlocks, html }] }, the shape the
 *   content door gives. Client Navigator blocks travel as their values, all others as their saved HTML.
 *   "content" is the same blocks serialized: a front end without its own block components (the starter) has the
 *   door draw it (POST /live with the window's token) and shows the entry as the door's preview would.
 * - "Draft", on every front end with a Preview page: a moment after the typing stops the editor autosaves (as
 *   WordPress does every minute anyway: an autosave beside a published entry, the draft itself otherwise) and
 *   reloads the front end's preview with a fresh link. The reading place is kept.
 *
 * Click-to-edit: in a preview the door marks every block with its place in the editor (data-cnh-block, "0.2.1");
 * the front end's preview page loads the plugin's assets/preview-bridge.js, which sends a click as
 * { type: "clientnav:select", path } and outlines the block named by { type: "clientnav:highlight", path }.
 * Both sides name the other's origin; nothing is sent to or accepted from anywhere else.
 */
import { registerPlugin } from "@wordpress/plugins";
import { PluginDocumentSettingPanel } from "@wordpress/editor";
import { dispatch, select as pick, useSelect } from "@wordpress/data";
import { getBlockContent, serialize } from "@wordpress/blocks";
import { Button } from "@wordpress/components";
import { createPortal, useCallback, useEffect, useRef, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";

const config = window.clientnavHeadless || {};
let origin = null;
try {
  origin = config.frontend ? new URL(config.frontend).origin : null;
} catch {}

// A picture is stored as { id, url, ... }; the front end draws { src, ... }.
const value = (v) => (v && typeof v === "object" && "url" in v ? { src: v.url, width: v.width || 0, height: v.height || 0, alt: v.alt || "", srcset: "" } : v);

function node(block, path) {
  const own = block.name.startsWith("clientnav/");
  return {
    name: block.name,
    path,
    attrs: own ? Object.fromEntries(Object.entries(block.attributes).map(([k, v]) => [k, value(v)])) : {},
    innerBlocks: own ? block.innerBlocks.map((b, i) => node(b, `${path}.${i}`)) : [],
    html: own ? "" : getBlockContent(block),
  };
}

// The entry's own blocks as the editor's canvas holds them (core/editor's copies have other ids since
// WordPress 6.9), inside the post content block when the editor shows the page within its template.
const entryBlocks = () => {
  const be = pick("core/block-editor");
  const inTemplate = be.getBlocksByName ? be.getBlocksByName("core/post-content") : [];
  return inTemplate.length ? be.getBlocks(inTemplate[0]) : be.getBlocks();
};

/** "0.2.1" for a block of the editor, as the door marks it. */
function pathOf(clientId) {
  const be = pick("core/block-editor");
  const top = new Set(entryBlocks().map((b) => b.clientId));
  const chain = [...be.getBlockParents(clientId), clientId];
  const from = chain.findIndex((id) => top.has(id));
  return from < 0 ? null : chain.slice(from).map((id) => be.getBlockIndex(id)).join(".");
}

/** The editor's block at a path, or the deepest one that exists on the way. */
function blockAt(path) {
  let list = entryBlocks();
  let id = null;
  for (const part of String(path).split(".")) {
    const b = list[Number(part)];
    if (!b) break;
    id = b.clientId;
    list = b.innerBlocks;
  }
  return id;
}

const CSS = `
body.cnh-split .interface-interface-skeleton { right: var(--cnh-w); }
.cnh-pane { position: fixed; top: var(--cnh-top, 32px); right: 0; bottom: 0; width: var(--cnh-w); z-index: 99; display: flex; flex-direction: column; background: #f0f0f1; border-left: 1px solid #dcdcde; }
.cnh-pane__bar { display: flex; flex-wrap: wrap; align-items: center; gap: 2px 4px; padding: 6px 8px; background: #fff; border-bottom: 1px solid #dcdcde; font-size: 12px; }
.cnh-pane__title { font-weight: 600; margin-right: auto; }
.cnh-pane__note { width: 100%; color: #757575; }
.cnh-pane__stage { flex: 1; min-height: 0; overflow: auto; display: flex; justify-content: center; }
.cnh-pane__stage iframe { border: 0; background: #fff; height: 100%; max-width: 100%; }
.cnh-pane__grip { position: absolute; left: -4px; top: 0; bottom: 0; width: 8px; cursor: col-resize; }
body.cnh-dragging .cnh-pane iframe { pointer-events: none; }
@media (max-width: 960px) { body.cnh-split .interface-interface-skeleton { right: 0; } .cnh-pane { display: none; } }
`;
let styled = false;
const style = () => {
  if (styled || typeof document === "undefined") return;
  styled = true;
  const el = document.createElement("style");
  el.textContent = CSS;
  document.head.appendChild(el);
};

const WIDTHS = { phone: 390, tablet: 768, wide: null };
const store = {
  get: () => {
    try {
      return Number(window.localStorage.getItem("cnh-split-w")) || 0;
    } catch {
      return 0;
    }
  },
  set: (w) => {
    try {
      window.localStorage.setItem("cnh-split-w", String(w));
    } catch {}
  },
};

function Live() {
  const { blocks, title, postId, selected } = useSelect(
    (s) => ({
      blocks: s("core/editor").getEditorBlocks ? s("core/editor").getEditorBlocks() : s("core/block-editor").getBlocks(),
      title: s("core/editor").getEditedPostAttribute("title"),
      postId: s("core/editor").getCurrentPostId(),
      selected: s("core/block-editor").getSelectedBlockClientId(),
    }),
    []
  );
  const [where, setWhere] = useState(null); // "beside" or "window"
  const [mode, setMode] = useState(config.livePage === false ? "draft" : "instant");
  const [device, setDevice] = useState("wide");
  const [width, setWidth] = useState(() => store.get() || Math.round(window.innerWidth * 0.45));
  const [src, setSrc] = useState("");
  const [note, setNote] = useState("");
  const frame = useRef(null);
  const win = useRef(null);
  const ready = useRef(false);
  const y = useRef(0);
  const shown = useRef("");
  const busy = useRef(false);
  const again = useRef(false);

  const target = useCallback(() => {
    if (where === "beside") return frame.current?.contentWindow || null;
    if (where === "window" && win.current && !win.current.closed) return win.current;
    return null;
  }, [where]);

  const post = useCallback((msg) => target()?.postMessage(msg, origin), [target]);

  const go = useCallback(
    (url) => {
      ready.current = false;
      if (where === "beside") setSrc(url);
      else if (win.current && !win.current.closed) win.current.location.href = url;
    },
    [where]
  );

  const sendBlocks = useCallback(() => {
    if (ready.current && mode === "instant") post({ type: "clientnav:blocks", title, blocks: blocks.map((b, i) => node(b, String(i))), content: serialize(blocks) });
  }, [blocks, title, mode, post]);

  // Draft: autosave if something changed, then load the front end's preview with a fresh link.
  const showDraft = useCallback(async () => {
    if (busy.current) {
      again.current = true;
      return;
    }
    busy.current = true;
    try {
      const editor = pick("core/editor");
      const now = `${editor.getEditedPostAttribute("title")}\n${editor.getEditedPostContent()}`;
      if (now === shown.current) return;
      if (editor.isEditedPostDirty() && editor.isEditedPostAutosaveable() && !editor.isSavingPost()) {
        setNote("Saving a draft for the preview…");
        await dispatch("core/editor").autosave();
      }
      const answer = await apiFetch({ path: `/clientnav-headless/v1/preview-link?post=${postId}` });
      if (!answer?.url) {
        setNote("The front end cannot show a draft: it is not paired with this WordPress yet.");
        return;
      }
      shown.current = now;
      go(answer.url);
      setNote("Shows the draft a moment after you stop typing. Click a part of it to select it here.");
    } catch (e) {
      setNote(`The draft could not be shown: ${e?.message || "no answer"}`);
    } finally {
      busy.current = false;
      if (again.current) {
        again.current = false;
        setTimeout(() => showDraftRef.current(), 300);
      }
    }
  }, [postId, go]);
  const showDraftRef = useRef(showDraft);
  showDraftRef.current = showDraft;

  // Opening, or switching between instant and draft.
  useEffect(() => {
    if (!where) return undefined;
    style();
    shown.current = "";
    y.current = 0;
    if (mode === "draft") {
      showDraft();
      return undefined;
    }
    setNote("Follows your typing, before anything is saved.");
    go(`${config.frontend}/live-preview/?token=${encodeURIComponent(config.liveToken)}`);
    // A front end without its own live page never says it is listening: show the draft instead.
    const t = setTimeout(() => {
      if (!ready.current) setMode("draft");
    }, 6000);
    return () => clearTimeout(t);
  }, [where, mode]); // eslint-disable-line react-hooks/exhaustive-deps

  // Instant: sent a moment after the last keystroke, so fast typing does not flood the front end.
  useEffect(() => {
    const t = setTimeout(sendBlocks, 150);
    return () => clearTimeout(t);
  }, [sendBlocks]);

  // Draft: a little longer, since each one is a save and a page load.
  useEffect(() => {
    if (!where || mode !== "draft") return undefined;
    const t = setTimeout(showDraft, 1500);
    return () => clearTimeout(t);
  }, [blocks, title, where, mode, showDraft]);

  // The block selected here is outlined there.
  const highlight = useCallback(() => post({ type: "clientnav:highlight", path: selected ? pathOf(selected) : null }), [post, selected]);
  useEffect(highlight, [highlight]);

  useEffect(() => {
    const onMessage = (e) => {
      const from = target();
      if (e.origin !== origin || !from || e.source !== from || !e.data || typeof e.data !== "object") return;
      if (e.data.type === "clientnav:ready") {
        ready.current = true;
        if (e.data.mode === "preview") {
          if (y.current) post({ type: "clientnav:scroll-to", y: y.current });
          if (!e.data.blocks) setNote("Shows the draft a moment after you stop typing. (This front end's preview page has no click-to-edit marks.)");
        } else sendBlocks();
        highlight();
      } else if (e.data.type === "clientnav:select" && e.data.path) {
        const id = blockAt(e.data.path);
        if (id) dispatch("core/block-editor").selectBlock(id);
      } else if (e.data.type === "clientnav:scroll" && typeof e.data.y === "number") {
        y.current = e.data.y;
      }
    };
    window.addEventListener("message", onMessage);
    return () => window.removeEventListener("message", onMessage);
  }, [target, post, sendBlocks, highlight]);

  // Room for the preview beside the editor.
  useEffect(() => {
    if (where !== "beside") return undefined;
    document.body.classList.add("cnh-split");
    document.body.style.setProperty("--cnh-w", `${width}px`);
    // The pane starts where the editor does: below the admin bar when there is one, whatever the body's classes say.
    const top = () => {
      const editor = document.querySelector(".interface-interface-skeleton");
      document.body.style.setProperty("--cnh-top", `${editor ? Math.max(0, editor.getBoundingClientRect().top) : 32}px`);
    };
    top();
    window.addEventListener("resize", top);
    return () => {
      window.removeEventListener("resize", top);
      document.body.classList.remove("cnh-split");
      document.body.style.removeProperty("--cnh-w");
      document.body.style.removeProperty("--cnh-top");
    };
  }, [where, width]);

  // A window closed by hand ends the preview.
  useEffect(() => {
    if (where !== "window") return undefined;
    const t = setInterval(() => {
      if (!win.current || win.current.closed) setWhere(null);
    }, 1000);
    return () => clearInterval(t);
  }, [where]);

  const drag = (e) => {
    e.preventDefault();
    document.body.classList.add("cnh-dragging");
    let last = width;
    const move = (m) => {
      last = Math.min(window.innerWidth - 420, Math.max(320, window.innerWidth - m.clientX));
      setWidth(last);
    };
    const up = () => {
      document.body.classList.remove("cnh-dragging");
      window.removeEventListener("mousemove", move);
      window.removeEventListener("mouseup", up);
      store.set(last);
    };
    window.addEventListener("mousemove", move);
    window.addEventListener("mouseup", up);
  };

  const openWindow = () => {
    // Opened here, in the click, or the browser blocks it; the address follows.
    win.current = window.open("about:blank", "clientnav-live-preview", "width=1280,height=900");
    if (win.current) {
      setSrc("");
      setWhere("window");
    }
  };

  const pick1 = (on) => (on ? "primary" : "tertiary");
  const pane =
    where === "beside"
      ? createPortal(
          <div className="cnh-pane">
            <div className="cnh-pane__grip" onMouseDown={drag} title="Drag to make the preview wider or narrower" />
            <div className="cnh-pane__bar">
              <span className="cnh-pane__title">Live preview</span>
              {config.livePage !== false && (
                <Button size="small" variant={pick1(mode === "instant")} onClick={() => setMode("instant")} label="The front end draws what you type, before anything is saved">
                  Instant
                </Button>
              )}
              <Button size="small" variant={pick1(mode === "draft")} onClick={() => setMode("draft")} label="The front end's own preview of the draft, after each pause">
                Draft
              </Button>
              <span aria-hidden="true">·</span>
              {Object.keys(WIDTHS).map((d) => (
                <Button key={d} size="small" variant={pick1(device === d)} onClick={() => setDevice(d)}>
                  {d === "phone" ? "Phone" : d === "tablet" ? "Tablet" : "Wide"}
                </Button>
              ))}
              <Button size="small" variant="tertiary" onClick={() => setWhere(null)} label="Close the preview">
                ×
              </Button>
              {note && <span className="cnh-pane__note">{note}</span>}
            </div>
            <div className="cnh-pane__stage">
              {src && <iframe ref={frame} src={src} title="Live preview" style={{ width: WIDTHS[device] ? `${WIDTHS[device]}px` : "100%" }} />}
            </div>
          </div>,
          document.body
        )
      : null;

  return (
    <>
      <PluginDocumentSettingPanel name="clientnav-live-preview" title="Live preview">
        <p>See this page in the site’s real design while you edit. Click a part of the preview to select it here.</p>
        <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
          <Button variant="primary" onClick={() => setWhere(where === "beside" ? null : "beside")}>
            {where === "beside" ? "Hide the preview" : "Show beside the editor"}
          </Button>
          <Button variant="secondary" onClick={openWindow}>
            Open in a window
          </Button>
        </div>
      </PluginDocumentSettingPanel>
      {pane}
    </>
  );
}

if (origin && config.liveToken) registerPlugin("clientnav-live-preview", { render: Live });
