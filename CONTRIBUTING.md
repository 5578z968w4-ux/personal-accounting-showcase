# Contributing

Contributions should be small, reviewable, and accompanied by focused tests.

1. Use PHP 8.2 and prepared statements for database access.
2. Escape HTML output and keep API errors free of credentials, tokens, connection strings, and stack traces.
3. Keep payment methods, salary parameters, and settlement rules configurable rather than hardcoded.
4. Use only synthetic fixtures. Never commit `.env`, database exports, runtime logs, real screenshots, or personal financial data.
5. Run PHP lint and all focused tests before opening a pull request.

Security reports must follow `SECURITY.md` and must not be filed as public issues.

By submitting a contribution, you agree that it may be distributed under the project's MIT License.
