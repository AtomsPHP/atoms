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
matches `../packages/core/resources/errors.json`, checks generated compatibility
content with PHP, checks JSON examples, builds the static site, and
validates internal links plus every stable error-code anchor.

Run `composer test -- --filter MethodsGuideTest` from the repository root to
check the Methods guide's scaffold command.

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

`scripts/release/release.php` generates the compatibility page, Laravel notices,
and CI matrix from package constraints and `release/manifest.json`. After editing
these inputs or the generator's prose, run `php scripts/release/release.php generate`
from the repository root and commit the results.

Before publishing compatibility changes, check the successful CI run for the
release commit. Each Laravel/PHP row must have a passing matrix job. Check that
the Worker job executed the representative bundle in its PHP interpreter too.
Composer constraints alone do not establish support.

For 0.9.1, [release-commit CI](https://github.com/AtomsPHP/atoms/actions/runs/41172031612)
passed Laravel 12 on PHP 8.3, 8.4 and 8.5, and Laravel 13 on PHP 8.3, 8.4, 8.5
and 8.6. Its Worker job also passed. The
[release run](https://github.com/AtomsPHP/atoms/actions/runs/41172307195)
required that successful CI result before publication.

The docs deployment is separate from package publication. After deploying the
docs, compare the live installation requirements and compatibility tables with
the release-qualified source. A package release does not update those pages.

When updating a release's examples, keep the PHP package pins, CLI version,
runtime scaffold and upgrade commands, and Action tag synchronized. Review
`getting-started/install.md`, `getting-started/laravel.md`, and `guides/deploy.md`
under `src/content/docs/`, then rerun the new-project instructions in a clean
directory.

## JSON examples

Use `json` fences for valid JSON without comments or trailing commas. Add
`title="filename.json"` for complete files. Use `jsonc` for JSONC files or fragments.
`npm run check:examples` validates these blocks as part of `npm run check`.
