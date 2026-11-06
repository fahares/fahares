<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Source;
use App\Sources\SourceEntrySync as Sync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Two library catalogues had the wrong cataloger and printing (step 14.49), from early bulk assignments that Fankha's
 * table of catalogues does not support (fahares-corpus/catalogs/fankha_complete_catalogs_table.md):
 *
 *  - «فهرست نسخه‌های خطی کتابخانه آستان حضرت عبدالعظیم حسنی» (2 volumes): rows 282–283 name «نگارش ابوالفضل حافظیان
 *    بابلی», سازمان چاپ و نشر دار الحدیث, قم 1382; vol. 1 has 443 pages, vol. 2 pp. 449–678, which fits the pages its
 *    copies cite. `manuscripts:link-unlinked` (step 6) had given all its copies to «علی صدرائی خوئی».
 *  - the Navab catalogue: row 527 is «فهرست نسخه‌های خطی مدرسه علمیه عالی نواب» by محمود یزدی مطلق (فاضل), عروج
 *    اندیشه, مشهد 1388, 639 pages, the one single-volume Navab catalogue whose page range fits its copies' citations
 *    (pp. 9–546; the other, row 526, is pp. 439–562 of «دو کتابخانه مشهد»). The site had «تقی بینش», فرهنگ ایران زمین
 *    1344, who catalogued «چهار کتابخانه مشهد» and not Navab.
 *
 * Each volume's cataloger is replaced, and its copies move from the wrong cataloger to the right one. The new title of
 * the Navab catalogue also goes into its source's group key, so that sources:backfill finds the same source (and its
 * code, which names its cover) again. Sources are found by code and catalogers by MTIF id, never by local id, and the
 * command refuses unless each volume still has the cataloger it fixes. Safe to run again.
 */
class FixAbdolazimNavabCataloguesCommand extends Command
{
    protected $signature = 'catalogs:fix-abdolazim-navab {--dry-run : Report the changes without saving them}';

    protected $description = 'Give the Abdolazim and Navab catalogues their real cataloger and printing';

    public const HAFEZIAN = 56965;

    public const SADRAEI = 37166;

    public const FAZEL = 37263;

    public const BINESH = 37278;

    /** MTIF id => the attributes of a right cataloger the site has no record of yet, made by the run */
    public const NEW_CATALOGERS = [];

    /** source code => [wrong cataloger, right cataloger, source values, [volume number => volume values]] */
    public const FIXES = [
        'catalogue-b89f67efe34f' => [
            'from' => self::SADRAEI,
            'to' => self::HAFEZIAN,
            'source' => ['publisher' => 'سازمان چاپ و نشر دار الحدیث', 'publication_year' => '1382'],
            'volumes' => [
                1 => ['publisher' => 'سازمان چاپ و نشر دار الحدیث', 'publication_year' => '1382', 'pages_count' => 443],
                2 => ['publisher' => 'سازمان چاپ و نشر دار الحدیث', 'publication_year' => '1382', 'page_first' => 449, 'page_last' => 678],
            ],
        ],
        'catalogue-41322fd8f0f4' => [
            'from' => self::BINESH,
            'to' => self::FAZEL,
            'source' => ['title' => 'فهرست نسخه‌های خطی مدرسه علمیه عالی نواب', 'publisher' => 'عروج اندیشه', 'publication_year' => '1388'],
            'volumes' => [
                1 => ['title' => 'فهرست نسخه‌های خطی مدرسه علمیه عالی نواب', 'publisher' => 'عروج اندیشه', 'publication_year' => '1388', 'pages_count' => 639],
            ],
        ],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $ids = collect(static::FIXES)->flatMap(fn ($fix) => [$fix['from'], $fix['to']])->unique()->values()->all();
        // toBase(): Eloquent's only() would filter by primary key, not by MTIF id
        $catalogers = Cataloger::whereIn('mtif_entry_id', $ids)->get()->keyBy('mtif_entry_id')->toBase();
        foreach (static::NEW_CATALOGERS as $mtif => $attributes) {
            if (! $catalogers->has($mtif)) {
                $catalogers->put($mtif, new Cataloger($attributes + ['mtif_entry_id' => $mtif]));
                $this->line("«{$attributes['name']}» (MTIF {$mtif}) has no record: one is made");
            }
        }
        if ($missing = array_diff($ids, $catalogers->keys()->all())) {
            $this->error('Catalogers not found by MTIF id: ' . implode(', ', $missing));

            return self::FAILURE;
        }

        $plan = [];
        // static: FixJaliliCatalogueCommand runs the same fix on its own catalogue
        foreach (static::FIXES as $code => $fix) {
            $source = Source::where('code', $code)->first();
            $volumes = $source ? CatalogVolume::whereIn('id', $source->volumes()->pluck('id'))->orderBy('volume_number')->get()->keyBy(fn ($v) => (int) $v->volume_number) : collect();
            if (! $source || $volumes->keys()->all() !== array_keys($fix['volumes'])) {
                $this->error("Source {$code} or its volumes not found");

                return self::FAILURE;
            }
            $from = $catalogers[$fix['from']];
            $to = $catalogers[$fix['to']];
            foreach ($volumes as $volume) {
                $linked = $volume->catalogers()->pluck('catalogers.id')->all();
                if ($linked !== [$from->id] && $linked !== [$to->id]) {
                    $this->error("Volume {$volume->id} has catalogers [" . implode(', ', $linked) . "], not «{$from->name}» or «{$to->name}» alone");

                    return self::FAILURE;
                }
                $this->line("{$code} vol {$volume->volume_number} ({$volume->id}): «{$from->name}» → «{$to->name}», "
                    . DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->where('cataloger_id', $from->id)->count() . ' copies to move, '
                    . DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->count() . ' in all');
            }
            $this->line('  ' . json_encode($fix['source'], JSON_UNESCAPED_UNICODE));
            $plan[] = [$source, $volumes, $fix, $from, $to];
        }
        if ($dry) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        $touched = collect();
        DB::transaction(function () use ($plan, &$touched) {
            foreach ($plan as [$source, $volumes, $fix, $from, $to]) {
                $to->exists || $to->save();
                foreach ($fix['volumes'] as $number => $values) {
                    $volume = $volumes[$number];
                    // a source volume shares its id with its catalog volume
                    DB::table('catalog_volumes')->where('id', $volume->id)->update($values + ['updated_at' => now()]);
                    DB::table('source_volumes')->where('id', $volume->id)->update($values + ['updated_at' => now()]);
                    $volume->catalogers()->sync([$to->id => ['role' => 'cataloger']]);
                    DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->where('cataloger_id', $from->id)->update(['cataloger_id' => $to->id]);
                }
                $values = $fix['source'];
                if (isset($values['title'])) {
                    $metadata = $source->metadata ?? [];
                    $metadata['group'] = Sync::groupKey((object) ['edition_of_id' => null, 'library_id' => $volumes->first()->library_id, 'title' => $values['title']]);
                    $values['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE);
                }
                DB::table('sources')->where('id', $source->id)->update($values + ['updated_at' => now()]);
                $touched->push($from, $to);
            }
            Cataloger::recount($touched->pluck('id'));
        });

        foreach (Cataloger::whereIn('id', $touched->pluck('id'))->get() as $c) {
            $this->line("«{$c->name}» ({$c->id}): {$c->volumes_count} volumes, {$c->manuscripts_count} copies, {$c->supervised_count} supervised");
        }
        $this->info('Done.');

        return self::SUCCESS;
    }
}
