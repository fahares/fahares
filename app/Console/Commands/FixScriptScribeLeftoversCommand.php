<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\Script;
use App\Suggestions\RecordRemover;
use App\Support\CopyDateRange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What the script cleanup left (backlog item 52, step 14.434), from database/data/item52_script_scribe_fixes.json:
 *  - «scripts»: copies whose «نوع خط» is wrong. Ten «تایپی» copies whose library catalogue page gives a handwritten script,
 *    and two «پهلوی» copies whose script field held the copy's whole description (the description is already in their
 *    notes). Each gets its script wording and its script links.
 *  - «autographs»: copies whose scribe name is the catalogue's «کاتب = مؤلف», «= مؤلف (احتمالاً)», «= مؤلف ؟» … The name is
 *    emptied, the copy becomes an autograph, and the doubt goes to `autograph_hedge`. A scribe link to a made-up person
 *    («کاتب = مؤلف») moves to the work's author when the catalogue does not doubt it, and goes otherwise; the made-up
 *    person is removed once nothing links to it. A date glued to the name becomes the copy date.
 * A copy is found by city, library, shelfmark and work slug, and changed only while its field still holds the value the
 * file names, so a second run changes nothing. Every write is in the audit log.
 */
class FixScriptScribeLeftoversCommand extends Command
{
    protected $signature = 'fahares:fix-script-scribe-leftovers
        {--file=database/data/item52_script_scribe_fixes.json : The decision file}
        {--apply : Write; without it the command only reports}';

    protected $description = 'Backlog item 52: wrong «تایپی» and «پهلوی» scripts, and «= مؤلف» scribe names';

    private const FIX = 'fix-script-scribe-leftovers (step 14.434, backlog item 52)';

    /** A made-up person whose name is the catalogue's «کاتب = مؤلف» with or without a date after it */
    public const PLACEHOLDER = '/^\s*(?:کاتب\s*)?=\s*مؤلف/u';

    private array $counts = ['scripts' => 0, 'autographs' => 0, 'already' => 0, 'missing' => 0, 'people_removed' => 0];

    public function handle(): int
    {
        // a fresh count each run: the container hands out the same command object to each call in one process
        $this->counts = array_map(fn () => 0, $this->counts);
        $apply = (bool) $this->option('apply');
        $data = json_decode((string) file_get_contents(base_path($this->option('file'))), true, flags: JSON_THROW_ON_ERROR);
        $touched = [];

        foreach ($data['scripts'] as $row) {
            if (! $m = $this->copy($row)) {
                continue;
            }
            if (! str_starts_with((string) $m->script_names, $row['from'])) {
                $this->counts['already']++;
                continue;
            }
            $this->counts['scripts']++;
            $this->line("  #{$m->id} «" . mb_substr($m->script_names, 0, 40) . '» → «' . $row['script_names'] . (isset($row['script_style']) ? " ({$row['script_style']})" : '') . '»');
            if ($apply) {
                $this->writeScripts($m, $row);
                $touched[] = $m->id;
            }
        }

        $placeholders = [];
        foreach ($data['autographs'] as $row) {
            if (! $m = $this->copy($row)) {
                continue;
            }
            if ($m->scribe_name !== $row['from']) {
                $this->counts['already']++;
                continue;
            }
            $this->counts['autographs']++;
            $this->line("  #{$m->id} «{$m->scribe_name}» → autograph" . ($row['hedge'] ? " ({$row['hedge']})" : '') . (isset($row['copy_date_raw']) ? ", date «{$row['copy_date_raw']}»" : ''));
            if ($apply) {
                $placeholders = array_merge($placeholders, $this->writeAutograph($m, $row));
                $touched[] = $m->id;
            }
        }

        // every made-up person, also one a first run left linked, goes once nothing links to it
        if ($apply) {
            $placeholders = array_merge($placeholders, Person::where('name', 'like', '%= مؤلف%')->orWhere('name', 'like', '%=مؤلف%')->pluck('id')->all());
        }
        foreach (array_unique($placeholders) as $id) {
            $this->removeIfUnlinked($id);
        }

        if ($touched) {
            Manuscript::whereIn('id', $touched)->get()->searchable();
            // the authors who became these copies' scribes
            $scribes = DB::table('manuscript_people')->whereIn('manuscript_id', $touched)->where('role', ManuscriptPerson::SCRIBE)->whereNotNull('person_id')->distinct()->pluck('person_id');
            foreach (Person::whereIn('id', $scribes)->get() as $person) {
                $person->recountContributions();
                $person->searchable();
            }
            DB::statement('UPDATE scripts SET manuscripts_count = (SELECT COUNT(*) FROM manuscript_script WHERE manuscript_script.script_id = scripts.id)');
        }

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        foreach ($this->counts as $k => $n) {
            $this->line(sprintf('  %-16s %4d', $k, $n));
        }

        return self::SUCCESS;
    }

