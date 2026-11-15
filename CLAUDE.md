# Working rules for parallel sessions

Several Claude sessions often work on this repository at once, each in its own worktree. These rules keep their step
numbers and deploys from colliding.

The step registry, the release queue and the locks live in `~/.fahares` on the owner's machine. If you work on another
machine or from a fork, the last section, "Outside contributors", replaces the rules that depend on them.

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
  11 Aban 1405; phase 13 ends at 13.173 (13.174 and 13.175 were renumbered 14.2 and 14.3 and stay unused).

## Push and deploy

A push to `main` deploys to production (Coolify) and runs the migrations, so pushes are batched.

- A working session does not push to `main`. It commits on its own branch, merges `origin/main` into it, runs the full
  suite with `scripts/dev/test-suite`, and reports the branch and its last commit as ready for release.
- The ready report goes into the shared release queue: `scripts/dev/release-queue add <branch> <session-name> "what it
  is; the data command needed after the deploy, or none"`. Sessions run under more than one Claude account, and a
  session cannot message the other account's sessions, so the queue (`~/.fahares/release-queue.tsv`, outside git) is
  the one place every releasing session sees. A message to the task-management session may still be sent as well.
- Work in your own worktree (`.claude/worktrees/…`), never by switching branches in the main checkout: the releasing
  session merges there, and a switched checkout puts its commits on the wrong branch.
- Run the suite only through `scripts/dev/test-suite [artisan test options]`, never `docker exec … php artisan test`
  directly: every checkout tests against the same `fahares_test` database, and two runs at once deadlock on its
  migrations. The script waits for a lock shared by all sessions (and for any phpunit started without it);
  `--status` shows who holds it. A session that tests against its own database may keep doing so.
- The task-management session releases: it first reads `scripts/dev/release-queue --list`, merges the ready branches
  into `main`, runs the full suite once on the merged result, and pushes once, when the user asks for a release. After
  the push it marks each one `scripts/dev/release-queue done <branch> <release-commit>` (or `drop <branch> "why"`).
  Only one session releases at a time: if a task-management session of another account is active, agree with the user
  which one releases.
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

## Outside contributors

These rules are for a session on any machine other than the owner's, where `~/.fahares/steps.tsv` and
`~/.fahares/release-queue.tsv` do not exist. The scripts would start an empty registry there and hand out numbers that
are already taken.

- Work in your own fork of `fahares/fahares`, on a branch per task, and send a pull request to `main`. Nobody but the
  owner pushes to `fahares/fahares`.
- Take only the `docs/BACKLOG.md` items the owner has marked with your name.
- Do not run `scripts/dev/reserve-step`, `scripts/dev/release-queue` or `scripts/dev/prod-run`. Do not name a step
  number in a commit, a file name or a doc, and do not edit `docs/PROCESS_DOCUMENTATION.md` or
  `docs/TECHNICAL_APPENDIX.md`. The releasing session reserves the number and writes the step entry when it merges.
- The pull request description takes the place of the release-queue line and the step entry. It says what the change
  is, the goal, the actions and the result, in the form at the end of `docs/PROCESS_DOCUMENTATION.md`. It also gives
  the data command needed after the deploy, with its expected counts, or says there is none.
- Before opening or updating the pull request, merge `origin/main` of `fahares/fahares` into the branch and run the
  full suite against your own test database. `scripts/dev/test-suite` and its lock are not needed on your machine.
- A migration runs on production as soon as the release is deployed. It changes structure only and must work with the
  code of the release before it. A mass data change is a separate artisan command.
- You have no production access. The owner runs any data command, after a backup, in a session on their machine.

The task-management session reads `gh pr list --state open` together with `scripts/dev/release-queue --list` before a
release. It fetches a ready pull request with `git fetch origin pull/<number>/head`, merges it into the release batch
like any other ready branch, and reserves its step number then. GitHub marks the pull request as merged after the push.
