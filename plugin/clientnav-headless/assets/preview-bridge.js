/*
 * Client Navigator Headless: the preview bridge, for a front end's preview page.
 *
 * A front end adds one line to the page that shows a draft (its /preview/<id>/ page):
 *   <script src="https://<WordPress address>/wp-content/plugins/clientnav-headless/assets/preview-bridge.js" defer></script>
 * It does nothing unless the page is shown beside the editor in WordPress (inside a frame). There it
 *   - outlines the block under the pointer, and a click selects that block in the editor (instead of following links);
 *   - outlines the block selected in the editor and brings it into view;
 *   - keeps the reading place when the editor reloads the draft after a pause in typing.
 * Blocks are found by data-cnh-block, which the content door adds to every block in a preview answer.
 * A front end that draws blocks itself puts data-cnh-block="<path>" on a block's outer element ("path" in the
 * block tree). Messages go only to the WordPress address this script was loaded from, and only its messages count.
 * A front end's live page (unsaved blocks drawn as they are typed) loads it with data-mode="live".
 */
(function () {
  if (window.parent === window || window.__cnhBridge) return;
  window.__cnhBridge = true;
  var me = document.currentScript && document.currentScript.src;
  var live = !!(document.currentScript && document.currentScript.getAttribute("data-mode") === "live");
  var wp;
  try {
    wp = new URL(me).origin;
  } catch (e) {
    return;
  }
  var send = function (msg) {
    window.parent.postMessage(msg, wp);
  };
  var css = document.createElement("style");
  css.textContent =
    "[data-cnh-block]{cursor:pointer}" +
    ".cnh-hover{outline:2px dashed #3858e9!important;outline-offset:2px}" +
    ".cnh-picked{outline:2px solid #3858e9!important;outline-offset:2px}";
  (document.head || document.documentElement).appendChild(css);

  var hover = null;
  var picked = null;
  var mark = function (el, cls, was) {
    if (was && was !== el) was.classList.remove(cls);
    if (el) el.classList.add(cls);
    return el;
  };
  var blockOf = function (node) {
    return node && node.closest ? node.closest("[data-cnh-block]") : null;
  };

  document.addEventListener("mouseover", function (e) {
    hover = mark(blockOf(e.target), "cnh-hover", hover);
  });
  document.addEventListener("mouseleave", function () {
    hover = mark(null, "cnh-hover", hover);
  });
  document.addEventListener(
    "click",
    function (e) {
      var block = blockOf(e.target);
      var link = e.target.closest && e.target.closest("a[href]");
      // The preview stays on the draft: links are not followed here.
      if (block || link) {
        e.preventDefault();
        e.stopPropagation();
      }
      if (block) send({ type: "clientnav:select", path: block.getAttribute("data-cnh-block") });
    },
    true
  );

  var timer = 0;
  window.addEventListener(
    "scroll",
    function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        send({ type: "clientnav:scroll", y: Math.round(window.scrollY) });
      }, 150);
    },
    { passive: true }
  );

  window.addEventListener("message", function (e) {
    if (e.origin !== wp || !e.data || typeof e.data !== "object") return;
    if (e.data.type === "clientnav:highlight") {
      var el = e.data.path ? document.querySelector('[data-cnh-block="' + String(e.data.path).replace(/[^0-9.]/g, "") + '"]') : null;
      picked = mark(el, "cnh-picked", picked);
      if (el) {
        var r = el.getBoundingClientRect();
        if (r.bottom < 0 || r.top > window.innerHeight) el.scrollIntoView({ block: "center", behavior: "smooth" });
      }
    } else if (e.data.type === "clientnav:scroll-to" && typeof e.data.y === "number") {
      window.scrollTo(0, e.data.y);
    }
  });

  // A live page (<script ... data-mode="live">) says it is ready itself, once it listens for the blocks: this script
  // may run before the page's own code does, and blocks sent then would be lost.
  if (!live) send({ type: "clientnav:ready", mode: "preview", blocks: document.querySelectorAll("[data-cnh-block]").length });
})();
