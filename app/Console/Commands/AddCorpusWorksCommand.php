<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Sources\SourceEntrySync;
use App\Suggestions\ContributorRelations;
use App\Suggestions\ProtectedColumns;
use App\Suggestions\RecordMerger;
use App\Support\PersonNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Adds the Fankha works the old parser lost and parser v2 found (item 87, step 13.79), from the decision file of
 * the corpus script `fankha_v2_new_works_export.py`. Every work was checked against its page images first.
 *
 * - works: each is made from v2's columns with its slug suffix («-v18-2510», the volume's next free ordinal), its
 *   author and translator linked only to a person PersonNameMatcher is confident of (else kept as a name), its
 *   subjects and languages, and its copies: a copy the site holds under another work moves to it (found by that
 *   work's slug, city, library and shelfmark, never by id), the rest are made with the library the site uses for
 *   the same catalogue name.
 * - fills: site works the moves leave empty get their own copies, moved or made the same way.
 * - work_updates: columns of a site work that came from a neighbouring entry, written only where the site still
 *   holds the exported value and no reviewed correction protects the column.
 *
 * A work is added whole or not at all: one copy that cannot be found leaves it out. Everything is logged in the
 * records' history (create, corpus_update). Without --apply it only reports. Afterwards run catalogs:link-volumes,
 * fahares:rebuild-scripts and fahares:link-autograph-scribes.
 */
class AddCorpusWorksCommand extends Command
{
    protected $signature = 'fahares:add-corpus-works
                            {--file= : Decision file (database/data/fankha_new_works_20261030.json)}
                            {--apply : Write the changes; without it the command only reports what it would do}';

    protected $description = 'Add the works parser v2 found and the site lacks, with their copies';

    private const WORK_COLUMNS = ['primary_title', 'clean_title', 'alternative_titles', 'transliteration', 'author_name',
        'composition_date_raw', 'composition_place', 'work_form', 'subject_summary', 'language_summary', 'incipit_text',
        'explicit_text', 'description', 'bibliography', 'print_info', 'dedication', 'related_work',
        'commentaries_and_glosses', 'raw_text', 'volume_number', 'page_start', 'page_end'];

    private const COPY_COLUMNS = ['sequence_number', 'page_start', 'page_end', 'city', 'library', 'shelfmark', 'raw_text',
        'scribe_name', 'has_other_scribes', 'is_bika', 'is_bita', 'is_autograph', 'copy_date_raw', 'copy_date_hijri_year',
        'copy_place', 'commissioned_by', 'folios', 'lines', 'dimensions', 'paper', 'binding', 'incipit_text',
        'explicit_text', 'is_corrected', 'has_marginal_notes', 'is_ruled', 'has_catchwords', 'is_facsimile',
        'is_collated', 'is_illuminated', 'is_illustrated', 'has_author_marginalia', 'metadata'];

    private const UPDATE_COLUMNS = ['author_name', 'raw_text', 'description', 'bibliography', 'transliteration',
        'composition_date_raw', 'incipit_text', 'explicit_text', 'alternative_titles'];

    private PersonNameMatcher $matcher;

    /** @var array<string, ?array{0: string, 1: string, 2: ?int}> «city|library» => the site's city, library, library_id */
    private array $libraries = [];

    private array $problems = [];

    public function handle(RecordMerger $merger, ContributorRelations $relations): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_new_works_20261030.json');
        $plan = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! $plan || ! isset($plan['works'])) {
            $this->error("Decision file missing or invalid: {$path}");
            return 1;
        }
        $apply = (bool) $this->option('apply');
        $this->matcher = new PersonNameMatcher;
        $note = basename($path);

        // resolve everything first: a work goes ahead only when all its copies are found
        $works = [];
        foreach ($plan['works'] as $w) {
            if ($this->workBySuffix($w['slug_suffix'])) {
                $this->line("already added: {$w['v2']} {$w['slug_suffix']}");
                continue;
            }
            $copies = $this->resolveCopies($w['copies'], $w['v2'], (int) $w['columns']['volume_number']);
            if ($copies !== null) {
                $works[] = [$w, $copies];
            }
        }
        $fills = [];
        foreach ($plan['fills'] ?? [] as $f) {
            $work = Work::where('slug', $f['work_slug'])->first();
            if (! $work) {
                $this->problems[] = "{$f['v2']}: work {$f['work_slug']} not found";
                continue;
            }
            $copies = $this->resolveCopies($f['copies'], $f['v2'], (int) $work->volume_number, $work);
            if ($copies === []) {
                $this->line("already filled: {$f['work_slug']}");
            } elseif ($copies !== null) {
                $fills[] = [$work, $f, $copies];
            }
        }
        $updates = [];
        $guard = ProtectedColumns::for(Work::class);
        foreach ($plan['work_updates'] ?? [] as $u) {
            $outcome = $this->checkUpdate($u, $guard);
            if ($outcome instanceof Work) {
                $updates[] = [$outcome, $u];
            } elseif ($outcome !== 'already') {
                $this->problems[] = "{$u['work_slug']}: {$outcome}";
            }
        }

        $made = $moved = 0;
        foreach (array_merge(array_column($works, 1), array_column($fills, 2)) as $copies) {
            foreach ($copies as $c) {
                isset($c['manuscript']) ? $moved++ : $made++;
            }
        }
        foreach ($this->problems as $line) {
            $this->warn($line);
        }
        $missing = array_keys(array_filter($this->libraries, fn ($l) => ! $l || ! $l[2]));
        if ($missing) {
            $this->warn('Libraries with no site record (copies keep the name only): ' . implode('; ', $missing));
        }
        $this->info(($apply ? 'Adding ' : 'Would add ') . count($works) . ' works, fill ' . count($fills)
            . ' works and update ' . count($updates) . "; copies: {$moved} moved, {$made} made.");
        if (! $apply || (! $works && ! $fills && ! $updates)) {
            return 0;
        }

        $touchedWorks = [];
        $touchedCopies = [];
        Work::withoutSyncingToSearch(fn () => Manuscript::withoutSyncingToSearch(function () use ($works, $fills, $updates, $merger, $relations, $note, &$touchedWorks, &$touchedCopies) {
            DB::transaction(function () use ($works, $fills, $updates, $merger, $relations, $note, &$touchedWorks, &$touchedCopies) {
                foreach ($works as [$w, $copies]) {
                    $work = $this->makeWork($w, $relations, $note);
                    $touchedWorks[] = $work->id;
                    array_push($touchedCopies, ...$this->placeCopies($work, $copies, $merger, $note, $touchedWorks));
                }
                foreach ($fills as [$work, $f, $copies]) {
                    $touchedWorks[] = $work->id;
                    array_push($touchedCopies, ...$this->placeCopies($work, $copies, $merger, $note, $touchedWorks));
                }
                foreach ($updates as [$work, $u]) {
                    $this->updateWork($work, $u, $note);
                    $touchedWorks[] = $work->id;
                }
                $this->recount($touchedWorks);
            });
        }));

        SourceEntrySync::syncIds(Work::class, $touchedWorks);
        SourceEntrySync::syncIds(Manuscript::class, $touchedCopies);
        foreach (array_chunk(array_values(array_unique($touchedCopies)), 100) as $chunk) {
            Manuscript::whereIn('id', $chunk)->get()->searchable();
            gc_collect_cycles();
        }
        foreach (array_chunk(array_values(array_unique($touchedWorks)), 100) as $chunk) {
            Work::whereIn('id', $chunk)->get()->searchable();
            gc_collect_cycles();
        }
        $this->info('Done. Now run catalogs:link-volumes, fahares:rebuild-scripts and fahares:link-autograph-scribes.');

        return 0;
    }

    /** The one work whose slug ends with the natural key («-v18-2510»). */
    private function workBySuffix(string $suffix): ?Work
    {
        return Work::where('slug', 'like', "%{$suffix}")->get()->first(fn ($w) => str_ends_with($w->slug, $suffix));
    }

    private function findMove(array $m): ?Manuscript
    {
        $rows = Manuscript::whereHas('work', fn ($q) => $q->where('slug', $m['from_slug']))
            ->where('city', $m['city'])->where('library', $m['library'])->where('shelfmark', $m['shelfmark'])->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }

    /**
     * Each copy as a manuscript to move or a row to make; null (and a problem) when one cannot be placed. A copy the
     * target work already holds (a fill run before) is left out.
     */
    private function resolveCopies(array $copies, string $key, int $volume, ?Work $target = null): ?array
    {
        $out = [];
        foreach ($copies as $c) {
            $shelfmark = $c['move']['shelfmark'] ?? $c['make']['shelfmark'];
            $page = isset($c['move']) ? null : $c['make']['page_start'];
            if ($target && $target->manuscripts()->where('shelfmark', $shelfmark)
                ->when($page, fn ($q) => $q->where('page_start', $page))
                ->when(isset($c['move']), fn ($q) => $q->where('city', $c['move']['city'])->where('library', $c['move']['library']))->exists()) {
                continue;
            }
            if (isset($c['move'])) {
                $m = $this->findMove($c['move']);
                if (! $m) {
                    $this->problems[] = "{$key}: copy {$c['move']['city']}، {$c['move']['library']}، {$c['move']['shelfmark']} not found (once) under {$c['move']['from_slug']}; work left out";
                    return null;
                }
                $out[] = ['manuscript' => $m, 'sequence_number' => $c['sequence_number']];
            } else {
                $row = $c['make'];
                $this->libraries[$row['city'] . '|' . $row['library']] ??= $this->library($row['city'], $row['library']);
                $out[] = ['make' => $row + ['volume_number' => $volume]];
            }
        }

        return $out;
    }

    /**
     * The site's city, library and library_id for a catalogue's library name: what the copies whose own text starts
     * with the same «city؛ library؛» mostly have (libraries were renamed after the import), else the name as it is.
     */
    private function library(string $city, string $library): ?array
    {
        $prefix = preg_quote("{$city}؛ {$library}؛", '/');
        $row = DB::table('manuscripts')->whereRaw('raw_text REGEXP ?', ["^([0-9]+\\. )?{$prefix}"])
            ->selectRaw('city, library, library_id, count(*) n')->groupBy('city', 'library', 'library_id')->orderByDesc('n')->first();
        if ($row) {
            return [$row->city, $row->library, $row->library_id];
        }
        $id = DB::table('libraries')->where('city', $city)->where('name', $library)->value('id');

        return [$city, $library, $id];
    }

    private function makeWork(array $w, ContributorRelations $relations, string $note): Work
    {
        $cols = array_intersect_key($w['columns'], array_flip(self::WORK_COLUMNS));
        $base = Str::slug($cols['transliteration'] ?: $cols['clean_title']) ?: 'work';
        $author = $this->person($w['author'] ?? null);
        $work = Work::create($cols + [
            'slug' => mb_substr($base, 0, 180) . $w['slug_suffix'],
            'author_id' => $author?->id,
        ]);
        $this->log(Work::class, $work->id, 'create', [], ['slug' => $work->slug, 'author_id' => $author?->id, 'corpus_fix' => $note, 'v2' => $w['v2']]);

        SourceEntrySync::syncRecord($work);
        $entry = SourceEntry::where('citable_type', Work::class)->where('citable_id', $work->id)
            ->where('origin', SourceEntry::ORIGIN_FANKHA)->orderByDesc('is_primary')->orderBy('id')->value('id');
        foreach ($w['roles'] ?? [] as $r) {
            $person = $this->person($r);
            $hedge = trim((string) ($r['hedge'] ?? ''));
            try {
                $relations->addRole($work, $r['role'], $person, $person ? null : $r['name'], null, null, [
                    'certainty' => PersonNameMatcher::certainty($hedge),
                    'hedge_raw' => $hedge !== '' ? mb_substr($hedge, 0, 60) : null,
                    'source_entry_id' => $entry,
                ]);
            } catch (InvalidArgumentException $e) {
                $this->warn("{$w['v2']}: {$r['role']} {$r['name']}: {$e->getMessage()}");
            }
        }
        foreach (['subjects' => ['subjects', 'subject_work', 'subject_id'], 'languages' => ['languages', 'language_work', 'language_id']] as $field => [$table, $pivot, $column]) {
            foreach ($w[$field] ?? [] as $name) {
                $id = DB::table($table)->where('name', $name)->value('id');
                if ($id) {
                    DB::table($pivot)->insertOrIgnore([$column => $id, 'work_id' => $work->id]);
                    DB::table($table)->where('id', $id)->increment('works_count');
                } else {
                    $this->warn("{$w['v2']}: {$field} «{$name}» not on the site; kept in the summary only");
                }
            }
        }

        return $work;
    }

    /** @return int[] the copies placed */
    private function placeCopies(Work $work, array $copies, RecordMerger $merger, string $note, array &$touchedWorks): array
    {
        $ids = [];
        foreach ($copies as $c) {
            if (isset($c['manuscript'])) {
                $m = $c['manuscript'];
                $old = ['work_id' => $m->work_id, 'sequence_number' => $m->sequence_number];
                $touchedWorks[] = $m->work_id;
                $merger->moveManuscript($m, $work);
                $m->update(['sequence_number' => $c['sequence_number']]);
                $this->log(Manuscript::class, $m->id, 'corpus_update', $old, ['work_id' => $work->id, 'sequence_number' => $c['sequence_number'], 'corpus_fix' => $note, 'category' => 'new_work']);
                $ids[] = $m->id;
                continue;
            }
            $row = array_intersect_key($c['make'], array_flip([...self::COPY_COLUMNS, 'volume_number']));
            [$city, $library, $libraryId] = $this->libraries[$row['city'] . '|' . $row['library']] ?? [$row['city'], $row['library'], null];
            $scribe = $row['scribe_name'] && ! $row['is_bika'] ? $this->person(['name' => $row['scribe_name']]) : null;
            $m = Manuscript::create(array_merge($row, [
                'work_id' => $work->id,
                'city' => $city,
                'library' => $library,
                'library_id' => $libraryId,
                'scribe_id' => $scribe?->id,
            ]));
            $this->log(Manuscript::class, $m->id, 'create', [], ['work_id' => $work->id, 'corpus_fix' => $note, 'category' => 'new_work']);
            $ids[] = $m->id;
        }

        return $ids;
    }

    private function checkUpdate(array $u, ProtectedColumns $guard): Work|string
    {
        $work = Work::where('slug', $u['work_slug'])->first();
        if (! $work) {
            return 'work not found';
        }
        $already = true;
        foreach ($u['changes'] as $field => $change) {
            if (! in_array($field, self::UPDATE_COLUMNS, true)) {
                return "field {$field} not allowed";
            }
            $current = $work->{$field};
            if ($this->same($current, $change['to'])) {
                continue;
            }
            $already = false;
            if (! $this->same($current, $change['from'])) {
                return "{$field} changed on the site since the export";
            }
        }
        if ($already) {
            return 'already';
        }
        $fields = array_fill_keys(array_keys($u['changes']), true);
        if (array_diff_key($fields, $guard->strip($work->id, $fields))) {
            return 'protected by a reviewed correction';
        }

        return $work;
    }

    private function updateWork(Work $work, array $u, string $note): void
    {
        $new = array_map(fn ($c) => $c['to'], $u['changes']);
        $old = array_map(fn ($c) => $c['from'], $u['changes']);
        if (array_key_exists('author_name', $new)) {
            $old['author_id'] = $work->author_id;
            $new['author_id'] = $this->person($u['author'] ?? null)?->id;
        }
        $work->update($new);
        $this->log(Work::class, $work->id, 'corpus_update', $old, $new + ['corpus_fix' => $note, 'category' => 'new_work', 'v2' => $u['v2']]);
    }

    /** The person a name confidently is (PersonNameMatcher), else null and the name stays text. */
    private function person(?array $p): ?Person
    {
        if (! $p || ! ($p['name'] ?? null)) {
            return null;
        }

        return $this->matcher->match(PersonNameMatcher::clean($p['name']), $p['century'] ?? null)[0];
    }

    private function recount(array $workIds): void
    {
        foreach (array_unique($workIds) as $id) {
            DB::table('works')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('work_id', $id)->count()]);
        }
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return $a == $b;
        }
        if ($a === null || $b === null) {
            return $a === $b || ((string) $a === '' && (string) $b === '');
        }

        return (string) $a === (string) $b;
    }

    private function log(string $type, int $id, string $action, array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => null,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
