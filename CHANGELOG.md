# Changelog

## Unreleased

- Added a reproducible full-history scanner for all local Git blobs, including unreachable objects, and configured CI to check out and inspect complete public history.
- Changed the public application icon and PWA theme to a professional deep-blue palette with a warm-gold accent.
- Adopted the MIT License for the public-release candidate.
- Replaced the inherited application icons with an original SVG-led icon set and documented its asset provenance.
- Added a reproducible public-release boundary checker and a zero-secret, read-only GitHub Actions workflow pinned to an immutable checkout revision.
- Added visually reviewed desktop and mobile screenshots captured from the isolated synthetic Demo.
- Replaced real-world payment, account, and household-role labels with synthetic Demo references; Demo reset now rebuilds those reference tables.
- Added a fail-closed interactive local demo using `personal_accounting_demo`, synthetic reset data, a visible demo banner, and dedicated public demo credentials.
- Disabled external AI, unauthenticated Quick Entry, Shortcut API, and database diagnostics while demo mode is enabled.
- Moved candidate runtime logs to project-scoped Docker named volumes so running the Demo does not add files to the public source tree.
- Added Demo mode regression coverage and responsive, accessible guidance for the login and dashboard journey.
- Increased section action links to a 44px touch target for mobile Demo navigation.
- Prepared a history-free public-release candidate from an audited tracked-file allowlist.
- Replaced environment-specific documentation with generic local-development instructions.
- Changed the default web binding to `127.0.0.1:8080`; MariaDB remains internal to the Compose network.
- Excluded credentials, backups, imports, logs, internal operational documentation, and private Git history.
