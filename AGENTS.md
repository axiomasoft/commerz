<!-- generated from .ai/guidelines/agent-entrypoint.md by scripts/build-agent-docs.sh -->

# Commerz

- Edit this context only in `.ai/guidelines/agent-entrypoint.md`, then run
  `bash scripts/build-agent-docs.sh`.
- This is a Laravel package monorepo (commerce domain core). Keep the PHP core
  transport-agnostic and preserve the boundaries recorded in `docs/`.
- Architecture rules live in docs/architecture/ (packages, API, extension,
  data, invariants, decisions).
- Put PHP package code under `packages/<name>/`, PHP tests under the existing
  `tests/` suites or package-owned `packages/<name>/tests/`.
- Treat public contracts, wire formats, migrations, package dependencies, and
  exported APIs as SemVer surfaces.
- Keep dependencies pointing toward the core. Feature packages may use only its
  public contracts and declared extension points; packages do not write to tables
  owned by another package.
- For plan work, start at `plans/ACTIVE.md`; otherwise open only the documents
  needed for the task.
- Preserve unrelated worktree changes. Validate the affected scope with the
  relevant Composer checks (`lint:check`, `analyse`, `refactor:check`, `test`).
- For large tasks, prioritize implementation with lightweight affected checks.
  Defer independent review to a separate owner-requested session; do not add
  SKM review loops or repeat successful checks solely for workflow ceremony.
  Expand validation only for a concrete risk or failure. The owner's instruction
  takes precedence over skill and plan review defaults.

## Owner delegation

- You own architecture: pursue the best long-term design, even against earlier
  plans or proposals. Decide, record why (plan decision or `docs/architecture/`),
  continue.
- Ask the owner only if you cannot proceed alone: irreversible outward actions
  (push, publish, release), data loss, secrets, or conflict with stated goals.
- Fix defects and design flaws when found, including in earlier work.
- Success is the finished product, not a partial report.
