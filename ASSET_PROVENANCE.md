# Asset provenance

## Application icon

- Source: `app/public/icons/icon-source.svg`
- Created: 2026-08-08
- Purpose: original application and PWA icon for this public-release candidate
- Method: hand-authored SVG geometry using only paths, circles, rectangles, gradients, and solid colors
- Palette: deep blue (`#1E40AF`, `#1E3A8A`, `#0F172A`) with a warm-gold (`#F2B84B`) accent
- Third-party inputs: none; no stock asset, external font, trademark, copied icon, or traced artwork was used
- Renderer: Sharp from the bundled local Codex workspace runtime; no network resource was used
- Raster outputs and SHA-256:
  - `apple-touch-icon.png` (180x180): `66d001c38f8052b028f3aa57fd33d4e60ae72f5b4cf5357b85a83b9db44e256d`
  - `icon-192.png` (192x192): `7e5f7e26576bf3d2a999242b8778631dbfa6ad97ce7736df6160704919d3d495`
  - `icon-512.png` (512x512): `a7c2d17627ab2aa1b005f321a34f1462cf355e351063ae1e2886fa61f3159c00`

Each PNG exists in both `app/public/` and `app/public/icons/`; matching names are byte-identical. The SVG source SHA-256 is `60b0a2cb48dc2eae88f0f6a6f5eb552996b54c3357fc7dcc363e8884d9c179ec`.

The raster files are generated from the SVG source and are distributed with this project under the MIT License.