    private function copy(array $row): ?Manuscript
    {
        $m = Manuscript::where('city', $row['city'])->where('library', $row['library'])->where('shelfmark', $row['shelfmark'])
            ->whereHas('work', fn ($q) => $q->where('slug', $row['work']))->first();
        if (! $m) {
            $this->counts['missing']++;
            $this->warn("  not found: {$row['city']} / {$row['library']} / {$row['shelfmark']} ({$row['work']})");
        }

        return $m;
    }

    private function writeScripts(Manuscript $m, array $row): void
    {
        DB::transaction(function () use ($m, $row) {
            $old = ['script_names' => $m->script_names, 'script_style' => $m->script_style,
                'scripts' => DB::table('manuscript_script')->join('scripts', 'scripts.id', '=', 'manuscript_script.script_id')
                    ->where('manuscript_id', $m->id)->orderByDesc('is_primary')->pluck('scripts.name')->all()];
            $ids = array_map(fn ($name) => Script::where('name', $name)->value('id') ?? throw new \RuntimeException("no script «{$name}»"), $row['scripts']);
            DB::table('manuscript_script')->where('manuscript_id', $m->id)->delete();
            DB::table('manuscript_script')->insert(array_map(fn ($id, $i) => ['manuscript_id' => $m->id, 'script_id' => $id, 'is_primary' => $i === 0], $ids, array_keys($ids)));
            $new = ['script_names' => $row['script_names'], 'script_style' => $row['script_style'] ?? $m->script_style];
            DB::table('manuscripts')->where('id', $m->id)->update($new + ['updated_at' => now()]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $m->id, 'action' => 'corpus_update',
                'old_values' => $old, 'new_values' => $new + ['scripts' => $row['scripts'], 'page' => $row['page'], 'corpus_fix' => self::FIX]]);
        });
    }

    /** @return list<int> the made-up people the copy was linked to */
    private function writeAutograph(Manuscript $m, array $row): array
    {
        return DB::transaction(function () use ($m, $row) {
            $hedge = $row['hedge'] ?? null;
            $author = $m->work?->author_id;
            $placeholders = [];
            $old = ['scribe_name' => $m->scribe_name, 'scribe_id' => $m->scribe_id, 'is_autograph' => $m->is_autograph, 'autograph_hedge' => $m->autograph_hedge];
            $new = ['scribe_name' => null, 'is_autograph' => true, 'autograph_hedge' => $hedge];

            $rows = DB::table('manuscript_people as mp')->join('people as p', 'p.id', '=', 'mp.person_id')
                ->where('mp.manuscript_id', $m->id)->where('mp.role', ManuscriptPerson::SCRIBE)->get(['mp.id', 'mp.person_id', 'p.name']);
            foreach ($rows as $r) {
                if (! preg_match(self::PLACEHOLDER, $r->name)) {
                    continue;
                }
                $placeholders[] = $r->person_id;
                $authorLinked = $author && DB::table('manuscript_people')->where('manuscript_id', $m->id)->where('role', ManuscriptPerson::SCRIBE)->where('person_id', $author)->exists();
                if (! $hedge && $author && ! $authorLinked) {
                    DB::table('manuscript_people')->where('id', $r->id)->update(['person_id' => $author, 'updated_at' => now()]);
                } else {
                    DB::table('manuscript_people')->where('id', $r->id)->delete();
                }
            }
            if ($m->scribe_id && in_array($m->scribe_id, $placeholders, true)) {
                $new['scribe_id'] = ! $hedge && $author ? $author : null;
            }

            if (isset($row['copy_date_raw']) && blank($m->copy_date_raw)) {
                $range = CopyDateRange::parse($row['copy_date_raw']);
                $new += ['copy_date_raw' => $row['copy_date_raw'], 'copy_date_hijri_year' => $range['from'], 'copy_date_from' => $range['from'],
                    'copy_date_to' => $range['to'], 'copy_date_kind' => $range['kind'], 'copy_date_certainty' => $range['certainty'], 'copy_date_hedge_raw' => $range['hedge']];
            }

            DB::table('manuscripts')->where('id', $m->id)->update($new + ['updated_at' => now()]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $m->id, 'action' => 'corpus_update',
                'old_values' => $old, 'new_values' => $new + ['corpus_fix' => self::FIX]]);

            return $placeholders;
        });
    }

    /** A made-up «کاتب = مؤلف» person goes once no copy or work links to it; its page then says it was removed. */
    private function removeIfUnlinked(int $id): void
    {
        $person = Person::find($id);
        if (! $person || ! preg_match(self::PLACEHOLDER, $person->name)) {
            return;
        }
        if (DB::table('work_author')->where('person_id', $id)->exists() || (new RecordRemover)->blockers($person)) {
            $this->warn("  person #{$id} «{$person->name}» still has links; kept");

            return;
        }
        DB::transaction(function () use ($person) {
            $removed = (new RecordRemover)->remove($person);
            AuditLog::create(['user_id' => null, 'auditable_type' => Person::class, 'auditable_id' => $person->id, 'action' => 'remove',
                'old_values' => $removed, 'new_values' => ['corpus_fix' => self::FIX]]);
        });
        $person->unsearchable();
        $this->counts['people_removed']++;
        $this->line("  removed person #{$person->id} «{$person->name}»");
    }
}
