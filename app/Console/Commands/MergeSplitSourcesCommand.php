<?php

namespace App\Console\Commands;

use App\Models\Source;
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
 * Safe to run again: a folded group is one source and is not found again.
 */
class MergeSplitSourcesCommand extends Command
{
    protected $signature = 'sources:merge-split
        {--group=* : Codes of sources to fold that the titles do not tell, comma-separated, the first kept}
        {--dry-run : Report the groups and what would change without saving}';

    protected $description = 'Fold a catalogue the sources registry holds as several sources into one source';

    private const UNITS = ['اول' => 1, 'یکم' => 1, 'دوم' => 2, 'سوم' => 3, 'چهارم' => 4, 'پنجم' => 5, 'ششم' => 6, 'هفتم' => 7,
        'هشتم' => 8, 'نهم' => 9, 'دهم' => 10, 'یازدهم' => 11, 'دوازدهم' => 12, 'سیزدهم' => 13, 'چهاردهم' => 14, 'پانزدهم' => 15,
        'شانزدهم' => 16, 'هفدهم' => 17, 'هجدهم' => 18, 'هیجدهم' => 18, 'نوزدهم' => 19, 'بیستم' => 20, 'سی ام' => 30, 'سیم' => 30,
        'چهلم' => 40, 'پنجاهم' => 50];

