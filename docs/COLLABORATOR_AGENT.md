# Instructions for the collaborator's Claude

You are helping a designer work on Fahares (`https://fahares.net`, repository `fahares/fahares`), a Laravel site. They
are a strong web designer with little experience of git, Docker or the command line, and they work on Windows. Your
job is to bring the site up on their machine, and afterwards to carry their design changes through git and onto the
live site.

How to work with them:

- Speak Persian, in plain words. Run the git, Docker and GitHub commands yourself and say in one line what each step
  did. Do not ask them to type commands unless a step needs their own hands (an installer, a sign-in in the browser).
- Never ask for a password or a token, and never type one. They sign in themselves in the browser.
- The setup below was tested on Linux, not on Windows. When a command fails on Windows, find the cause and adapt it;
  the goal of each step is stated so that you can.

This kit holds three files: this one, `READ-ME-FIRST.txt` for the designer, and the data file
`fahares-dev-<date>.sql.zst`. Keep the data file outside the repository folder, never commit it, and never upload or
send it anywhere: it is a copy of the site's database.

## Part 1: one-time setup

Do the steps in order and check each before the next.

### 1. Tools

Check what is there (`docker --version`, `docker compose version`, `git --version`, `gh --version`) and install what is
missing. Ask the designer to run an installer when it needs their approval.

- Docker Desktop, with the WSL 2 backend, started and running.
- Git for Windows (it brings Git Bash).
- GitHub CLI (`gh`).

About 6 GB of free disk space is needed.

### 2. Where the repository goes

- If you run inside WSL (Linux commands such as `uname` work and the home folder is `/home/...`), clone into the WSL
  home, for example `~/fahares`. This is the fast setup.
- If you run on Windows itself, clone to a short path such as `C:\dev\fahares`. Run the commands in Git Bash, not
  PowerShell, and run `export MSYS_NO_PATHCONV=1` first so that paths inside containers are not rewritten. Pages will
  take a few seconds each, because containers read a Windows folder slowly. Tell the designer that this is expected. If
  it gets in the way of their work, offer to move the repository into WSL.

### 3. GitHub

- `gh auth login --web --git-protocol https`: the designer signs in to their GitHub account in the browser.
- `gh auth setup-git`.
- Set the commit identity from the account: the name they want shown, and the account's no-reply address
  (`<id>+<login>@users.noreply.github.com`, from `gh api user`).
- The owner invites the account to the repository. The designer accepts at
  `https://github.com/fahares/fahares/invitations`. Until then a push is refused; setup can go on without it.

### 4. Code and services

```bash
git clone -c core.autocrlf=false https://github.com/fahares/fahares.git
cd fahares
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app sh -c 'npm install && npm run build'
```

`core.autocrlf=false` keeps Unix line endings; shell scripts with Windows line endings do not run. If port 3306, 7701
or 8000 is taken on the machine, find what holds it and tell the designer before changing anything.

### 5. Data

The data file is copied into the database container and unpacked there, so no tool is needed on the machine. The
import takes about five minutes, more on Windows.

```bash
docker compose cp <path to>/fahares-dev-<date>.sql.zst db:/tmp/dev.sql.zst
docker compose exec db sh -c 'zstd -dc /tmp/dev.sql.zst | mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
docker compose exec db rm /tmp/dev.sql.zst
docker compose exec app php artisan migrate
```

Check: `docker compose exec app php artisan tinker --execute="echo App\Models\Work::count();"` prints a number above
70,000.

### 6. Search index

Lists and search read from Meilisearch and stay empty without this. It takes a long time; run the commands one after
another and tell the designer they can leave it running.

```bash
docker compose exec app php artisan scout:sync-index-settings
docker compose exec app php artisan scout:import "App\Models\Work"
docker compose exec app php artisan scout:import "App\Models\Person"
docker compose exec app php artisan scout:import "App\Models\Manuscript"
docker compose exec app php artisan scout:import "App\Models\SourceItem"
```

### 7. An admin user and the tests

The users in the data file are placeholders and cannot sign in. Make one for the designer, with an address and name
they choose. The command asks for a password, so the designer runs this one line in their own terminal, in the
repository folder:

```bash
docker compose exec app php artisan admin:create-user --email=<address> --name="<name>"
```

Then the tests:

```bash
bash scripts/create_test_db.sh
docker compose exec app php artisan test
```

The tests use a separate database, `fahares_test`, and do not touch the imported data. The whole suite should pass on
an untouched checkout. If some tests fail there, note which, so that later you can tell them from failures a change
causes.

### 8. Done

Open `http://localhost:8000`. The home page shows, a search returns results, and a work page opens. Page images of the
catalogues are not in the data and do not show locally; that is expected. So are empty visit statistics, messages and
change history.

## Part 2: everyday work

If `CLAUDE.md` in the repository has a section "Collaborators on another machine", that section is the rule and wins
over this part. Read `CLAUDE.md` in every session; most of it is about the owner's machine, and that section says which
parts are yours.

### Starting a change

- `git fetch origin`, then a new branch from `origin/main`, one branch per task. Never commit on `main`.
- `docker compose exec app php artisan migrate`, in case the structure changed.
- After a change in `resources/css` or `resources/js`, run `docker compose exec app npm run build`. A change in a
  template under `resources/views` needs no build.
- Show the designer the result on `http://localhost:8000` and let them judge it.

### Publishing a change

Merging a pull request to `main` puts it on the live site within minutes. Do it only when the designer says the change
is ready to go live.

1. Commit with a short English message that says what changed. Add no co-author or "generated with" line.
2. `git fetch origin` and merge `origin/main` into the branch.
3. Run the whole suite: `docker compose exec app php artisan test`. Do not publish with a failing test that passed
   before the change.
4. Push the branch and open a pull request to `main`. The description says what the change is, why, and what the result
   is; the owner writes the project log from it.
5. Look at the files in the pull request (`gh pr diff --name-only`).
   - If all of them are under `resources/views/`, `resources/css/`, `resources/js/`, `public/images/`, `lang/` or
     `tests/`, the designer may publish alone: `gh pr merge --merge`.
   - If any file is elsewhere, GitHub blocks the merge until the owner approves. Tell the designer the pull request is
     waiting for the owner, and stop there. Do not move logic into a template to avoid this.
6. After a merge, wait for the `image.yml` run to finish (`gh run watch`), then open the changed pages on
   `https://fahares.net` and confirm they look right. If the site is broken, revert at once: `gh pr revert <number>`,
   then merge the revert the same way, and tell the designer and the owner.
7. One pull request at a time: do not merge the next before the first is live and checked.

If GitHub says `main` is locked or read-only, the owner is running a job on the live database. Wait and try later; it
is not an error.

### Never

- Do not run `scripts/dev/reserve-step`, `scripts/dev/release-queue`, `scripts/dev/prod-run` or `scripts/dev/main-lock`.
  They belong to the owner's machine and would give wrong results here.
- Do not write a step number («گام») anywhere, and do not edit `docs/PROCESS_DOCUMENTATION.md` or
  `docs/TECHNICAL_APPENDIX.md`.
- Do not write migrations or commands that change data. If a design needs one, describe the need in a pull request or
  to the owner.
- Do not force-push, and do not push to `main`.
- Do not commit `.env` or the data file.
