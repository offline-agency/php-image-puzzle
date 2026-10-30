# Baseline reproduction (Phase 0)

Before any fix, every gap G01–G18 was reproduced against the original code
at commit `b114a4a` (tag `1.0.2`) on PHP 8.5 with GD, exif and pcntl.

- Raw output: [`baseline-2026-10-25.txt`](baseline-2026-10-25.txt)
  (machine-specific paths and repeated PHP 8.5 `imagedestroy()` deprecation
  notices removed; nothing else edited).
- Scripts: `tools/repro/baseline-repro.php` and `tools/repro/worker-repro.php`
  as committed in `c07e25f`. They target the 1.x API and were removed from the
  tree afterwards because they no longer apply to the 2.x code; run them from
  that commit's tree against a `1.0.2` checkout to reproduce the output:

  ```bash
  git worktree add ../image-puzzle-1.0.2 1.0.2 && cd ../image-puzzle-1.0.2
  composer install
  mkdir -p tools/repro
  git show c07e25f:tools/repro/baseline-repro.php > tools/repro/baseline-repro.php
  git show c07e25f:tools/repro/worker-repro.php > tools/repro/worker-repro.php
  php tools/repro/baseline-repro.php
  ```

Each reproduced gap is now covered by a regression test that failed on the
original behaviour; see [`gap-matrix.md`](gap-matrix.md).
