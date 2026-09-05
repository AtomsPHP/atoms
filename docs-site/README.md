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
matches `../packages/core/resources/errors.json`, builds the static site, and
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
