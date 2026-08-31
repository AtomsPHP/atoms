# AGENTS.md

Guidance for coding agents working in `site/` — the public marketing site.

## What this is

An Astro static site: the homepage for Atoms at the workspace's public domain.
It is not a package, is not versioned by `release/manifest.json`, and takes no
part in the coordinated release flow. It ships when its content ships.

## Layout

- `src/content/home/*.md` — **all page copy**, one markdown file per section
  (`hero`, `code`, `live`, `auth`, `how`, `why`, `faq`, `start`).
  Prose is the markdown body; repeated structured copy (annotation rail,
  "why" cards, pipeline stations, CTAs) is frontmatter, whose strings support
  the inline-markdown subset in `src/lib/inline-md.ts` (`` `code` ``,
  `**bold**`, `*em*`, `[link](href)`) — no lists, no block elements. The
  schema lives in `src/content.config.ts`. Copy that wants full markdown
  belongs in a body: `faq.md` is written that way, one `###` per question
  with the answer beneath it.
- `src/pages/index.astro` — page structure only: the designed section shells.
- `src/samples.ts` — the code samples, as hand-highlighted HTML (brand token
  classes, anchor ids for the annotation rail). Single source for both
  renditions: index.astro renders the HTML, and the markdown endpoint derives
  plain code from the same strings.
- `src/pages/index.md.ts` — the homepage as markdown for agents, built to
  `/index.md` from the same content collection and samples.
- `worker/index.js` — the deployed edge, a Cloudflare Worker in front of the
  static build: it redirects any `www.` hostname to the bare apex, and answers
  a request for `/` carrying `Accept: text/markdown` with `/index.md`
  (`Vary: Accept`). `wrangler.jsonc` binds `dist/` as its assets and sets
  `run_worker_first`, without which neither behaviour would see a request that
  matches a built file. Deploying anywhere else needs equivalents at that edge.
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
- Code samples live only in `src/samples.ts` — edit them there, never inline
  in a template, so the HTML and markdown renditions cannot drift. A `<pre>`
  authored inline in a template needs `is:raw`, or Astro parses the braces as
  template expressions.
- The page scripts are `is:inline` on purpose: they are plain browser JS,
  exempt from `astro check`'s TS pass.
- Respect `prefers-reduced-motion` in any animation work.

## Commands

```sh
npm ci          # install
npm run dev     # local Astro dev server
npm run build   # astro check, then static build into dist/
npx wrangler dev  # the built site behind worker/index.js, on 127.0.0.1:8787
npm run deploy    # build, then deploy the Worker and its assets
```

The build needs no Cloudflare account, no credentials, and no cross-repo
fetches; only `npm run deploy` does. The Worker is `atoms-site`, serving
`atomsphp.dev` and `www.atomsphp.dev` as custom domains declared in
`wrangler.jsonc` — it is deployed straight from a working copy and takes no
part in the coordinated release flow.
