<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Sources\SourceMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Folds a printed catalogue the sources registry holds as several sources into one (step 13.110). sources:backfill made
 * one source per library and title of catalog_volumes, so a catalogue whose volumes carry their letters in the title
 * («فهرست نسخ خطی کتابخانه عمومی آیت‌الله گلپایگانی - جلد اول: آ - ج») became a source per volume, and one Fankha
 * cites under two spellings (Marashi's, «آیت‌الله العظمی» and «حضرت آیة الله العظمی») two sources with overlapping
 * volumes.
 *
 * A group is the sources of one kind, library and printing whose titles are one once the volume («- جلد …», «- 5») is
 * dropped and «حضرت», «آیة الله», ي/ی, ك/ک and the zero-width non-joiner are evened out; --group adds one the titles
 * cannot tell (its first code is kept). The source kept is the one with a cover, then the most volumes, then the
 * lowest volume. Every volume takes the number its title gives («جلد هشتم» → 8) and moves to the kept source with its
 * id, which is its catalog volume's too, so its catalogers stay. Where two volumes have one number, the kept source's
 * (else the one with most entries) stays and the other's entries, printed entries, pages, copies and catalogers move
 * to it before it is deleted with its catalog volume. The kept source takes the titles' common form and the folded
 * sources' codes (metadata.merged_codes), which /sources/{code} redirects and sources:backfill keeps folded.
 * The folding itself is SourceMerger's, which the admin panel's merge of two sources shares (step 14.50).
 * Safe to run again: a folded group is one source and is not found again.
 */
class MergeSplitSourcesCommand extends Command
{
    protected $signature = 'sources:merge-split
        {--group=* : Codes of sources to fold that the titles do not tell, comma-separated, the first kept}
        {--only : Fold the --group groups only, not the ones the titles tell}
        {--dry-run : Report the groups and what would change without saving}';

    protected $description = 'Fold a catalogue the sources registry holds as several sources into one source';

    private bool $dry = false;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        if ($this->dry) {
            $this->warn('Dry run: nothing is saved.');
        }
        $groups = $this->groups();
        if (! $groups) {
            $this->info('No catalogue is held as several sources.');

            return self::SUCCESS;
        }
        foreach ($groups as $group) {
            $this->dry ? $this->fold($group) : DB::transaction(fn () => $this->fold($group));
        }
        if (! $this->dry) {
            Cache::forget('sources:shelf');
        }

        return self::SUCCESS;
    }

    /**
     * The sources to fold, each group kept source first.
     *
     * @return array<int, Collection<int, Source>>
     */
    private function groups(): array
    {
        $sources = Source::with('volumes')->orderBy('id')->get();
        $groups = $this->option('only') ? [] : $sources->groupBy(fn (Source $s) => self::catalogueKey($s))->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->sortBy([
                fn ($a, $b) => (int) (bool) $b->coverUrl() <=> (int) (bool) $a->coverUrl(),
                fn ($a, $b) => $b->volumes->count() <=> $a->volumes->count(),
                fn ($a, $b) => SourceMerger::lowestVolume($a) <=> SourceMerger::lowestVolume($b),
                fn ($a, $b) => $a->id <=> $b->id,
            ])->values())->values()->all();

        foreach ($this->option('group') as $codes) {
            $codes = array_values(array_filter(array_map('trim', explode(',', $codes))));
            // a code folded by an earlier run stands for the source it was folded into
            $group = collect($codes)->map(fn ($c) => $sources->firstWhere('code', $c) ?? Source::mergedInto($c))->filter()->unique('id')->values();
            if (count($codes) < 2) {
                $this->warn('--group needs two codes or more: ' . implode(',', $codes));
            } elseif ($group->count() > 1) {
                $groups[] = $sources->whereIn('id', $group->pluck('id'))->sortBy(fn ($s) => $group->search(fn ($g) => $g->id === $s->id))->values();
            }
        }

        return $groups;
    }

    /** @param  Collection<int, Source>  $group */
    private function fold(Collection $group): void
    {
        $merger = app(SourceMerger::class);
        $plan = $merger->plan($group);
        $kept = $plan['kept'];
        $this->info("«{$plan['title']}» (library {$kept->library_id}): keep #{$kept->id} {$kept->code}, fold "
            . $plan['folded']->map(fn ($s) => "#{$s->id} {$s->code}")->implode(', '));
        foreach ($plan['stays'] as $v) {
            $this->line(sprintf('  volume %s #%d «%s»%s%s, %d entries', $v->new ?? '-', $v->volume->id, $v->volume->title,
                $v->source->id === $kept->id ? '' : " from #{$v->source->id}", $v->old !== $v->new ? " (was {$v->old})" : '', $v->entries));
        }
        foreach ($plan['drops'] as [$d, $k]) {
            $this->line(sprintf('  volume %s #%d «%s» of #%d is #%d: %d entries move to it', $d->new, $d->volume->id, $d->volume->title,
                $d->source->id, $k->volume->id, $d->entries));
        }
        if (! $this->dry) {
            $merger->apply($plan);
        }
    }

    /** Moves everything that hangs on a duplicate volume to the one kept (SourceMerger::foldVolume). */
    public static function foldVolume(int $drop, int $keep): void
    {
        SourceMerger::foldVolume($drop, $keep);
    }

    public static function catalogueKey(Source $source): string
    {
        return SourceMerger::catalogueKey($source);
    }

    public static function baseTitle(string $title): string
    {
        return SourceMerger::baseTitle($title);
    }

    public static function normalize(string $title): string
    {
        return SourceMerger::normalize($title);
    }

    public static function volumeNumber(string $title): ?int
    {
        return SourceMerger::volumeNumber($title);
    }

    public static function isPart(string $title): bool
    {
        return SourceMerger::isPart($title);
    }
}
