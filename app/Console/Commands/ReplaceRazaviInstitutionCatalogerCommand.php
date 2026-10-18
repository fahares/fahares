<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\EntityRedirect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the cataloger «آستان قدس رضوی», which is the institution, not a cataloger (backlog item 43).
 * Fankha names no cataloger for volumes 2, 3, 4 and 6 of the old Razavi catalogue, so the remediation
 * data had put the publisher in that slot. The MTIF record of the series (mtif.org/book/147) credits
 * «اوکتایی» with volumes 1–5, so volumes 2–4 move to him; volume 6 stays without a cataloger, since MTIF's
 * «مهدی ولائی» for it does not fit an undated old volume. Old links redirect to the Razavi library.
 * Local and production ids differ, so the cataloger is matched by slug and the volumes by MTIF entry id.
 * Safe to run again.
 */
class ReplaceRazaviInstitutionCatalogerCommand extends Command
{
    protected $signature = 'catalogers:replace-razavi-institution {--dry-run : Report the changes without saving them}';

    protected $description = 'Replace the institution «آستان قدس رضوی» posing as a cataloger with the sourced cataloger or a volume note';

    public const INSTITUTION_SLUG = 'آستان-قدس-رضوی';

    public const OKTAYI_SLUG = 'اوکتایی';

    /** MTIF entry ids of volumes 2, 3 and 4 («اوکتایی») and of volume 6 (unknown). */
    public const OKTAYI_VOLUMES = [21409, 21410, 21411];

    public const UNKNOWN_VOLUMES = [21413];

    public const SOURCE_NOTE = 'فنخا فهرست‌نگار این جلد را نام نبرده است. رکورد MTIF مجموعه (mtif.org/book/147) جلدهای ۱ تا ۵ را «تألیف اوکتایی» آورده است.';

    public const UNKNOWN_NOTE = 'فهرست‌نگار نامعلوم است. فنخا نامی نیاورده و رکورد MTIF (mtif.org/book/147) «مهدی ولائی» نوشته است که با «بی‌تا» بودن این جلد کهن نمی‌خواند.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $volumes = CatalogVolume::whereIn('mtif_entry_id', [...self::OKTAYI_VOLUMES, ...self::UNKNOWN_VOLUMES])->get()->keyBy('mtif_entry_id');
        $missing = array_diff([...self::OKTAYI_VOLUMES, ...self::UNKNOWN_VOLUMES], $volumes->keys()->all());
        if ($missing) {
            $this->error('Volumes not found by MTIF id: ' . implode(', ', $missing));

            return self::FAILURE;
        }
        foreach ($volumes as $volume) {
            if (! str_contains($volume->title, 'آستان قدس رضوی')) {
                $this->error("Volume {$volume->id} is not a Razavi volume: {$volume->title}");

                return self::FAILURE;
            }
        }

        // Eloquent's only() filters by primary key, so pick by MTIF id explicitly
        $oktayiVolumes = $volumes->filter(fn ($v) => in_array($v->mtif_entry_id, self::OKTAYI_VOLUMES));
        $unknownVolumes = $volumes->filter(fn ($v) => in_array($v->mtif_entry_id, self::UNKNOWN_VOLUMES));
        $institution = Cataloger::where('slug', self::INSTITUTION_SLUG)->first();
        $libraryId = $volumes->first()->library_id;

        $this->line('Institution record: ' . ($institution ? "{$institution->id} ({$institution->manuscripts_count} manuscripts)" : 'already removed'));
        $this->line('«اوکتایی» volumes: ' . $oktayiVolumes->pluck('id')->implode(', ') . '; unknown: ' . $unknownVolumes->pluck('id')->implode(', '));
        if ($dry) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($institution, $oktayiVolumes, $unknownVolumes, $libraryId) {
            $oktayi = Cataloger::firstOrCreate(['slug' => self::OKTAYI_SLUG], [
                'name' => 'اوکتایی',
                'bio' => 'فهرست‌نگار جلدهای کهن «فهرست کتب کتابخانه مبارکه آستان قدس رضوی» (چاپخانه طوس، مشهد، از ۱۳۰۵ ش). نام کوچک او در منابع این فهرست نیامده است.',
                'metadata' => [
                    'institutions' => ['کتابخانه مرکزی آستان قدس رضوی'],
                    'major_catalogs' => ['فهرست کتب کتابخانه مبارکه آستان قدس رضوی (جلدهای ۲، ۳ و ۴)'],
                    'sources' => ['رکورد MTIF مجموعه: «[تألیف اوکتایی]»، مندرجات «ج ۱–۵: اوکتایی» (mtif.org/book/147)'],
                ],
            ]);

            $oktayi->catalogVolumes()->syncWithoutDetaching(
                $oktayiVolumes->mapWithKeys(fn ($v) => [$v->id => ['role' => 'cataloger']])->all()
            );
            foreach ($oktayiVolumes as $volume) {
                $volume->update(['metadata' => array_merge($volume->metadata ?? [], ['cataloger_note' => self::SOURCE_NOTE])]);
            }
            foreach ($unknownVolumes as $volume) {
                $volume->update(['metadata' => array_merge($volume->metadata ?? [], ['cataloger_note' => self::UNKNOWN_NOTE])]);
            }

            // Copies of these volumes point at their cataloger directly as well
            DB::table('manuscripts')->whereIn('catalog_volume_id', $oktayiVolumes->pluck('id'))
                ->where(fn ($q) => $q->whereNull('cataloger_id')->orWhere('cataloger_id', $institution?->id))
                ->update(['cataloger_id' => $oktayi->id]);

            if ($institution) {
                DB::table('manuscripts')->where('cataloger_id', $institution->id)->update(['cataloger_id' => null]);
                $institution->catalogVolumes()->detach();
                $institution->delete();
                EntityRedirect::recordRedirect('cataloger_library', $institution->id, $libraryId);
            }

            $this->recount($oktayi);
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
