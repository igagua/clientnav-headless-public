#!/usr/bin/env node
/**
 * Pairs this front end with its CMS: the one-time code from WordPress (Settings > Headless > Make a pairing code,
 * or Client Navigator's ability clientnav/headless-pair) is exchanged for the shared secret, which is written into
 * the front end's .env without being shown.
 *
 *   npx clientnav-headless-pair --cms https://cms.example.com CODE [--env .env] [--name REVALIDATE_SECRET]
 *
 * The .env before is kept as .env.before-pair. Restart the front end afterwards so it reads the new secret.
 */
import { copyFileSync, existsSync, readFileSync, writeFileSync } from "node:fs";

export async function pair({ cms, code, envFile = ".env", name = "REVALIDATE_SECRET", fetch: doFetch = globalThis.fetch }) {
  if (!cms || !code) throw new Error("usage: clientnav-headless-pair --cms https://cms.example.com CODE [--env .env] [--name REVALIDATE_SECRET]");
  const base = String(cms).replace(/\/+$/, "").replace(/\/wp-json$/, "");
  let answer;
  try {
    const res = await doFetch(`${base}/wp-json/clientnav-headless/v1/pair`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ code: String(code).trim() }), signal: AbortSignal.timeout(30000) });
    answer = res.ok ? await res.json() : null;
  } catch {
    answer = null;
  }
  const secret = answer?.secret ?? "";
  if (!answer?.ok || secret.length < 32) throw new Error("WordPress did not accept the code (wrong, used, or older than 15 minutes). Nothing was changed.");
  const before = existsSync(envFile) ? readFileSync(envFile, "utf8") : "";
  if (before) copyFileSync(envFile, `${envFile}.before-pair`);
  const lines = before.split("\n").filter((l, i, all) => !(l === "" && i === all.length - 1));
  let set = false;
  const out = lines.map((l) => (l.startsWith(`${name}=`) ? ((set = true), `${name}=${secret}`) : l));
  if (!set) out.push(`${name}=${secret}`);
  writeFileSync(envFile, out.join("\n") + "\n", { mode: 0o600 });
  return { envFile, name, kept: before ? `${envFile}.before-pair` : null };
}

const run = process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href;
if (run || process.argv[1]?.endsWith("clientnav-headless-pair")) {
  const args = process.argv.slice(2);
  const opt = (k) => {
    const i = args.indexOf(k);
    return i >= 0 ? args.splice(i, 2)[1] : undefined;
  };
  const cms = opt("--cms") ?? process.env.CMS_URL;
  const envFile = opt("--env") ?? ".env";
  const name = opt("--name") ?? "REVALIDATE_SECRET";
  pair({ cms, code: args[0], envFile, name })
    .then((r) => console.log(`Paired: ${r.name} in ${r.envFile} now holds WordPress's secret (not shown).${r.kept ? ` The file before is ${r.kept}.` : ""} Restart the front end.`))
    .catch((e) => {
      console.error(e.message);
      process.exit(1);
    });
}
