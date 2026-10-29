// Builds the editor side of the plugin from plugin/src and packages/ui:  npm run build:plugin
import { build } from "esbuild";
import { cpSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const ui = join(here, "../packages/ui");
const out = join(here, "clientnav-headless");

// Packages WordPress already has on the page are taken from it instead of being packed in again.
const globals = { react: "React", "react-dom": "ReactDOM", "react/jsx-runtime": "ReactJSXRuntime" };
const camel = (s) => s.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
const fromWordPress = {
  name: "from-wordpress",
  setup(b) {
    b.onResolve({ filter: /^(react|react-dom|react\/jsx-runtime|@wordpress\/[a-z-]+)$/ }, (a) => ({ path: a.path, namespace: "wp" }));
    b.onLoad({ filter: /.*/, namespace: "wp" }, (a) => ({ contents: `module.exports = window.${globals[a.path] ?? `wp.${camel(a.path.replace("@wordpress/", ""))}`};`, loader: "js" }));
  },
};

rmSync(join(out, "build"), { recursive: true, force: true });
rmSync(join(out, "blocks"), { recursive: true, force: true });
mkdirSync(join(out, "build"), { recursive: true });

await build({ entryPoints: [join(here, "src/editor.jsx")], outfile: join(out, "build/editor.js"), bundle: true, format: "iife", minify: true, jsx: "automatic", target: "es2020", plugins: [fromWordPress], logLevel: "info" });

// The block definitions and the stylesheet are the library's; the plugin carries copies.
for (const name of readdirSync(join(ui, "blocks"))) cpSync(join(ui, "blocks", name), join(out, "blocks", name), { recursive: true });
writeFileSync(join(out, "build/ui.css"), readFileSync(join(ui, "src/styles.css"), "utf8") + readFileSync(join(here, "src/editor.css"), "utf8"));
console.log("blocks:", readdirSync(join(out, "blocks")).join(", "));
