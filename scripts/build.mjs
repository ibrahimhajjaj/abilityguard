/**
 * Bundle assets/admin.jsx → assets/admin.js with esbuild.
 *
 * React + ReactDOM are bundled in. The output is a single self-contained
 * file enqueued via wp_enqueue_script. No in-browser Babel, no CDN.
 *
 * Usage:
 *   node scripts/build.mjs           # one-shot production build
 *   node scripts/build.mjs --watch   # rebuild on changes
 */

import esbuild from "esbuild";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, "..");

const config = {
  entryPoints: [resolve(root, "assets/admin.jsx")],
  outfile: resolve(root, "assets/admin.js"),
  bundle: true,
  format: "iife",
  target: ["chrome120", "firefox120", "safari16"],
  jsx: "transform",
  jsxFactory: "React.createElement",
  jsxFragment: "React.Fragment",
  minify: true,
  sourcemap: false,
  logLevel: "info",
  loader: { ".jsx": "jsx" },
  // Make React global inside the bundle so the inline `React.*` references
  // in admin.jsx resolve. We import React + ReactDOM at the top of the
  // entry banner so esbuild includes them.
  banner: {
    js: "",
  },
};

const watch = process.argv.includes("--watch");

if (watch) {
  const ctx = await esbuild.context(config);
  await ctx.watch();
  console.log("[abilityguard] watching assets/admin.jsx …");
} else {
  await esbuild.build(config);
  console.log("[abilityguard] built assets/admin.js");
}
