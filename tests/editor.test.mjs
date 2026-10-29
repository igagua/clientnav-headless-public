// The built editor script, loaded with a stand-in for WordPress: which blocks and panels it registers.
// Run after `npm run build:plugin`:  npm test
import { readFileSync, readdirSync } from "node:fs";
import { test } from "node:test";
import assert from "node:assert/strict";
import vm from "node:vm";

const root = new URL("../plugin/clientnav-headless/", import.meta.url);
const code = readFileSync(new URL("build/editor.js", root), "utf8");
const blocks = readdirSync(new URL("blocks/", root)).length;

function load(config) {
  const registered = [];
  const panels = [];
  // Anything else the script touches while loading answers with itself: called, read or built, it never throws.
  const any = new Proxy(function () {}, { get: (t, k) => (k === Symbol.toPrimitive ? () => "" : any), apply: () => any, construct: () => any });
  const part = (own) => new Proxy(own, { get: (t, k) => (k in t ? t[k] : any) });
  const wp = part({ blocks: part({ registerBlockType: (name) => registered.push(name) }), plugins: part({ registerPlugin: (name) => panels.push(name) }) });
  const window = { wp, React: any, ReactDOM: any, ReactJSXRuntime: any, URL, console };
  window.window = window;
  if (config) window.clientnavHeadless = config;
  vm.runInNewContext(code, window);
  return { registered, panels };
}

test("no settings handed over (no front end named): every block, no live preview", () => {
  const { registered, panels } = load(null);
  assert.equal(registered.length, blocks);
  assert.ok(registered.every((n) => n.startsWith("clientnav/")));
  assert.deepEqual(panels, []);
});

test("blocks on, front end named: every block and the live preview panel", () => {
  const { registered, panels } = load({ frontend: "https://www.example.test", liveToken: "t", blocks: true });
  assert.equal(registered.length, blocks);
  assert.deepEqual(panels, ["clientnav-live-preview"]);
});

test("blocks off: no block at all, the live preview panel stays", () => {
  const { registered, panels } = load({ frontend: "https://www.example.test", liveToken: "t", blocks: false });
  assert.deepEqual(registered, []);
  assert.deepEqual(panels, ["clientnav-live-preview"]);
});

test("settings from before this switch (no word on blocks): every block", () => {
  const { registered } = load({ frontend: "https://www.example.test", liveToken: "t" });
  assert.equal(registered.length, blocks);
});