    private const TENS = ['بیست' => 20, 'سی' => 30, 'چهل' => 40, 'پنجاه' => 50];

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
        $groups = $sources->groupBy(fn (Source $s) => self::catalogueKey($s))->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->sortBy([
                fn ($a, $b) => (int) (bool) $b->coverUrl() <=> (int) (bool) $a->coverUrl(),
                fn ($a, $b) => $b->volumes->count() <=> $a->volumes->count(),
                fn ($a, $b) => $this->lowestVolume($a) <=> $this->lowestVolume($b),
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
        $kept = $group->first();
        $folded = $group->slice(1)->values();
        $title = self::baseTitle($kept->title);
        $this->info("«{$title}» (library {$kept->library_id}): keep #{$kept->id} {$kept->code}, fold "
            . $folded->map(fn ($s) => "#{$s->id} {$s->code}")->implode(', '));

        // every volume with the number its title gives, the kept source's first
        $volumes = $group->flatMap(fn (Source $s) => $s->volumes->map(fn ($v) => (object) [
            'volume' => $v, 'source' => $s, 'old' => $v->volume_number,
            // a part is numbered as its source cites it («جلد سوم - بخش دوم (مجلد چهارم)» is 4)
            'new' => self::isPart($v->title) ? $v->volume_number : self::volumeNumber($v->title) ?? self::volumeNumber($s->title) ?? $v->volume_number,
            'entries' => DB::table('source_entries')->where('source_volume_id', $v->id)->count(),
        ]));
        $stays = [];
        $drops = [];
        foreach ($volumes->sortBy([fn ($a, $b) => (int) ($b->source->id === $kept->id) <=> (int) ($a->source->id === $kept->id),
            fn ($a, $b) => $b->entries <=> $a->entries, fn ($a, $b) => $a->volume->id <=> $b->volume->id]) as $v) {
            // a part shares its volume's number with the other parts («جلد ۳/۲۴», «جلد نهم - بخش دوم») and is never one of them
            $key = $v->new === null || self::isPart($v->volume->title) ? 'v' . $v->volume->id : $v->new;
            $same = $stays[$key] ?? null;
            if ($same && $same->source->id !== $v->source->id
                && in_array(self::subtitle($v->volume->title), ['', self::subtitle($same->volume->title)], true)) {
                $drops[] = [$v, $same];
            } else {
                $stays[$same ? 'v' . $v->volume->id : $key] = $v;
            }
        }
        uasort($stays, fn ($a, $b) => [$a->new ?? PHP_INT_MAX, $a->volume->id] <=> [$b->new ?? PHP_INT_MAX, $b->volume->id]);
        foreach ($stays as $v) {
            $this->line(sprintf('  volume %s #%d «%s»%s%s, %d entries', $v->new ?? '-', $v->volume->id, $v->volume->title,
                $v->source->id === $kept->id ? '' : " from #{$v->source->id}", $v->old !== $v->new ? " (was {$v->old})" : '', $v->entries));
        }
        foreach ($drops as [$d, $k]) {
            $this->line(sprintf('  volume %s #%d «%s» of #%d is #%d: %d entries move to it', $d->new, $d->volume->id, $d->volume->title,
                $d->source->id, $k->volume->id, $d->entries));
        }
        if ($this->dry) {
            return;
        }

        foreach ($drops as [$d, $k]) {
            self::foldVolume($d->volume->id, $k->volume->id);
        }
        $this->renumber($kept, $group, $volumes, $drops, $stays);

        $metadata = $kept->metadata ?? [];
        $metadata['merged_codes'] = array_values(array_unique(array_merge($metadata['merged_codes'] ?? [],
            ...$folded->map(fn ($s) => [$s->code, ...($s->metadata['merged_codes'] ?? [])])->all())));
        $fill = [];
        foreach (['short_name', 'compiler', 'publisher', 'publication_year', 'description', 'citation_format', 'extractor'] as $column) {
            if (blank($kept->{$column}) && filled($value = $folded->pluck($column)->filter()->first())) {
                $fill[$column] = $value;
            }
        }
        DB::table('sources')->whereIn('edition_of_id', $folded->pluck('id'))->update(['edition_of_id' => $kept->id]);
        DB::table('sources')->whereIn('id', $folded->pluck('id'))->delete();
        DB::table('sources')->where('id', $kept->id)->update($fill + [
            'title' => $title,
            'volumes_count' => DB::table('source_volumes')->where('source_id', $kept->id)->count(),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
        Cache::forget("sources:copies:{$kept->id}");
        foreach (['works', 'manuscripts'] as $type) {
            Cache::forget('sources:linked:' . md5(implode('|', [$kept->id, $type, null, ''])));
        }
    }

    /** Moves everything that hangs on a duplicate volume to the one kept, then deletes it with its catalog volume. */
    public static function foldVolume(int $drop, int $keep): void
    {
        DB::table('source_entries')->where('source_volume_id', $drop)->update(['source_volume_id' => $keep]);
        // a printed entry or a page the kept volume has too is the same one
        foreach (DB::table('source_items')->where('source_volume_id', $drop)->get(['id', 'item_key']) as $item) {
            $same = DB::table('source_items')->where('source_volume_id', $keep)->where('item_key', $item->item_key)->value('id');
            if ($same) {
                DB::table('source_entries')->where('source_item_id', $item->id)->update(['source_item_id' => $same]);
                DB::table('source_item_refs')->where('target_item_id', $item->id)->update(['target_item_id' => $same]);
                DB::table('source_items')->where('parent_id', $item->id)->update(['parent_id' => $same]);
                DB::table('source_items')->where('id', $item->id)->delete();
            } else {
                DB::table('source_items')->where('id', $item->id)->update(['source_volume_id' => $keep]);
            }
        }
        DB::table('source_pages')->where('source_volume_id', $drop)
            ->whereNotIn('page', DB::table('source_pages')->where('source_volume_id', $keep)->select('page'))
            ->update(['source_volume_id' => $keep]);
        foreach (['source_volumes', 'catalog_volumes'] as $table) {
            DB::table($table)->where('edition_of_id', $drop)->update(['edition_of_id' => $keep]);
        }
        DB::table('manuscripts')->where('catalog_volume_id', $drop)->update(['catalog_volume_id' => $keep]);
        DB::table('manuscript_catalog_references')->where('catalog_volume_id', $drop)->update(['catalog_volume_id' => $keep]);
        $has = DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $keep)->pluck('cataloger_id')->all();
        DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $drop)->whereNotIn('cataloger_id', $has)
            ->update(['catalog_volume_id' => $keep]);
        DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $drop)->delete();

        // what the kept volume lacks, the duplicate may have
        foreach (['source_volumes' => ['publisher', 'publication_year', 'pages_count', 'page_first', 'page_last', 'manuscripts_range',
            'mtif_entry_id', 'facsimile_code', 'cover_image_path', 'source_url'], 'catalog_volumes' => ['publisher', 'publication_year',
            'pages_count', 'manuscripts_range', 'mtif_entry_id', 'cover_image_path']] as $table => $columns) {
            $from = DB::table($table)->where('id', $drop)->first();
            $to = DB::table($table)->where('id', $keep)->first();
            if ($from && $to && ($fill = collect($columns)->filter(fn ($c) => blank($to->{$c}) && filled($from->{$c}))
                ->mapWithKeys(fn ($c) => [$c => $from->{$c}])->all())) {
                DB::table($table)->where('id', $keep)->update($fill);
            }
        }
        DB::table('source_volumes')->where('id', $drop)->delete();
        DB::table('catalog_volumes')->where('id', $drop)->delete();
    }

