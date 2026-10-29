# Atoms documentation site

The public documentation for <https://docs.atomsphp.dev>, built with Astro
Starlight. Source pages live in `src/content/docs/`.

```sh
npm ci
npm run dev
npm run build
npx wrangler dev   # the built site as Cloudflare would serve it, on 127.0.0.1:8787
npm run deploy     # build, then deploy the Worker and its assets
```

`npm run build` checks Astro types, verifies that the generated error reference
matches `../packages/core/resources/errors.json`, checks release-owned compatibility
content (using PHP), checks JSON examples, builds the static site, and
validates internal links plus every stable error-code anchor.

Do not edit `src/content/docs/reference/errors.md` by hand. After changing the
core error catalog, regenerate it with:

```sh
npm run generate:errors
```

## Deploying

Like the marketing site in `../site/`, the docs are a Cloudflare Worker made of
static assets: `wrangler.jsonc` binds `dist/` and declares `docs.atomsphp.dev`
as a custom domain. The Worker is `atoms-docs`. It is deployed straight from a
working copy with `npm run deploy` and takes no part in the coordinated release
flow; the build itself needs no Cloudflare account, only `wrangler deploy` does.
The token it runs under needs zone-level `Workers Routes: Edit` and `DNS: Edit`
on `atomsphp.dev` to attach the custom domain on the first deploy.

## Compatibility content

The release generator owns `src/content/docs/reference/compatibility.md` and
marked Laravel notices in the install and Laravel quickstart pages. It reads
Illuminate constraints from the Laravel adapter and Atom-side database bridge,
and Laravel CI versions and Testbench constraints from `release/manifest.json`.
The same metadata generates the marked tests matrix in `.github/workflows/ci.yml`.
After changing those inputs, run `php scripts/release/release.php generate` from
the repository root and commit the results. `php scripts/release/release.php check`
checks for drift; `php scripts/release/test-compatibility.php` tests the generator.

## JSON examples

Use `json` fences for complete JSON examples and add `title="filename.json"`
for complete file examples. JSON fences must parse as strict JSON; comments and
trailing commas are not allowed. Use `jsonc` for JSONC examples or fragments,
and title complete JSONC files with their `.jsonc` filename. `npm run
check:examples` runs the checker regression tests and checks Markdown and MDX
pages recursively. It runs as part of `npm run check`.
