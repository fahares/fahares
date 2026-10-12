<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\Catalog;
use App\Models\EntityRedirect;
use App\Models\Language;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies a catalog addenda reconciliation file (fahares-corpus/reconciliations/*.json):
 * merges addendum entries into their main works, applies errata, and records every
 * addendum page as a source citation so nothing from the printed addenda is lost.
 * Safe to re-run: entries already applied are skipped.
 */
class ReconcileAddendaCommand extends Command
{
    protected $signature = 'fahares:reconcile-addenda
                            {--file= : Path to the reconciliation JSON (default: database/data/fankha_vol34_addenda.json)}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Merge catalog addenda (استدراکات) into their main works according to a reconciliation file';

    protected array $touchedPeople = [];
    protected array $touchedSubjects = [];
    protected array $touchedLanguages = [];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_vol34_addenda.json');
        if (! is_file($path)) {
            $this->error("Reconciliation file not found: {$path}");
            return self::FAILURE;
        }

        $map = json_decode(file_get_contents($path), true);
        $catalog = Catalog::where('code', $map['catalog'] ?? 'fankha')->first();
        if (! $catalog) {
            $this->error("Catalog [{$map['catalog']}] not found.");
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $stats = ['merged' => 0, 'moved' => 0, 'duplicates' => 0, 'errata' => 0, 'skipped' => 0];

        foreach ($map['merges'] ?? [] as $item) {
            $source = $this->resolve($item['source']);
            $target = $this->resolve($item['target']);
            if (! $source || ! $target) {
                $stats['skipped']++;
                continue;
            }

            [$moved, $dups] = $this->countMerge($source, $target);
            $this->line(sprintf('merge #%d → #%d «%s»: %d moved, %d duplicate%s',
                $source->id, $target->id, $target->primary_title, $moved, $dups, $item['distinct_work'] ? ' [distinct work]' : ''));

            if (! $dry) {
                DB::transaction(fn() => $this->merge($source, $target, $catalog, (bool) $item['distinct_work']));
            }
            $stats['merged']++;
            $stats['moved'] += $moved;
            $stats['duplicates'] += $dups;
        }

        foreach ($map['errata'] ?? [] as $item) {
            $source = $this->resolve($item['source']);
            $target = $this->resolve($item['target']);
            if (! $source || ! $target) {
                $stats['skipped']++;
                continue;
            }

            $this->line(sprintf('erratum #%d → #%d «%s»: %s = «%s»',
                $source->id, $target->id, $target->primary_title, $item['field'], $item['new_value']));

            if (! $dry) {
                DB::transaction(fn() => $this->applyErratum($source, $target, $catalog, $item));
            }
            $stats['errata']++;
        }

        if (! $dry) {
            $this->recount();
        }

        $this->info(($dry ? '[dry run] ' : '') . sprintf(
            'Merged %d entries (%d manuscripts moved, %d duplicates kept as citations), %d errata applied, %d already applied or missing.',
            $stats['merged'], $stats['moved'], $stats['duplicates'], $stats['errata'], $stats['skipped']
        ));

        return self::SUCCESS;
    }

    /**
     * Find the work named in the file, checking its title so a stale id never hits the wrong record.
     */
    protected function resolve(array $ref): ?Work
    {
        $work = Work::find($ref['work_id']);
        if (! $work) {
            return null;
        }

        if (TextNormalizer::normalize($work->primary_title) !== TextNormalizer::normalize($ref['title'])) {
            $this->warn("Work #{$ref['work_id']} is «{$work->primary_title}», expected «{$ref['title']}»; skipped.");
            return null;
        }

        return $work;
    }

    protected function manuscriptKey(Manuscript $m): string
    {
        return TextNormalizer::normalize($m->city) . '|' . TextNormalizer::normalize($m->library) . '|'
            . preg_replace('/\s+/u', '', TextNormalizer::normalize($m->shelfmark));
    }

    protected function countMerge(Work $source, Work $target): array
    {
        $targetKeys = $target->manuscripts()->get()->map(fn($m) => $this->manuscriptKey($m))->all();
        $dups = $source->manuscripts()->get()->filter(fn($m) => in_array($this->manuscriptKey($m), $targetKeys, true))->count();

        return [$source->manuscripts()->count() - $dups, $dups];
    }

    protected function merge(Work $source, Work $target, Catalog $catalog, bool $distinctWork): void
    {
        $targetByKey = $target->manuscripts()->get()->keyBy(fn($m) => $this->manuscriptKey($m));

        foreach ($source->manuscripts()->get() as $ms) {
            $existing = $targetByKey->get($this->manuscriptKey($ms));

            if ($existing) {
                // Same copy already listed under the main entry: keep it, cite the addendum description
                $this->cite($existing, $catalog, $ms->volume_number, $ms->page_start, $ms->page_end, 'addendum', $ms->raw_text);
                EntityRedirect::recordRedirect('manuscripts', $ms->id, $existing->id);
                $ms->delete();
                continue;
            }

            $metadata = (array) ($ms->metadata ?? []);
            if ($distinctWork) {
                $metadata['is_distinct_work'] = true;
            }
            $ms->update(['work_id' => $target->id, 'metadata' => $metadata]);
        }

        $this->cite($target, $catalog, $source->volume_number, $source->page_start, $source->page_end, 'addendum', $source->raw_text);
        $this->removeWork($source, $target);

        $target->update(['manuscripts_count' => $target->manuscripts()->count()]);
        $target->searchable();
    }

    protected function applyErratum(Work $source, Work $target, Catalog $catalog, array $item): void
    {
        if ($item['field'] === 'author') {
            $this->touchedPeople[] = $target->author_id;
            $target->update([
                'author_id' => $source->author_id,
                'author_name' => $source->author_name,
                'has_other_authors' => $source->has_other_authors,
            ]);
            DB::table('work_author')->where('work_id', $target->id)->delete();
            foreach (DB::table('work_author')->where('work_id', $source->id)->get() as $row) {
                $this->touchedPeople[] = $row->person_id;
                DB::table('work_author')->insert(['work_id' => $target->id, 'person_id' => $row->person_id, 'position' => $row->position, 'note' => $row->note]);
            }
            Manuscript::where('work_id', $target->id)->searchable();
        }

        $this->cite($target, $catalog, $source->volume_number, $source->page_start, $source->page_end, 'erratum', $source->raw_text);
        $this->removeWork($source, $target);
        $target->searchable();
    }

    protected function cite($record, Catalog $catalog, ?int $volume, ?int $from, ?int $to, string $role, ?string $rawText): void
    {
        $record->sourceCitations()->firstOrCreate(
            ['catalog_id' => $catalog->id, 'volume_number' => $volume, 'page_start' => $from, 'role' => $role],
            ['page_end' => $to, 'raw_text' => $rawText]
        );
    }

    protected function removeWork(Work $source, Work $target): void
    {
        array_push($this->touchedPeople, ...DB::table('work_author')->whereIn('work_id', [$source->id, $target->id])->pluck('person_id')->all());
        array_push($this->touchedSubjects, ...$source->subjects()->pluck('subjects.id')->all());
        array_push($this->touchedLanguages, ...$source->languages()->pluck('languages.id')->all());

        EntityRedirect::recordRedirect('works', $source->id, $target->id);
        $source->delete();
    }

    protected function recount(): void
    {
        foreach (Person::whereIn('id', array_filter(array_unique($this->touchedPeople)))->get() as $person) {
            $works = $person->authoredWorks()->count();
            $person->update(['works_count' => $works, 'is_author' => $person->is_author || $works > 0]);
            $person->searchable();
        }

        $subjects = Subject::whereIn('id', array_unique($this->touchedSubjects))->get();
        foreach ($subjects as $subject) {
            $subject->update(['works_count' => DB::table('subject_work')->where('subject_id', $subject->id)->count()]);
        }
        // Parent subjects carry the distinct count of their own and their children's works
        foreach (Subject::whereIn('id', $subjects->pluck('parent_id')->filter()->unique())->with('children')->get() as $parent) {
            $ids = array_merge([$parent->id], $parent->children->pluck('id')->all());
            $parent->update(['works_count' => DB::table('subject_work')->whereIn('subject_id', $ids)->distinct()->count('work_id')]);
        }

        foreach (Language::whereIn('id', array_unique($this->touchedLanguages))->get() as $language) {
            $language->update(['works_count' => DB::table('language_work')->where('language_id', $language->id)->count()]);
        }
    }
}
