<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrects the catalog volume ↔ cataloger attributions that the fact-check of item 44 flagged (backlog item 57).
 *
 *  - Shahchragh vol. 3 (1382): Behrouzi died in 1362 and cannot have compiled it; ensani.ir credits «محمد برکت»
 *    (ensani.ir/fa/article/download/203309). Fankha's second name «محمدصادق فقیری» is dropped with him, since MTIF
 *    knows «محمد صادق فقیر» only as the author of the National Library of Fars catalogue.
 *  - Vaziri Yazd vols. 4 and 5: Fankha (entries 250, 251) and MTIF (book 21389, 21390) both credit «محمد شیروانی»,
 *    as for vols. 1–3; «فقیری» had been put there by the remediation command without a source.
 *  - Gharb school, Hamedan: MTIF (book 21524) credits «جواد مقصود همدانی»; «پرویز اذکائی» wrote another catalogue
 *    (Rasht and Hamedan vol. 3, MTIF 21525). His copy links move to Maqsud and his volume link is removed.
 *  - «محمد صادق فقیر» (MTIF 37302) is the spelling of the name; he is linked to the two Fars National Library volumes
 *    (MTIF 21518, 21519) he authored.
 *
 * Volumes are found by library slug + volume number and catalogers by MTIF entry id, never by local id, so the
 * same command runs on production. Safe to run again.
 */
class FixCatalogVolumeAttributionsCommand extends Command
{
    protected $signature = 'catalogs:fix-volume-attributions {--dry-run : Report the changes without saving them}';

    protected $description = 'Correct the doubtful volume ↔ cataloger attributions flagged by the item-44 fact-check';

    public const BARAKAT = 37244;

    public const BEHROUZI = 37301;

    public const FAQIR = 37302;

    public const MAQSUD = 37304;

    public const AZKAEI = 37305;

    public const SHIRVANI = 37275;

    public const FAQIR_NAME = 'محمدصادق فقیر';

    /**
     * library slug, volume number, MTIF ids to detach, MTIF id to attach, source
     *
     * @var array<int, array{0: string, 1: int, 2: array<int, int>, 3: int, 4: string}>
     */
    public const FIXES = [
        ['shyraz-shahchragh-97', 3, [self::BEHROUZI, self::FAQIR], self::BARAKAT, 'ensani.ir/fa/article/download/203309: جلد سوم، ۱۳۸۲، با تلاش محمد برکت'],
        ['yzd-ozyry-32', 4, [self::FAQIR], self::SHIRVANI, 'فنخا ۲۵۰ و MTIF book/21389'],
        ['yzd-ozyry-32', 5, [self::FAQIR], self::SHIRVANI, 'فنخا ۲۵۱ و MTIF book/21390'],
        ['hmdan-mdrsh-ghrb-29', 1, [self::AZKAEI], self::MAQSUD, 'MTIF book/21524'],
    ];

    /** Fars National Library vols. 1 and 2, authored by Faqir according to MTIF 21518 and 21519. */
    public const FAQIR_VOLUMES = [['shyraz-mly-91', 1], ['shyraz-mly-91', 2]];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $ids = [self::BARAKAT, self::BEHROUZI, self::FAQIR, self::MAQSUD, self::AZKAEI, self::SHIRVANI];
        // toBase(): Eloquent's only() would filter by primary key, not by MTIF id
        $catalogers = Cataloger::whereIn('mtif_entry_id', $ids)->get()->keyBy('mtif_entry_id')->toBase();
        $missing = array_diff($ids, $catalogers->keys()->all());
        if ($missing) {
            $this->error('Catalogers not found by MTIF id: ' . implode(', ', $missing));

            return self::FAILURE;
        }

        $plan = [];
        foreach (self::FIXES as [$slug, $number, $detach, $attach, $source]) {
            $volume = $this->volume($slug, $number);
            if (! $volume) {
                $this->error("Volume not found: {$slug} #{$number}");

                return self::FAILURE;
            }
            $plan[] = [$volume, $detach, $attach, $source];
        }
        $faqirVolumes = [];
        foreach (self::FAQIR_VOLUMES as [$slug, $number]) {
            $volume = $this->volume($slug, $number);
            if (! $volume) {
                $this->error("Volume not found: {$slug} #{$number}");

                return self::FAILURE;
            }
            $faqirVolumes[] = $volume;
        }

        foreach ($plan as [$volume, $detach, $attach, $source]) {
            $this->line("{$volume->id} {$volume->title}: " . $catalogers->only($detach)->pluck('name')->implode('، ')
                . ' → ' . $catalogers[$attach]->name . " [{$source}]");
        }
        if ($dry) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $catalogers, $faqirVolumes) {
            foreach ($plan as [$volume, $detach, $attach, $source]) {
                $wrongIds = $catalogers->only($detach)->pluck('id');
                $new = $catalogers[$attach];
                $volume->catalogers()->detach($wrongIds->all());
                $volume->catalogers()->syncWithoutDetaching([$new->id => ['role' => 'cataloger']]);
                DB::table('manuscripts')->where('catalog_volume_id', $volume->id)
                    ->where(fn ($q) => $q->whereNull('cataloger_id')->orWhereIn('cataloger_id', $wrongIds))
                    ->update(['cataloger_id' => $new->id]);
                $volume->update(['metadata' => array_merge($volume->metadata ?? [], ['cataloger_source' => $source])]);
            }

            $faqir = $catalogers[self::FAQIR];
            $faqir->update(['name' => self::FAQIR_NAME]);
            foreach ($faqirVolumes as $volume) {
                $volume->catalogers()->syncWithoutDetaching([$faqir->id => ['role' => 'cataloger']]);
            }

            foreach ($catalogers as $cataloger) {
                $this->recount($cataloger);
            }
        });

        $this->info('Done.');

        return self::SUCCESS;
    }

    protected function volume(string $librarySlug, int $number): ?CatalogVolume
    {
        return CatalogVolume::where('volume_number', $number)
            ->whereHas('library', fn ($q) => $q->where('slug', $librarySlug))
            ->first();
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
