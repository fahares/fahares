<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A part of a catalogue volume (step 13.150, docs/plans/catalog-volume-parts.md): the catalogue of one library in a
 * volume that holds several («نشریه» 5's «کتابخانه عبدالمجید مولوی», pp. 8–107, by Mahdi Valaei). A copy of such a
 * volume is of one part and reaches its catalogers through it; the volume's own people are its editors.
 */
class CatalogVolumePart extends Model
{
    protected $guarded = ['id'];

    private static ?bool $ready = null;

    /**
     * Whether the tables exist: a deploy brings the code up about a minute before it migrates (docs/SOURCE_PROTOCOL.md
     * section 9), and pages and saves must not fail meanwhile.
     */
    public static function ready(): bool
    {
        return self::$ready ??= \Illuminate\Support\Facades\Schema::hasTable('catalog_volume_parts')
            && \Illuminate\Support\Facades\Schema::hasColumn('manuscripts', 'catalog_volume_part_id');
    }

    protected function casts(): array
    {
        return [
            'page_first' => 'integer',
            'page_last' => 'integer',
            'sort' => 'integer',
            'manuscripts_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(CatalogVolume::class, 'catalog_volume_id');
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function catalogers(): BelongsToMany
    {
        return $this->belongsToMany(Cataloger::class, 'catalog_volume_part_cataloger')
            ->withPivot('role')
            ->withTimestamps()
            ->orderBy('catalog_volume_part_cataloger.id');
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    /** «ص ۸–۱۰۷», «ص ۸», or null. */
    public function getPagesTextAttribute(): ?string
    {
        if (! $this->page_first) {
            return null;
        }

        return 'ص ' . $this->page_first . ($this->page_last && $this->page_last !== $this->page_first ? '–' . $this->page_last : '');
    }

    public function holds(?int $page): bool
    {
        return $page !== null && $this->page_first !== null && $page >= $this->page_first && $page <= ($this->page_last ?? $this->page_first);
    }

    /**
     * The part a copy is of, among its volume's parts, and how it was found:
     *  1. the one part of the copy's library;
     *  2. of several of its library, the one whose pages hold the page its citation gives;
     *  else none. A copy whose library has no part but whose page falls in one part only is reported (by_page) and
     *  left without one: a copy of one library is not of another's catalogue, and the record of its library may be
     *  a duplicate to merge (step 13.150).
     *
     * @param  Collection<int, self>  $parts  the volume's parts
     * @return array{0: ?self, 1: string} the part and «library», «page», «by_page» or «none»
     */
    public static function choose(Collection $parts, ?int $libraryId, ?int $page): array
    {
        $own = $parts->where('library_id', $libraryId)->values();
        if ($libraryId && $own->count() === 1) {
            return [$own->first(), 'library'];
        }
        if ($libraryId && $own->count() > 1) {
            $held = $own->filter(fn ($p) => $p->holds($page))->values();

            return $held->count() === 1 ? [$held->first(), 'page'] : [null, 'none'];
        }
        $held = $parts->filter(fn ($p) => $p->holds($page))->values();

        return [null, $held->count() === 1 ? 'by_page' : 'none'];
    }

    /**
     * Gives each copy the part of its volume it is of, and the part's first cataloger as its own. A copy that leaves
     * its parts (a volume without them, or none) gets its volume's primary cataloger back, as the import gave it. Run
     * after every change of catalog_volume_id (SourceEntrySync::syncCited calls it). Returns the counts: of the copies
     * read, those on a volume with parts by how their part was found, «outside» (on their library's one part though
     * the page their citation gives is not in its pages), and «changed».
     *
     * @param  iterable<int>  $ids
     * @return array<string, int>
     */
    public static function assign(iterable $ids): array
    {
        $stats = ['library' => 0, 'page' => 0, 'by_page' => 0, 'none' => 0, 'outside' => 0, 'changed' => 0];
        if (! self::ready()) {
            return $stats;
        }
        $touched = [];
        foreach (collect($ids)->unique()->chunk(1000) as $chunk) {
            $rows = DB::table('manuscripts')->whereIn('id', $chunk->all())
                ->get(['id', 'library_id', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']);
            $volumeIds = $rows->pluck('catalog_volume_id')->filter()->unique()->values();
            $parts = $volumeIds->isEmpty() ? collect() : self::with('catalogers')->whereIn('catalog_volume_id', $volumeIds)->orderBy('sort')->orderBy('id')->get()->groupBy('catalog_volume_id');
            $leaving = $rows->filter(fn ($r) => $r->catalog_volume_part_id && ! isset($parts[$r->catalog_volume_id]));
            if ($rows->every(fn ($r) => ! $r->catalog_volume_part_id && ! isset($parts[$r->catalog_volume_id]))) {
                continue;
            }
            $pages = DB::table('source_entries')->where('legacy_type', 'fankha_citation')->whereIn('legacy_id', $rows->pluck('id'))->pluck('page_start', 'legacy_id');
            $primary = self::primaryCatalogers($leaving->pluck('catalog_volume_id')->filter()->unique()->all());

            foreach ($rows as $r) {
                [$part, $how, $cataloger] = [null, null, $r->cataloger_id];
                if (isset($parts[$r->catalog_volume_id])) {
                    $page = isset($pages[$r->id]) ? (int) $pages[$r->id] : null;
                    [$part, $how] = self::choose($parts[$r->catalog_volume_id], $r->library_id ? (int) $r->library_id : null, $page);
                    $stats[$how]++;
                    if ($how === 'library' && $page !== null && $part->page_first !== null && ! $part->holds($page)) {
                        $stats['outside']++;
                    }
                    $cataloger = $part?->catalogers->firstWhere('pivot.role', 'cataloger')?->id ?? $part?->catalogers->first()?->id ?? $r->cataloger_id;
                } elseif ($r->catalog_volume_part_id) {
                    $cataloger = $primary[$r->catalog_volume_id] ?? $r->cataloger_id;
                }
                if ($part?->id !== ($r->catalog_volume_part_id ? (int) $r->catalog_volume_part_id : null) || (int) $cataloger !== (int) $r->cataloger_id) {
                    DB::table('manuscripts')->where('id', $r->id)->update(['catalog_volume_part_id' => $part?->id, 'cataloger_id' => $cataloger]);
                    $stats['changed']++;
                    array_push($touched, $part?->id, $r->catalog_volume_part_id);
                }
            }
        }
        self::recount(array_filter($touched));

        return $stats;
    }

    /** The cataloger (or else first person) of each volume, as CatalogVolume::primaryCataloger. */
    public static function primaryCatalogers(array $volumeIds): array
    {
        if (! $volumeIds) {
            return [];
        }

        return DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumeIds)
            ->orderByRaw("role = 'cataloger' desc")->orderBy('id')->get(['catalog_volume_id', 'cataloger_id'])
            ->groupBy('catalog_volume_id')->map(fn ($g) => (int) $g->first()->cataloger_id)->all();
    }

    /** Takes again the copies of the parts given. */
    public static function recount(array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            DB::table('catalog_volume_parts')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_part_id', $id)->count()]);
        }
    }
}
