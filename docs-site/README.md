# Atoms documentation site

The public documentation for <https://docs.atomsphp.dev>, built with Astro
Starlight. Source pages live in `src/content/docs/`.

```sh
npm ci
npm run dev
npm run build
```

`npm run build` checks Astro types, verifies that the generated error reference
matches `../packages/core/resources/errors.json`, builds the static site, and
validates internal links plus every stable error-code anchor.

Do not edit `src/content/docs/reference/errors.md` by hand. After changing the
core error catalog, regenerate it with:

```sh
npm run generate:errors
```
