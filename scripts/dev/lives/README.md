# Tying the books of lives to people with a language model

The tools of step 14.232 (backlog item 86). They run on the owner's machine against the local copy of production;
production only loads the judged file.

1. `export.sh <dir> <source-code>` writes the people with works, their works, the source's entries and the ties
   there are, as TSV files.
2. `python3 blocker.py <dir> <source-code>` gives each untied entry at most four candidate people and writes
   `tasks_<code>.jsonl`. It prints its recall on the ties there are. A candidate must carry the name of the entry's
   subject (the given name before the first «بن» of the heading or of the head of the text, the father after it)
   and one more thing: a nisba or laqab, the exact death year, or a title of his in the text.
3. `python3 batches.py <dir> <source-code>` cuts the tasks into batches of 50, without the entries that only point
   to another entry.
4. Each batch is judged by one model agent with the instructions in `brief.txt` (Sonnet subagents of Claude Code in
   step 14.232). An agent answers with a candidate's number or 0, the candidate's name, a confidence, and a phrase
   quoted from the entry.
5. `python3 build.py <data-file.json>` (copied beside the batches) checks the answers and writes the data file: an
   answer whose number and name disagree is dropped, an unquoted phrase caps the confidence at 85, and of two
   entries that chose one person the surer stays, at 89 at most.
6. `php artisan sources:tie-person-lives --file=<data-file.json>` shows what it would make; `--apply` saves the ties
   as unverified; `--undo --apply` takes the unreviewed ones back. A tie under confidence 90
   (`SourceEntry::LIFE_SHOWN_FROM`) is shown to reviewers only, in the queue «زندگی‌نامه‌های خودکار اشخاص».
