<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\Work;
use App\Models\WorkPerson;
use App\Suggestions\ProtectedColumns;
use App\Suggestions\RecordRemover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the people rows that are a line of a work's entry, not a person (backlog item 145; step 14.235). With no
 * transliteration line under it, the parser read the first line of the entry as the author and the year at its end as
 * his life dates: «سال تألیف» [1332ق], «به دستور: شاهرخ», «عهدنامه انگلیس و افغانستان مورخ 30 مه» [1879م].
 *
 * The judged list is a decisions file, keyed by the person's name and the works' slugs, since local and production
 * ids differ. Each row names what its works take back from the line, and only into a column that is empty:
 * the date of composition, the description, the dedication (from which fahares:backfill-work-dedications then makes
 * the dedicatee or commissioner) or the sources. By kind:
 *  - date, translation_date, patron, sentence: the author link goes and the row is removed (RecordRemover, logged as
 *    "remove"). Its address leads to the work, or to the patron the dedication was linked to.
 *  - rename: a real name with the description run on («صالح. منظومه‌ای است…»). The person takes the name; when
 *    another person already has it, the name stays on the work as text and the row is removed, as above.
 *  - unlink: a real person linked as the author through a dedication line. Only the link goes.
 * A row is skipped when the person has anything the file does not name (another work, another role, an entry in a
 * source, an open suggestion), when its line is no longer in the work's text, or when a reviewer verified the work's
 * author. Without --apply it only reports. A second run finds nothing to do.
 */
class RemovePseudoPeopleCommand extends Command
{
    protected $signature = 'fahares:remove-pseudo-people
        {--file=database/data/pseudo_people_step14_235.json : The judged list}
        {--apply : Write the changes}
        {--show=200 : How many rows to list}';

    protected $description = 'Removes people rows that are a line of a work\'s entry, and gives the line back to the work';

    private const REMOVED = ['date', 'translation_date', 'patron', 'sentence'];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var int[] */
    private array $workIds = [];

    /** @var int[] */
    private array $copyIds = [];

    /** @var int[] */
    private array $keptPeople = [];

    /** @var int[] works whose dedication the run filled */
    private array $dedicated = [];

    /** @var array<int, int> removed person id => the work whose dedication names his patron */
    private array $patronOf = [];

