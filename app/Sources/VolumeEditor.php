<?php

namespace App\Sources;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\CatalogVolumePart;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * A change to a catalogue volume made in the admin panel (step 14.50): its library, its people, or its parts. Each
 * change is followed as catalogs:import-volume-parts follows its own: CatalogVolumePart::assign gives the volume's
 * copies their parts again, and the parts and the catalogers are counted again. run() reports what it did to the
 * copies; with $dry it does the same inside a transaction and takes it back, which is the preview.
 */
class VolumeEditor
{
    /**
     * @param  Closure(): void  $change  writes the change to the volume, its people or its parts
     * @return array{changed: int, moved: int, joined: int, left: int, cataloger: int, outside: int, by_page: int, none: int, parts: array<int, int>}
     */
    public function run(CatalogVolume $volume, Closure $change, bool $dry = false): array
    {
        $result = null;
        $work = function () use ($volume, $change, &$result) {
            $before = $this->state($volume->id);
            $people = $this->people($volume->id);
            $primary = CatalogVolumePart::primaryCatalogers([$volume->id])[$volume->id] ?? null;

            $change();

            // a volume without parts gives its copies its first cataloger, as the import did; one with parts, its parts'
            if (! DB::table('catalog_volume_parts')->where('catalog_volume_id', $volume->id)->exists()) {
                $now = CatalogVolumePart::primaryCatalogers([$volume->id])[$volume->id] ?? null;
                if ($now !== $primary) {
                    DB::table('manuscripts')->where('catalog_volume_id', $volume->id)->whereNull('catalog_volume_part_id')
                        ->where(fn ($q) => $q->whereNull('cataloger_id')->when($primary, fn ($q) => $q->orWhere('cataloger_id', $primary)))
                        ->update(['cataloger_id' => $now]);
                }
            }
            $stats = CatalogVolumePart::assign(array_keys($before));
            $parts = DB::table('catalog_volume_parts')->where('catalog_volume_id', $volume->id)->pluck('id')->all();
            CatalogVolumePart::recount($parts);
            DB::table('catalog_volumes')->where('id', $volume->id)->update(['manuscripts_count' => count($before)]);
            Cataloger::recount(array_unique(array_merge($people, $this->people($volume->id))));

            $result = $this->compare($before, $this->state($volume->id), $stats);
        };

        if (! $dry) {
            DB::transaction($work);

            return $result;
        }
        DB::beginTransaction();
        try {
            $work();
        } finally {
            DB::rollBack();
        }

        return $result;
    }

    /** @return array<int, array{0: ?int, 1: ?int}> copy id => [part, cataloger] */
    private function state(int $volumeId): array
    {
        return DB::table('manuscripts')->where('catalog_volume_id', $volumeId)->get(['id', 'catalog_volume_part_id', 'cataloger_id'])
            ->mapWithKeys(fn ($r) => [$r->id => [$r->catalog_volume_part_id ? (int) $r->catalog_volume_part_id : null, $r->cataloger_id ? (int) $r->cataloger_id : null]])
            ->all();
    }

    /** The catalogers of the volume and of its parts. */
    private function people(int $volumeId): array
    {
        return DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $volumeId)->pluck('cataloger_id')
            ->merge(DB::table('catalog_volume_part_cataloger')->whereIn('catalog_volume_part_id',
                DB::table('catalog_volume_parts')->where('catalog_volume_id', $volumeId)->select('id'))->pluck('cataloger_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function compare(array $before, array $after, array $stats): array
    {
        $result = ['changed' => 0, 'moved' => 0, 'joined' => 0, 'left' => 0, 'cataloger' => 0,
            'outside' => $stats['outside'], 'by_page' => $stats['by_page'], 'none' => $stats['none'], 'parts' => []];
        foreach ($after as $id => [$part, $cataloger]) {
            [$oldPart, $oldCataloger] = $before[$id] ?? [null, null];
            if ($part !== $oldPart || $cataloger !== $oldCataloger) {
                $result['changed']++;
            }
            if ($part !== $oldPart) {
                $result[$oldPart === null ? 'joined' : ($part === null ? 'left' : 'moved')]++;
            }
            if ($cataloger !== $oldCataloger) {
                $result['cataloger']++;
            }
            if ($part !== null) {
                $result['parts'][$part] = ($result['parts'][$part] ?? 0) + 1;
            }
        }

        return $result;
    }
}
