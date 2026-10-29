"use client";

import { useEffect } from "react";

/**
 * Elementor's link actions, which its own front-end script carries out on the CMS: "#elementor-action:action=…".
 *   popup:open   opens the popup drawn on the page as <dialog data-popup="id"> (components/designed.tsx)
 *   popup:close  closes the open one
 *   lightbox     a YouTube or Vimeo video, or a picture, in a dialog of its own
 * Anything else such a link asks for is left alone. Escape, the close button or a click beside the box closes it.
 */
type Settings = { id?: string | number; type?: string; videoType?: string; url?: string; html?: string };

function parse(href: string): { action: string; settings: Settings } | null {
  const h = decodeURIComponent(href);
  const m = h.match(/#elementor-action:action=([a-z:]+)(?:&(?:amp;)?settings=([A-Za-z0-9+/=]+))?/);
  if (!m) return null;
  let settings: Settings = {};
  try {
    settings = m[2] ? JSON.parse(atob(m[2])) : {};
  } catch {}
  return { action: m[1], settings };
}

function video(url: string): string | null {
  const yt = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,20})/);
  if (yt) return `https://www.youtube-nocookie.com/embed/${yt[1]}?autoplay=1&rel=0`;
  const vm = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
  if (vm) return `https://player.vimeo.com/video/${vm[1]}?autoplay=1`;
  return null;
}

function lightbox(content: HTMLElement) {
  const d = document.createElement("dialog");
  d.className = "cnh-popup cnh-lightbox";
  const close = document.createElement("button");
  close.type = "button";
  close.className = "cnh-popup-close";
  close.setAttribute("aria-label", "Close");
  close.setAttribute("data-popup-close", "");
  close.textContent = "×";
  d.append(close, content);
  d.addEventListener("close", () => d.remove());
  document.body.appendChild(d);
  d.showModal();
}

export function Actions() {
  useEffect(() => {
    const click = (e: MouseEvent) => {
      const t = e.target as Element | null;
      // Close: the close button, or a click on the dialog's own backdrop.
      if (t?.closest?.("[data-popup-close]")) {
        t.closest("dialog")?.close();
        return;
      }
      if (t instanceof HTMLDialogElement && t.classList.contains("cnh-popup")) {
        t.close();
        return;
      }
      const a = t?.closest?.("a[href*='elementor-action']") as HTMLAnchorElement | null;
      if (!a) return;
      const act = parse(a.getAttribute("href") ?? "");
      if (!act) return;
      if (act.action === "popup:open") {
        const d = document.querySelector(`dialog[data-popup="${Number(act.settings.id)}"]`) as HTMLDialogElement | null;
        if (!d) return;
        e.preventDefault();
        if (!d.open) d.showModal();
        (d.querySelector("input, button:not([data-popup-close]), a") as HTMLElement | null)?.focus();
      } else if (act.action === "popup:close") {
        e.preventDefault();
        (a.closest("dialog") as HTMLDialogElement | null)?.close();
      } else if (act.action === "lightbox") {
        const url = String(act.settings.url ?? "");
        const src = act.settings.type === "video" ? video(url) : null;
        if (src) {
          e.preventDefault();
          const f = document.createElement("iframe");
          f.src = src;
          f.title = "Video";
          f.allow = "autoplay; encrypted-media; picture-in-picture; fullscreen";
          f.allowFullscreen = true;
          f.className = "cnh-lightbox-video";
          lightbox(f);
        } else if (act.settings.type === "image" && /^(https?:)?\//.test(url)) {
          e.preventDefault();
          const img = document.createElement("img");
          img.src = url;
          img.alt = "";
          img.className = "cnh-lightbox-image";
          lightbox(img);
        }
      }
    };
    // Escape closes the open popup, also when a site script inside it takes the key for itself (a search clearing its field).
    const key = (e: KeyboardEvent) => {
      if (e.key !== "Escape") return;
      const open = [...document.querySelectorAll("dialog.cnh-popup[open]")].pop() as HTMLDialogElement | undefined;
      if (open) {
        e.preventDefault();
        open.close();
      }
    };
    document.addEventListener("click", click);
    window.addEventListener("keydown", key, true);
    return () => {
      document.removeEventListener("click", click);
      window.removeEventListener("keydown", key, true);
    };
  }, []);
  return null;
}
