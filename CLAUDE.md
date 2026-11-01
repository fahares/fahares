# Working rules for parallel sessions

Several Claude sessions often work on this repository at once, each in its own worktree. These rules keep their step
numbers and deploys from colliding.

## Step numbers («گام»)

- Take a step number only from `scripts/dev/reserve-step "short title" <session-name>`. It prints the next free number
  (e.g. `13.127`) from a shared registry outside git (`~/.fahares/steps.tsv`) under a lock.
- Reserve before the first commit or doc that names the step. Never infer the next number from `docs/` or `git log`:
  another session's branch may already hold it.
- One reservation per step. If the work is dropped, leave the number unused; never reuse it.
- `scripts/dev/reserve-step --list` shows the recent reservations and their sessions.

## Push and deploy

A push to `main` deploys to production (Coolify) and runs the migrations, so pushes are batched.

- A working session does not push to `main`. It commits on its own branch, merges `origin/main` into it, runs the full
  suite in the `fahares_app` container, and reports the branch and its last commit as ready for release.
- The task-management session releases: it merges the ready branches into `main`, runs the full suite once on the
  merged result, and pushes once, when the user asks for a release.
- When a session needs its code on production soon (for example to run a data command), it says so in its
  ready report, so a release can be made then.
- A docs-only commit, such as the record of a production run, waits for the next release. A push of only `docs/`, root `*.md` or `scripts/dev/` builds and deploys nothing (`.github/workflows/image.yml`), so such a push is harmless but still waits for the release.
- Production data commands still need the user's own approval in the session that runs them, a backup first,
  and one command at a time across sessions.

## Merging the step logs

`docs/PROCESS_DOCUMENTATION.md` and `docs/TECHNICAL_APPENDIX.md` merge with `merge=union` (`.gitattributes`): both
sides' new steps are kept without a conflict. Union cannot tell an edit from an addition, so after a merge check that
an earlier line edited on both sides was not kept twice, and that steps stay in number order.