    public function handle(RecordRemover $remover): int
    {
        $path = base_path($this->option('file'));
        $doc = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! is_array($doc['people'] ?? null)) {
            $this->error("No people in {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $guard = ProtectedColumns::for(Work::class);
        $this->counts = $this->workIds = $this->copyIds = $this->keptPeople = $this->dedicated = $this->patronOf = [];
        $shown = 0;

        foreach ($doc['people'] as $row) {
            $plan = $this->plan($row, $guard);
            $this->counts[$plan['outcome']] = ($this->counts[$plan['outcome']] ?? 0) + 1;
            if ($shown++ < (int) $this->option('show') && $plan['outcome'] !== 'already') {
                $this->line(sprintf('  %-12s %s%s', $plan['outcome'], mb_substr($row['name'], 0, 70), isset($plan['why']) ? " ({$plan['why']})" : ''));
                foreach ($plan['fills'] ?? [] as $slug => $fill) {
                    $this->line("      {$slug}: " . implode('؛ ', array_map(fn ($k, $v) => "{$k} = " . mb_substr(is_array($v) ? implode(' | ', $v) : (string) $v, 0, 60), array_keys($fill), $fill)));
                }
            }
            if ($apply && isset($plan['person'])) {
                DB::transaction(fn () => $this->write($row, $plan, $doc['step'] ?? null, $remover));
            }
        }

        ksort($this->counts);
        $this->table(array_keys($this->counts), [array_values($this->counts)]);
        if (! $apply) {
            $this->info('Dry run; pass --apply to write.');

            return self::SUCCESS;
        }
        $this->finish();

        return self::SUCCESS;
    }

    /**
     * What the row would change, or why it is skipped.
     *
     * @return array{outcome: string, why?: string, person?: Person, works?: Work[], fills?: array<string, array>, copies?: int[]}
     */
    private function plan(array $row, ProtectedColumns $guard): array
    {
        $people = Person::where('name', $row['name'])->get();
        if ($people->isEmpty()) {
            return ['outcome' => 'already'];
        }
        if ($people->count() > 1) {
            return ['outcome' => 'skipped', 'why' => 'more than one person has this name'];
        }
        $person = $people->first();
        $works = [];
        $fills = [];
        foreach ($row['works'] as $w) {
            $work = Work::where('slug', $w['slug'])->first();
            if (! $work) {
                return ['outcome' => 'skipped', 'why' => "no work {$w['slug']}"];
            }
            $linked = (int) $work->author_id === $person->id
                || DB::table('work_people')->where('work_id', $work->id)->where('person_id', $person->id)->where('role', WorkPerson::AUTHOR)->exists();
            if (! $linked) {
                if ($row['kind'] === 'unlink') {
                    continue;
                }

                return ['outcome' => 'skipped', 'why' => "not the author of {$w['slug']}"];
            }
            if (! str_contains(self::flat($work->raw_text), self::flat($w['line']))) {
                return ['outcome' => 'skipped', 'why' => "the line is not in the text of {$w['slug']}"];
            }
            if ($guard->strip($work->id, ['author_id' => null]) === [] || $guard->protectsRows($work->id, 'people')) {
                return ['outcome' => 'skipped', 'why' => "a reviewer verified the author of {$w['slug']}"];
            }
            $works[] = $work;
            $fills[$work->slug] = $this->fill($work, $w, $row);
        }
        if ($row['kind'] === 'unlink') {
            return $works ? ['outcome' => 'unlink', 'person' => $person, 'works' => $works, 'fills' => $fills, 'copies' => []] : ['outcome' => 'already'];
        }

        // Everything the person has must be what the file names: these works, and their copies «کاتب = مؤلف»
        $ids = array_map(fn (Work $w) => $w->id, $works);
        $copies = Manuscript::where('scribe_id', $person->id)->pluck('work_id', 'id')
            ->union(DB::table('manuscript_people')->join('manuscripts', 'manuscripts.id', '=', 'manuscript_people.manuscript_id')
                ->where('manuscript_people.person_id', $person->id)->where('manuscript_people.role', ManuscriptPerson::SCRIBE)->pluck('manuscripts.work_id', 'manuscripts.id'));
        $other = [
            'another work' => DB::table('work_people')->where('person_id', $person->id)->where('role', WorkPerson::AUTHOR)->whereNotIn('work_id', $ids)->count()
                + Work::where('author_id', $person->id)->whereNotIn('id', $ids)->count(),
            'another role in a work' => DB::table('work_people')->where('person_id', $person->id)->where('role', '!=', WorkPerson::AUTHOR)->count(),
            'another role in a copy' => DB::table('manuscript_people')->where('person_id', $person->id)->where('role', '!=', ManuscriptPerson::SCRIBE)->count(),
            'a copy of another work' => $copies->reject(fn ($workId) => in_array($workId, $ids, true))->count(),
            'an entry in a source' => DB::table('source_entries')->where('citable_type', Person::class)->where('citable_id', $person->id)->count(),
            'an open suggestion' => FieldSuggestion::where('suggestable_type', Person::class)->where('suggestable_id', $person->id)->where('status', 'pending')->count(),
            'a period of life' => DB::table('person_periods')->where('person_id', $person->id)->count(),
            'a cataloguer record' => DB::table('catalogers')->where('person_id', $person->id)->count(),
        ];
        if ($has = array_keys(array_filter($other))) {
            return ['outcome' => 'skipped', 'why' => 'the person has ' . implode(', ', $has)];
        }

        $outcome = 'remove';
        if ($row['kind'] === 'rename') {
            $taken = Person::where('normalized_name', mb_substr(TextNormalizer::normalize($row['to']), 0, 600))->where('id', '!=', $person->id)->exists();
            $outcome = $taken ? 'name_as_text' : 'rename';
        }

        return ['outcome' => $outcome, 'person' => $person, 'works' => $works, 'fills' => $fills, 'copies' => $copies->keys()->all()];
    }

    /** The columns of the work the line goes back to; a column that already holds something is left alone. */
    private function fill(Work $work, array $w, array $row): array
    {
        $fill = [];
        foreach (['date' => 'composition_date_raw', 'description' => 'description', 'dedication' => 'dedication'] as $key => $column) {
            if (isset($w[$key]) && trim((string) $work->{$column}) === '') {
                $fill[$column] = $w[$key];
            }
        }
        if (isset($w['bibliography']) && ! $work->bibliography) {
            $fill['bibliography'] = $w['bibliography'];
        }
        // The author the entry does name, as text: the new name of the row, or the one printed in the description
        $name = $row['kind'] === 'rename' ? $row['to'] : ($w['author_name'] ?? null);
        if ($row['kind'] !== 'unlink' && $work->author_name !== $name) {
            $fill['author_name'] = $name;
        }

        return $fill;
    }

    private function write(array $row, array $plan, ?string $step, RecordRemover $remover): void
    {
        /** @var Person $person */
        $person = $plan['person'];
        $kept = $plan['outcome'] === 'rename';
        $meta = ['corpus_fix' => 'pseudo_person', 'step' => $step, 'kind' => $row['kind']];

        foreach ($plan['works'] as $work) {
            $fill = $plan['fills'][$work->slug];
            $old = ['author_id' => $work->author_id] + $work->only(array_keys($fill));
            if (! $kept) {
                $old['work_people'] = DB::table('work_people')->where('work_id', $work->id)->where('person_id', $person->id)
                    ->where('role', WorkPerson::AUTHOR)->get()->map(fn ($r) => (array) $r)->all();
                DB::table('work_people')->whereIn('id', array_column($old['work_people'], 'id'))->delete();
                Work::whereKey($work->id)->update(['author_id' => null]);
                $work->setRawAttributes(['author_id' => null] + $work->getAttributes(), true);
            }
            // through the model, so that the range columns of the date follow it
            $work->forceFill($fill)->save();
            Work::normalizeContributorPositions([$work->id]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Work::class, 'auditable_id' => $work->id, 'action' => 'corpus_update',
                'old_values' => $old, 'new_values' => $fill + ($kept ? [] : ['author_id' => null]) + $meta + ['person_name' => $row['name']]]);
            $this->workIds[] = $work->id;
            if (isset($fill['dedication'])) {
                $this->dedicated[] = $work->id;
            }
        }

        if ($plan['outcome'] === 'unlink') {
            $this->keptPeople[] = $person->id;

            return;
        }
        if ($kept) {
            $before = $person->only(['name', 'normalized_name', 'life_dates_raw', 'death_year_hijri', 'century_hijri']);
            $person->update(['name' => $row['to']] + (isset($row['life_dates_raw']) ? ['life_dates_raw' => $row['life_dates_raw']] : []));
            AuditLog::create(['user_id' => null, 'auditable_type' => Person::class, 'auditable_id' => $person->id, 'action' => 'corpus_update',
                'old_values' => $before, 'new_values' => $person->only(array_keys($before)) + $meta]);
            $this->keptPeople[] = $person->id;

            return;
        }

        // «کاتب = مؤلف» copies were tied to the row as their scribe; the copy keeps its own mark of the autograph
        foreach ($plan['copies'] as $copyId) {
            $rows = DB::table('manuscript_people')->where('manuscript_id', $copyId)->where('person_id', $person->id)
                ->where('role', ManuscriptPerson::SCRIBE)->get()->map(fn ($r) => (array) $r)->all();
            DB::table('manuscript_people')->whereIn('id', array_column($rows, 'id'))->delete();
            Manuscript::whereKey($copyId)->where('scribe_id', $person->id)->update(['scribe_id' => null]);
            Manuscript::normalizeContributorPositions([$copyId]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $copyId, 'action' => 'corpus_update',
                'old_values' => ['scribe_id' => $person->id, 'manuscript_people' => $rows], 'new_values' => ['scribe_id' => null] + $meta]);
            $this->copyIds[] = $copyId;
        }

