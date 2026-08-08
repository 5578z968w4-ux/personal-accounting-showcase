# Public release scope

## Candidate boundary

This directory is a clean-slate candidate assembled from an exact allowlist of application, Docker, test, fixture, and empty-directory files. Its local Git history begins with the reviewed public candidate and contains none of the private source repository history. Public-facing documents in this directory were written specifically for the candidate.

The private source repository, its `.git` directory, internal changelog, governance instructions, NAS paths, deployment reports, runtime logs, real imports, environment files, and database backups are outside the candidate.

## Known security boundaries

- The Compose web port defaults to localhost only; MariaDB is not published to the host.
- Interactive demo mode is fail-closed to `personal_accounting_demo` and ships with synthetic reset data.
- Quick Entry and the Shortcut API are private-network features without a complete public-internet authentication and abuse-control design.
- `db-test.php` creates a diagnostic record.
- Repair, migration, verification, and import scripts may read or write database state. Operators must inspect their gates and use a disposable test database.
- Gemini must remain disabled unless the operator supplies a private key through `.env` and accepts the data-processing implications.
- The application icon set was created specifically for this candidate from original vector geometry; its source and generation basis are recorded in `ASSET_PROVENANCE.md`.
- The candidate is licensed under the MIT License; the canonical text is included in `LICENSE`.
- Current-tree and all-local-blob Git-history checks, including unreachable objects, are bundled as local and CI release gates.
- The bundled desktop and mobile screenshots were re-reviewed against the current isolated Demo after the visual changes; they match the current UI and contain synthetic data only.

## Publication blockers

- Review and enable the bundled read-only CI workflow after repository creation; it has not run on GitHub.
- Enable GitHub secret scanning, push protection, branch rules, and private vulnerability reporting before announcing the project.
- Obtain explicit approval before creating a GitHub repository, pushing, or changing visibility.

## Excluded source categories

- Private Git history and remotes
- `.env` and all real credentials
- Database dumps, backups, imports, and logs
- Internal agent instructions and local development governance
- NAS synchronization, production migration, acceptance, and incident records
- Existing private-repository changelog
