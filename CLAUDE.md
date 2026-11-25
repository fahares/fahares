# Working rules for parallel sessions

Several Claude sessions often work on this repository at once, each in its own worktree. These rules keep their step
numbers and deploys from colliding.

## Persian spelling

- After a word ending in a silent heh, write the ezafe as «ۀ» (U+06C0): «صفحۀ»، «نسخۀ»، «همۀ»، «کتابخانۀ». Never «ه‌ی»
  (heh, ZWNJ, yeh). This holds for the site's templates, interface strings, PHP strings, config and editorial content,
  for the docs you write, for your replies to the user, and for any text you suggest to the user.
- The texts of sources (Fankha's `raw_text`, `source_pages`, page images' text) keep the spelling of the print.
- Code that reads source text or users' queries may still need to match «ه‌ی» as an input form: keep such patterns and
  lists, and add «ۀ» beside them.

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

## Backlog item numbers

- Take the number of a new `docs/BACKLOG.md` item («### N. …») only from `scripts/dev/reserve-backlog "short title"
  <session-name>`. It prints the next free number from a shared registry outside git (`~/.fahares/backlog.tsv`) under a
  lock, one above both the registry and origin/main's backlog.
- Reserve before writing the item, and use that number in the item, in step entries and in code comments. Never take
  the next number from `docs/BACKLOG.md` itself: another session's branch may already hold it.
- A reserved number that is not used stays as a gap; never reuse it. `scripts/dev/reserve-backlog --list` shows the
  recent reservations.

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
- A full run (no options) that passes with the code committed records the commit's code tree (every file but `docs/`
  and the top-level `*.md`) in `~/.fahares/tested.tsv`. `scripts/dev/test-suite --tested` exits 0 when the current
  commit's code is there, so the releasing session runs the suite only when a batch's code is new: several branches
  together, `main` moved on since a session's run, or a commit after it. So commit before your last full run.
- The task-management session releases: it first reads `scripts/dev/release-queue --list`, merges the ready branches
  into `main`, runs the full suite once on the merged result (unless `--tested` finds that code already tested), and
  pushes once, when the user asks for a release. After the push it marks each one
  `scripts/dev/release-queue done <branch> <release-commit>` (or `drop <branch> "why"`).
  Only one session releases at a time: if a task-management session of another account is active, agree with the user
  which one releases.
- When a session needs its code on production soon (for example to run a data command), it says so in its
  ready report, so a release can be made then.
- A docs-only commit, such as the record of a production run, waits for the next release. A push of only `docs/`, root `*.md` or `scripts/dev/` builds and deploys nothing (`.github/workflows/image.yml`), so such a push is harmless but still waits for the release.
- Production data commands still need the user's own approval in the session that runs them, a backup first,
  and one command at a time across sessions.
- Run every command that writes production data through `scripts/dev/prod-run <session-name> -- '<command>'`, never
  plain `ssh fahares-app`. It holds a lock shared by all sessions on this machine and refuses (exit 75) while another run
  holds it, an artisan run is still going in production, or a MariaDB transaction or statement has been open over five
  minutes (`PROD_RUN_DB_AGE`). `--wait` queues instead of refusing; `--status` shows the holder, the production artisan
  runs, the long transactions and statements, and the last runs (`~/.fahares/prod-runs.log`). Read-only checks may use
  plain ssh.
- Run heavy read-only queries (statistics, counts over big joins) on the local copy `fahares_prod`, refreshed from the
  latest daily backup with `scripts/dev/refresh-prod-copy`. One that must run on production runs outside a transaction
  and without a temporary table. Stopping its ssh or tinker does not stop the statement in MariaDB, which keeps its row
  locks: check `scripts/dev/prod-run --status` afterwards. Killing another session's thread needs the user's OK.
- A backup taken before a production run must leave the container at once: copy it to
  `../fahares-sources/backups/step13/` on this machine before reporting the run. `storage/app` and `/tmp` in the app
  container are wiped by the next deploy, which may start the moment your run frees the lock.
- Before a release push, the task-management session checks `scripts/dev/prod-run --status`: a deploy replaces the app
  container and would cut off a running command, and its migrations would wait behind a long open transaction.

## Merging the step logs

`docs/PROCESS_DOCUMENTATION.md` and `docs/TECHNICAL_APPENDIX.md` merge with `merge=union` (`.gitattributes`): both
sides' new steps are kept without a conflict. Union cannot tell an edit from an addition, so after a merge check that
an earlier line edited on both sides was not kept twice, and that steps stay in number order.
