# AGENTS.md

Guidance for coding agents working in `site/` — the public marketing site.

## What this is

An Astro static site: the homepage for Atoms at the workspace's public domain.
It is not a package, is not versioned by `release/manifest.json`, and takes no
part in the coordinated release flow. It ships when its content ships.

## Layout

- `src/pages/index.astro` — **all page content**: hero copy, every section's
  prose, and the code samples. Content edits happen here.
- `src/styles/global.css` — the design system: paper/ink tokens as CSS custom
  properties on `:root`, type scale, and every component style. Modern vanilla
  CSS only — no Tailwind, no preprocessor.
- `src/layouts/Base.astro` — head (fonts, favicons, meta), grain overlay, nav
  and footer chrome.
- `src/components/AtomDiagram.astro` — the animated hero diagram and its
  script.
- `public/` — favicon/icon files copied from `../brand/logo/`. That directory
  is the source of truth for brand assets; re-copy rather than editing here.

## Rules

- **Code samples must be real.** Every PHP snippet on the page uses actual
  `atoms/*` API surfaces — verify against `packages/` before changing one.
  The page must never show an API that does not exist.
- **Messaging describes the open-source project**: deploy to your own
  Cloudflare account, no hosted service. Never invent pricing, usage tooling,
  or cost figures.
- Keep `is:raw` on every `<pre>` — Astro otherwise parses the braces in code
  samples as template expressions.
- The page scripts are `is:inline` on purpose: they are plain browser JS,
  exempt from `astro check`'s TS pass.
- Respect `prefers-reduced-motion` in any animation work.

## Commands

```sh
npm ci          # install
npm run dev     # local dev server
npm run build   # astro check, then static build into dist/
```

The build needs no Cloudflare account, no credentials, and no cross-repo
fetches.
