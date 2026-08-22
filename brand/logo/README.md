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
  Science Gothic at weight 350. Outlined to vector paths, so it renders
  correctly with no font installed.
- `atoms-lockup.svg` / `.png` — icon and wordmark combined, sized and
  aligned to match. Also outlined.

## Wordmark typeface

Science Gothic, weight 350 — a libre variable font (SIL Open Font License
1.1), from [googlefonts/science-gothic](https://github.com/googlefonts/science-gothic).

## Palette

| Role | Color |
|---|---|
| Monolith (purple) | `#7C3AED` |
| Atom (orange) | `#EA580C` |
| Client nodes (charcoal) | `#44444B` |
| Bonds (gray) | `#9A9DA5` |
| Wordmark / ink | `#15161B` |