        $id = $person->id;
        $work = $plan['works'][0];
        $result = $remover->remove($person->fresh());
        AuditLog::create(['user_id' => null, 'auditable_type' => Person::class, 'auditable_id' => $id, 'action' => 'remove',
            'old_values' => $result, 'new_values' => $meta + ['redirect_work_id' => $work->id]]);
        if ($row['kind'] === 'patron') {
            $this->patronOf[$id] = $work->id;
        }
    }

    /** After the rows: the roles of the new dedications, the patrons' addresses, the counts and the search documents. */
    private function finish(): void
    {
        if ($this->dedicated) {
            $this->call('fahares:backfill-work-dedications', ['--only' => implode(',', array_unique($this->dedicated))]);
        }
        // «به دستور: شاهرخ» leads to the one person its dedication was linked to
        foreach ($this->patronOf as $personId => $workId) {
            $patrons = DB::table('work_people')->where('work_id', $workId)->whereIn('role', ['dedicatee', 'commissioner'])
                ->whereNotNull('person_id')->pluck('person_id')->unique();
            if ($patrons->count() === 1) {
                EntityRedirect::recordRedirect('people', $personId, (int) $patrons->first());
                $this->counts['patron_redirect'] = ($this->counts['patron_redirect'] ?? 0) + 1;
            }
        }
        foreach (Person::whereIn('id', $this->keptPeople)->get() as $person) {
            $person->recountContributions();
            $person->searchable();
        }
        Work::whereIn('id', $this->workIds)->get()->searchable();
        Manuscript::whereIn('id', $this->copyIds)->get()->searchable();
        $this->info(sprintf('Done: %d works, %d copies, %d patrons\' addresses.', count(array_unique($this->workIds)), count($this->copyIds), $this->counts['patron_redirect'] ?? 0));
    }

    /** Text without page tags and with single spaces, for finding a line in a work's raw text. */
    private static function flat(?string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/<!--.*?-->/us', ' ', (string) $text)));
    }
}