    /**
     * Moves the volumes and everything that cites the folded sources to the kept one, each place with its volume's new
     * number.
     */
    private function renumber(Source $kept, Collection $group, Collection $volumes, array $drops, array $stays): void
    {
        // [source id][old number] => new number, for the places that name a volume by its number only
        $numbers = [];
        foreach ($volumes as $v) {
            if ($v->old !== null && $v->new !== null) {
                $numbers[$v->source->id][$v->old] = $v->new;
            }
        }
        foreach ($stays as $v) {
            $id = $v->volume->id;
            if ($v->old !== $v->new && $v->old !== null) {
                foreach (['volume_number', 'text_volume_number'] as $column) {
                    DB::table('source_entries')->where('source_volume_id', $id)->where('source_id', $v->source->id)
                        ->where($column, $v->old)->update([$column => $v->new]);
                }
            }
            DB::table('source_volumes')->where('id', $id)->update(['source_id' => $kept->id, 'volume_number' => $v->new]);
            DB::table('catalog_volumes')->where('id', $id)->update(['volume_number' => $v->new]);
            DB::table('source_items')->where('source_volume_id', $id)->update(['source_id' => $kept->id, 'volume_number' => $v->new]);
        }
        // a duplicate volume's entries now hang on the kept volume, under its number
        foreach ($drops as [$d, $k]) {
            if ($d->old !== null && $d->old !== $k->new) {
                foreach (['volume_number', 'text_volume_number'] as $column) {
                    DB::table('source_entries')->where('source_volume_id', $k->volume->id)->where('source_id', $d->source->id)
                        ->where($column, $d->old)->update([$column => $k->new]);
                }
            }
        }
        foreach ($group as $source) {
            $map = array_filter($numbers[$source->id] ?? [], fn ($new, $old) => $new !== $old, ARRAY_FILTER_USE_BOTH);
            foreach (['source_entries' => ['volume_number', 'text_volume_number'], 'field_evidence' => ['volume_number']] as $table => $columns) {
                foreach ($columns as $column) {
                    if ($map) {
                        // in one statement, so that 1 → 2 and 2 → 3 do not run into each other
                        $case = 'case ' . $column . collect($map)->map(fn ($new, $old) => " when {$old} then {$new}")->implode('') . ' end';
                        DB::table($table)->where('source_id', $source->id)->whereIn($column, array_keys($map))
                            ->when($table === 'source_entries', fn ($q) => $q->whereNull('source_volume_id'))
                            ->update([$column => DB::raw($case)]);
                    }
                }
                if ($source->id !== $kept->id) {
                    DB::table($table)->where('source_id', $source->id)->update(['source_id' => $kept->id]);
                }
            }
            if ($source->id === $kept->id) {
                continue;
            }
            DB::table('source_entries')->where('locations', 'like', '%"source_id":' . $source->id . ',%')->orderBy('id')
                ->each(function ($row) use ($source, $kept, $map) {
                    $locations = array_map(fn ($l) => ($l['source_id'] ?? null) === $source->id
                        ? ['source_id' => $kept->id, 'volume' => $map[$l['volume'] ?? null] ?? $l['volume'] ?? null] + $l : $l,
                        json_decode($row->locations, true) ?: []);
                    DB::table('source_entries')->where('id', $row->id)->update(['locations' => json_encode($locations, JSON_UNESCAPED_UNICODE)]);
                });
        }
    }

