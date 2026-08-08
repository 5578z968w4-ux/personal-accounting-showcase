# Security policy

## Supported versions

Security fixes are planned for the latest release only.

## Reporting a vulnerability

Do not open a public issue containing credentials, personal financial data, internal URLs, exploit details, or database exports. After publication, use GitHub private vulnerability reporting if it is enabled. Until a private reporting channel is configured, do not publish the repository.

Include the affected version, reproduction steps using synthetic data, impact, and any proposed mitigation. Never attach a real `.env`, SQL dump, log, screenshot, or Shortcut containing a token.

## Deployment warning

The unauthenticated Quick Entry and Shortcut API surfaces assume a trusted private network. A public internet deployment requires a separate security design and review.

The bundled interactive demo is local-only. Demo mode requires a dedicated database identity, disables external AI and private-network write surfaces, and uses intentionally public credentials. These controls do not make it an internet-ready multi-tenant service.
