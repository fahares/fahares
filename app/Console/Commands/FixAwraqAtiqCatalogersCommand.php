<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * «اوراق عتیق» is a collection of articles «به کوشش سید محمد حسین حکیم», each the catalogue of one library by its own
 * author, but `manuscripts:link-unlinked` (step 6, 2026-10-06) gave all 998 of its copies to «علی صدرائی خوئی».
 * Each copy now goes to the author of its article, by its library, as Fankha's table of catalogues names them
 * (fahares-corpus/catalogs/fankha_complete_catalogs_table.md, rows 73, 271, 330, 520):
 *
 *  - دفتر ۱، خادم حسینی تبریز (ص ۸۳–۱۲۱) and ملا علی خوئی / جواد شریف (ص ۱۲۲–۱۴۳): «نگارش صدرائی خویی»
 *  - دفتر ۱، ثامنی شیراز (ص ۲۶۵–۲۸۳): «نگارش محمد برکت»
 *  - دفتر ۱، مدرسه‌ی آیة‌الله خوئی مشهد (ص ۲۴۱–۲۶۶): «نگارش عبدالله غفرانی», who had no cataloger record
 *
 * The other copies (دفتر ۱: ادبیات اصفهان, دانشگاه بوعلی, whose citations are of دفتر ۳; all of دفتر ۲) have an
 * article whose author no source at hand names, so they are left without a cataloger rather than a wrong one.
 * Hakim compiled the volumes and wrote none of these articles, so he stays on both as their «supervisor», the only
 * cataloger linked to them: one linked to a volume is credited with all of its copies (the cataloger's page, its
 * counts), and the authors are credited through their copies alone.
 *
 * Volumes are found by citation pattern, libraries by slug and catalogers by MTIF entry id or slug, never by local
 * id, so the same command runs on production. Safe to run again.
 */
class FixAwraqAtiqCatalogersCommand extends Command
{
    protected $signature = 'catalogs:fix-awraq-atiq {--dry-run : Report the changes without saving them}';

    protected $description = 'Give each copy of «اوراق عتیق» the author of its article, and its compiler the supervisor role';

    public const HAKIM = 37255;

    public const SADRAEI = 37166;

    public const BARAKAT = 37244;

    public const GHOFRANI_SLUG = 'عبدالله-غفرانی';

    /** citation pattern of each volume => [library slug => author (MTIF id, or the slug of a record made here)] */
    public const ARTICLES = [
        'اوراق عتیق: 1' => [
            'tbryz-khadm-hsyny-85' => self::SADRAEI,
            'khoy-mla-aaly-khoyy-shryf-171' => self::SADRAEI,
            'shyraz-thamny-mhmd-rda-172' => self::BARAKAT,
            'mshhd-mdrsh-ayh-allh-khoyy-74' => self::GHOFRANI_SLUG,
        ],
        'اوراق عتیق: 2' => [],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $ids = [self::HAKIM, self::SADRAEI, self::BARAKAT];
        // toBase(): Eloquent's only() would filter by primary key, not by MTIF id
        $catalogers = Cataloger::whereIn('mtif_entry_id', $ids)->get()->keyBy('mtif_entry_id')->toBase();
        if ($missing = array_diff($ids, $catalogers->keys()->all())) {
            $this->error('Catalogers not found by MTIF id: ' . implode(', ', $missing));

            return self::FAILURE;
        }

        $volumes = [];
        foreach (self::ARTICLES as $pattern => $articles) {
            $volume = CatalogVolume::where('citation_pattern', $pattern)->first();
            $libraries = Library::whereIn('slug', array_keys($articles))->pluck('id', 'slug');
            if (! $volume || $libraries->count() !== count($articles)) {
                $this->error("Volume or library not found for «{$pattern}»: " . implode(', ', array_diff(array_keys($articles), $libraries->keys()->all())));

                return self::FAILURE;
            }
            $volumes[] = [$volume, $articles, $libraries];
            $this->line("{$volume->id} {$volume->title}: " . DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->count() . ' copies');
            foreach ($articles as $slug => $author) {
                $this->line("  {$slug}: " . DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->where('library_id', $libraries[$slug])->count()
                    . ' → ' . ($catalogers[$author]->name ?? 'عبدالله غفرانی'));
            }
            $this->line('  others: ' . DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->whereNotIn('library_id', $libraries->values())->count() . ' → no cataloger');
        }
        if ($dry) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($volumes, $catalogers) {
            $ghofrani = Cataloger::firstOrCreate(['slug' => self::GHOFRANI_SLUG], [
                'name' => 'عبدالله غفرانی',
                'bio' => 'نگارنده‌ی فهرست نسخه‌های خطی کتابخانه‌ی مدرسه‌ی آیة‌الله خوئی مشهد در «اوراق عتیق»، دفتر اول (به کوشش سید محمد حسین حکیم، کتابخانه، موزه و مرکز اسناد مجلس شورای اسلامی، تهران، ۱۳۸۹، ص ۲۴۱–۲۶۶).',
                // after Fankha's table of catalogues, row 520: «نگارش عبداهلل غفرانی»
                'metadata' => ['major_catalogs' => ['اوراق عتیق، دفتر اول: مدرسه‌ی آیة‌الله خوئی مشهد']],
            ]);
            $authors = $catalogers->put(self::GHOFRANI_SLUG, $ghofrani);
            $touched = collect([$catalogers[self::HAKIM]]);

            foreach ($volumes as [$volume, $articles, $libraries]) {
                $touched = $touched->merge($volume->catalogers)->merge(collect($articles)->map(fn ($a) => $authors[$a]));
                DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->update(['cataloger_id' => null]);
                foreach ($articles as $slug => $author) {
                    DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->where('library_id', $libraries[$slug])
                        ->update(['cataloger_id' => $authors[$author]->id]);
                }
                // only the compiler: a cataloger linked to a volume is credited with all of its copies, and each
                // author wrote one article of it
                $volume->catalogers()->sync([$catalogers[self::HAKIM]->id => ['role' => 'supervisor']]);
            }

            $touched->unique('id')->each(fn (Cataloger $c) => $this->recount($c));
        });

        $this->info('Done.');

        return self::SUCCESS;
    }

    /** Same rule as the remediation command: direct links plus the copies of the cataloger's volumes. */
    protected function recount(Cataloger $cataloger): void
    {
        $volumeIds = $cataloger->catalogVolumes()->pluck('catalog_volumes.id');
        $cataloger->update([
            'volumes_count' => $volumeIds->count(),
            'manuscripts_count' => DB::table('manuscripts')
                ->where(fn ($q) => $q->where('cataloger_id', $cataloger->id)->orWhereIn('catalog_volume_id', $volumeIds))
                ->count(),
        ]);
        $this->line("«{$cataloger->name}» ({$cataloger->id}): {$cataloger->volumes_count} volumes, {$cataloger->manuscripts_count} manuscripts");
    }
}
