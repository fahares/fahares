<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\EntityRedirect;
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
 *  - Follow-ups: the volume «فهرست مدرسه رضویه قم» (MTIF 21378) moves from the Razavi library of Mashhad to the
 *    Madrese Razaviyye of Qom; the year of «آشنایی با چند نسخه خطی» becomes 1350 (Fankha vol. 24: «قم، ۱۳۵۰ ش»,
 *    where «1396» was a guess of the remediation command); the publisher of Shahchragh vol. 3 becomes «فلاح»;
 *    and «حسن‌زاده» becomes «صادق حسن‌زاده» (preface of the Masjid-e A'zam catalogue, ghbook 15891:
 *    «حجة الاسلام صادق حسن‌زاده مراغه‌ای»).
 *  - «پرویز اذکائی» is left with no volume and no copy by this, so his record is deleted and its old links
 *    redirect to the Gharb school library, the only place he was linked to (user's decision, 2026-10-21).
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

    public const HASANZADEH_SLUG = 'حسنزاده';

    public const HASANZADEH_OLD_NAME = 'حسن‌زاده';

    public const HASANZADEH_NAME = 'صادق حسن‌زاده';

    public const QOM_RAZAVIYYE_MTIF_VOLUME = 21378;

    public const QOM_RAZAVIYYE_LIBRARY_SLUG = 'km-mdrsh-rdoyh-140';

    public const ASHNAYI_CITATION = 'آشنایی با چند نسخه خطی';

    public const ASHNAYI_YEAR = '1350';

    public const ASHNAYI_GUESSED_YEAR = '1396';

    public const SHAHCHRAGH_PUBLISHER = 'فلاح';

    /**
     * library slug, volume number, MTIF ids to detach, MTIF id to attach, source
     *
     * @var array<int, array{0: string, 1: int, 2: array<int, int>, 3: int, 4: string}>
     */
    public const FIXES = [
        ['shyraz-shahchragh-97', 3, [self::BEHROUZI, self::FAQIR], self::BARAKAT, 'mtif.org/book/9217: جلد سوم به گردآوری محمد برکت، فلاح شیراز ۱۳۸۲؛ ensani.ir/fa/article/download/203309'],
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
        // Azkaei is deleted by the first run, so a rerun must not need him
        $missing = array_diff($ids, $catalogers->keys()->all(), [self::AZKAEI]);
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

            foreach ($catalogers->except(self::AZKAEI) as $cataloger) {
                $this->recount($cataloger);
            }

            $this->followUps();

            if ($catalogers->has(self::AZKAEI)) {
                $this->removeIfEmpty($catalogers[self::AZKAEI], $plan[3][0]->library_id);
            }
        });

        $this->info('Done.');

        return self::SUCCESS;
    }

    /** Library, year, publisher and name corrections that need no cataloger change. */
    protected function followUps(): void
    {
        $qom = \App\Models\Library::where('slug', self::QOM_RAZAVIYYE_LIBRARY_SLUG)->first();
        $razaviyye = CatalogVolume::where('mtif_entry_id', self::QOM_RAZAVIYYE_MTIF_VOLUME)->first();
        if ($qom && $razaviyye && $razaviyye->library_id !== $qom->id) {
            $razaviyye->update(['library_id' => $qom->id]);
            $this->line("Volume {$razaviyye->id} moved to library {$qom->slug}");
        }

        $ashnayi = CatalogVolume::where('citation_pattern', self::ASHNAYI_CITATION)
            ->where('publication_year', self::ASHNAYI_GUESSED_YEAR)->get();
        foreach ($ashnayi as $volume) {
            $volume->update([
                'publication_year' => self::ASHNAYI_YEAR,
                'metadata' => array_merge($volume->metadata ?? [], ['year_source' => 'فنخا ج ۲۴: «آشنایی با چند نسخه خطی، به کوشش حسین مدرسی طباطبایی، قم، ۱۳۵۰ ش»']),
            ]);
            $this->line("Volume {$volume->id} year set to " . self::ASHNAYI_YEAR);
        }

        $shahchragh = $this->volume('shyraz-shahchragh-97', 3);
        if ($shahchragh && $shahchragh->publisher !== self::SHAHCHRAGH_PUBLISHER) {
            $shahchragh->update(['publisher' => self::SHAHCHRAGH_PUBLISHER]);
        }

        $hasanzadeh = Cataloger::where('slug', self::HASANZADEH_SLUG)->where('name', self::HASANZADEH_OLD_NAME)->first();
        if ($hasanzadeh) {
            $hasanzadeh->update(['name' => self::HASANZADEH_NAME]);
            $this->line("Cataloger {$hasanzadeh->id} renamed to " . self::HASANZADEH_NAME);
        }
    }

    /** Deletes the cataloger once nothing points at him, leaving a redirect to the library. */
    protected function removeIfEmpty(Cataloger $cataloger, int $libraryId): void
    {
        if ($cataloger->catalogVolumes()->exists() || DB::table('manuscripts')->where('cataloger_id', $cataloger->id)->exists()) {
            return;
        }
        $cataloger->delete();
        EntityRedirect::recordRedirect('cataloger_library', $cataloger->id, $libraryId);
        $this->line("«{$cataloger->name}» ({$cataloger->id}) removed; old links redirect to library {$libraryId}");
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