    /** One key for the sources that are one catalogue: kind, library, printing and the title without its volume. */
    public static function catalogueKey(Source $source): string
    {
        return implode('|', [$source->kind, $source->library_id, $source->edition_of_id, $source->edition_label,
            self::normalize(self::baseTitle($source->title))]);
    }

    /** The title without its volume: «… گلپایگانی - جلد اول: آ - ج» and «… مرعشی نجفی - 13» → «… گلپایگانی», «… مرعشی نجفی». */
    public static function baseTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));

        return trim(preg_replace(['/\s*-\s*(?:عکسی\s*)?جلد(?:\s.*)?$/u', '/\s*-\s*[0-9۰-۹]+$/u'], '', $title));
    }

    /** The two spellings of one title as one: «حضرت آیة الله» and «آیت‌الله», ي and ی, ك and ک, the non-joiner and a space. */
    public static function normalize(string $title): string
    {
        $title = strtr($title, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', "\u{200C}" => ' ']);
        $title = preg_replace(['/حضرت/u', '/آ[یي][ةت]\s*ال?له/u', '/\s+/u'], ['', 'آیت الله', ' '], $title);

        return trim($title);
    }

    /** The volume a title names: «- جلد هشتم: ن - ی» → 8, «- جلد سی و یکم» → 31, «- جلد 13», «- 13» → 13; else null. */
    public static function volumeNumber(string $title): ?int
    {
        if (preg_match('/-\s*(?:عکسی\s*)?جلد\s*([^:\-]+)/u', $title, $m) || preg_match('/-\s*([0-9۰-۹]+)\s*$/u', $title, $m)) {
            $words = trim(preg_replace('/\s+/u', ' ', strtr($m[1], ["\u{200C}" => ' '])));
            if (preg_match('/^[0-9۰-۹]+$/u', $words)) {
                return (int) strtr($words, array_combine(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'], range(0, 9)));
            }
            // «بیست و هشتم»: the tens, then the ordinal
            $parts = explode(' و ', $words);
            $last = array_pop($parts);
            if (! isset(self::UNITS[$last])) {
                return null;
            }
            $number = self::UNITS[$last];
            foreach ($parts as $part) {
                if (! isset(self::TENS[$part])) {
                    return null;
                }
                $number += self::TENS[$part];
            }

            return $number;
        }

        return null;
    }

    /** Whether the title names a part of a volume: «- جلد نهم - بخش دوم», «- جلد ۳/۲۴: اهدایی طباطبائی», «(مجلد پنجم)». */
    public static function isPart(string $title): bool
    {
        return (bool) preg_match('/-\s*(?:عکسی\s*)?جلد[^:]*(?:\/|بخش|مجلد)/u', $title);
    }

    /** What the title says of the volume after its number: «- جلد اول: آ - ج» → «آ - ج», evened out; '' for none. */
    private static function subtitle(string $title): string
    {
        return preg_match('/-\s*(?:عکسی\s*)?جلد[^:]*:(.+)$/u', $title, $m) ? self::normalize($m[1]) : '';
    }

    private function lowestVolume(Source $source): int
    {
        return $source->volumes->map(fn ($v) => self::volumeNumber($v->title) ?? $v->volume_number ?? PHP_INT_MAX)->min() ?? PHP_INT_MAX;
    }
}
