# Working rules for parallel sessions

Several Claude sessions often work on this repository at once, each in its own worktree. These rules keep their step
numbers and deploys from colliding.

The step registry, the release queue and the locks live in `~/.fahares` on the owner's machine. If you work on another
machine, the last section, "Collaborators on another machine", replaces the rules that depend on them.

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

## Collaborators on another machine

These rules are for a session on any machine other than the owner's, where `~/.fahares/steps.tsv` and
`~/.fahares/release-queue.tsv` do not exist. The scripts would start an empty registry there and hand out numbers that
are already taken. `docs/COLLABORATOR_SETUP.md` tells how to bring the site up there, from a data file the owner makes
with `scripts/dev/dev-dump`; `docs/COLLABORATOR_AGENT.md` is the copy of these rules handed to the collaborator's Claude with
that file, so a change here is made there too.

- Push a branch of your own to `fahares/fahares`, one per task, and open a pull request to `main`. `main` takes no
  direct push from a collaborator.
- Take only the `docs/BACKLOG.md` items the owner has marked with your name.
- Do not run `scripts/dev/reserve-step`, `scripts/dev/release-queue`, `scripts/dev/prod-run` or `scripts/dev/main-lock`.
  Do not name a step number in a commit, a file name or a doc, and do not edit `docs/PROCESS_DOCUMENTATION.md` or
  `docs/TECHNICAL_APPENDIX.md`. The owner's task-management session reserves the number and writes the step entry.
- The pull request description takes the place of the release-queue line and the step entry. It says what the change
  is, the goal, the actions and the result, in the form at the end of `docs/PROCESS_DOCUMENTATION.md`.
- Before opening or updating the pull request, merge `origin/main` into the branch and run the full suite against your
  own test database. `scripts/dev/test-suite` and its lock are not needed on your machine.

Merging a pull request to `main` deploys it to production, with no one else in between.

- You may merge your own pull request when it touches only the paths that `.github/CODEOWNERS` leaves without an owner:
  templates, styles, scripts, images, interface strings and tests. GitHub asks for no review on these.
- Any other path (`app/`, `database/`, `config/`, `routes/`, `docker/`, `.github/`, `scripts/`, the package files, the
  docs) needs the owner's approval, and GitHub blocks the merge until then. Do not move logic into a template to get
  around this. A migration or a data command is always the owner's to release and run.
- Merge one pull request at a time. Wait for the `image.yml` run to finish, then open the changed pages on
  `https://fahares.net`. If the site is broken, revert the pull request on GitHub and merge the revert.
- If GitHub says `main` is locked or read-only, a production data run is under way on the owner's machine. Wait for it;
  do not ask for the lock to be lifted.
- You have no access to the server or to Coolify.

What the owner's sessions do about this:

- `scripts/dev/prod-run` locks `main` on GitHub for the length of a run (`scripts/dev/main-lock`), and refuses or waits
  while an `image.yml` build is queued or running. If a run died and left `main` locked, `scripts/dev/main-lock off`
  frees it. The protection of `main` is written in that script; change it there and apply it with `off`.
- `origin/main` may have moved since the last release. The task-management session fetches it and merges it into the
  release batch before the suite.
- At a release it also reads `gh pr list --state open` for pull requests waiting for the owner, and
  `gh pr list --state merged --search "-label:logged"` for the collaborator's merges that have no step entry yet. For
  each of these it reserves a step number, writes the entry from the pull request description, and adds the `logged`
  label.
