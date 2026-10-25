# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 2.x (the default branch, next release) | Yes |
| 1.0.x | No. Its known defects (unsafe paths, worker escapes, partial output) are fixed in 2.x; see [docs/MIGRATION.md](docs/MIGRATION.md) |

The Livewire and Filament integrations and the npm packages in this
repository follow the core's major version.

## Reporting a vulnerability

Please do not open a public issue. Report privately through either:

- GitHub: the **Report a vulnerability** button on the repository's
  [Security tab](https://github.com/offline-agency/php-image-puzzle/security), or
- email: <support@offlineagency.com>.

Include the affected version or commit, a minimal reproduction and the impact
you observed. We will acknowledge the report, keep you informed while we
investigate and credit you in the release notes unless you prefer otherwise.

## Scope notes

The library processes images, JSON definitions and saved games that may come
from untrusted users. Reports about these are in scope, for example:

- reading or writing files outside the directories the application chose;
- resource exhaustion that bypasses `ResourceLimits` or the documented JSON
  size, depth and count limits;
- a saved state or definition accepted by the PHP validation although it
  violates the rules or belongs to another puzzle;
- script injection through definitions, labels or file names in the players,
  the bundles or the SVG/PDF exports.

How applications should handle untrusted paths and files is described in
[docs/OPERATIONS.md](docs/OPERATIONS.md).
