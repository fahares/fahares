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
- Steps are grouped in phases («فاز»). A new phase opens when the main subject of the work changes or a phase passes
  about 50 steps: the task-management session proposes it, and with the user's OK it adds the phase heading and
  chronology row and writes the new phase number to `~/.fahares/phase`, which `reserve-step` reads. Phase 14 opened on
  12 Aban 1405; phase 13 ends at 13.173 (13.174 and 13.175 were renumbered 14.2 and 14.3 and stay unused).

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
- Run every command that writes production data through `scripts/dev/prod-run <session-name> -- '<command>'`, never
  plain `ssh fahares-app`. It holds a lock shared by all sessions on this machine and refuses (exit 75) while another run
  holds it or an artisan run is still going in production. `--wait` queues instead of refusing; `--status` shows the
  holder, the production artisan runs and the last runs (`~/.fahares/prod-runs.log`). Read-only checks may use plain ssh.
- A backup taken before a production run must leave the container at once: copy it to
  `../fahares-sources/backups/step13/` on this machine before reporting the run. `storage/app` and `/tmp` in the app
  container are wiped by the next deploy, which may start the moment your run frees the lock.
- Before a release push, the task-management session checks `scripts/dev/prod-run --status`: a deploy replaces the app
  container and would cut off a running command.

## Merging the step logs

`docs/PROCESS_DOCUMENTATION.md` and `docs/TECHNICAL_APPENDIX.md` merge with `merge=union` (`.gitattributes`): both
sides' new steps are kept without a conflict. Union cannot tell an edit from an addition, so after a merge check that
an earlier line edited on both sides was not kept twice, and that steps stay in number order.
