# Atoms logo — official mark

A molecule/arrangement mark: monolith → atom → clients, in purple and
orange — evoking the two platforms Atoms bridges, PHP and Cloudflare.

## Files

- `atoms-icon.svg` — master vector, transparent background. Use this as the
  source for any new size or format.
- `favicon-16.png`, `favicon-32.png`, `favicon-48.png` — transparent
  background. Each uses stroke/node weights tuned for legibility at that
  exact pixel size (thicker strokes at smaller sizes), not a naive scale of
  the master. The 16px size drops "client 2" (its bond is too short to read
  separately at that size).
- `icon-192.png`, `icon-512.png` — transparent background, for Android/PWA
  icons or general app-icon use. Full geometry (both clients).
- `apple-touch-icon-180.png` — opaque white background, per iOS convention
  (Apple touch icons don't handle transparency well).
- `atoms-wordmark.svg` / `.png` — the "atoms" wordmark on its own, set in
  Science Gothic at weight 350. Outlined to real vector paths, not live
  text — this file has no font dependency, so it renders correctly anywhere
  without Science Gothic installed or loaded.
- `atoms-lockup.svg` / `.png` — icon and wordmark combined, sized and
  aligned to match. Also outlined; no font dependency.

## Wordmark typeface

Science Gothic, weight 350 — a libre variable font (Thomas Phinney, Vassil
Kateliev, Brandon Buerkle; SIL Open Font License 1.1, no Reserved Font
Name), based on Morris Fuller Benton's Bank Gothic. Weight 350 sits between
the font's 300 and 400 static instances; getting a true interpolated value
there (rather than an approximation) requires the actual variable font file
— Google Fonts' hosted delivery only serves fixed static weights. If you
need to regenerate these assets or set live text at this weight, self-host
the variable font from
[googlefonts/science-gothic](https://github.com/googlefonts/science-gothic)
rather than loading it through `fonts.googleapis.com`.

## Palette

| Role | Color |
|---|---|
| Monolith (purple) | `#7C3AED` |
| Atom (orange) | `#EA580C` |
| Client nodes (charcoal) | `#44444B` |
| Bonds (gray) | `#9A9DA5` |
| Wordmark / ink | `#15161B` |
