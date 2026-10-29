<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

/**
 * One printed entry of a source (plan docs/plans/dharia-text.md): its place, number, title, author as printed and
 * text. Records are tied to it through source_entries.source_item_id.
 */
class SourceItem extends Model
{
    use Searchable;

    public const KIND_ENTRY = 'entry';

    public const KIND_SUB_ENTRY = 'sub_entry';

    public const KIND_ADDENDUM = 'addendum';

    public const TEXT_AUTO = 'auto';

    public const TEXT_EDITED = 'edited';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'volume_number' => 'integer',
            'position' => 'integer',
            'entry_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'author_death_year' => 'integer',
            'flags' => 'array',
            'metadata' => 'array',
        ];
    }

    public function searchableAs(): string
    {
        return 'source_items_index';
    }

    /** The printed entries are searched by their words, the tab «در منابع» (backlog item 83, phase 7); a preface is not */
    public function shouldBeSearchable(): bool
    {
        return $this->kind !== 'preface' && trim((string) $this->text) !== '';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'source_id' => (int) $this->source_id,
            'volume_number' => (int) $this->volume_number,
            'position' => (int) $this->position,
            'entry_number' => $this->entry_number ? (int) $this->entry_number : null,
            'kind' => $this->kind,
            'title' => \App\Helpers\TextNormalizer::expandVariants($this->title),
            'text' => \App\Helpers\TextNormalizer::normalize($this->text),
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function sourceVolume(): BelongsTo
    {
        return $this->belongsTo(SourceVolume::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function refs(): HasMany
    {
        return $this->hasMany(SourceItemRef::class)->orderBy('position');
    }

    public function links(): HasMany
    {
        return $this->hasMany(SourceEntry::class);
    }

    /** The key as the address writes it: «1345-2» for «1345#2», «1442.1» for the sub-entry «1442/1». */
    public function urlKey(): string
    {
        return strtr($this->item_key, ['#' => '-', '/' => '.']);
    }

    public static function keyFromUrl(string $key): string
    {
        return strtr($key, ['-' => '#', '.' => '/']);
    }

    /** The entry's own page: /sources/dharia/3/1442. */
    public function url(): string
    {
        return route('source-items.show', [$this->source->code, $this->volume_number, $this->urlKey()]);
    }

    /** «ج ۳، ص ۴۰۲–۴۰۳، ش ۱۴۴۲» (or «ستون» in the books numbered by column). */
    public function placeLabel(): string
    {
        return \App\Support\Fa::digits(implode('، ', array_filter([
            'ج ' . $this->volume_number,
            ($this->source?->unit === 'ستون' ? 'ستون ' : 'ص ') . $this->page_start . ($this->page_end > $this->page_start ? '–' . $this->page_end : ''),
            $this->entry_number ? 'ش ' . $this->entry_number : null,
        ])));
    }

    /**
     * The text cut where its pages turn, for the page marks of the entry page.
     *
     * @return list<array{page: int, text: string}>
     */
    public function pageSegments(): array
    {
        $segments = [];
        $from = 0;
        $page = $this->page_start;
        foreach ($this->metadata['page_breaks'] ?? [] as [$at, $next]) {
            $segments[] = ['page' => $page, 'text' => trim(mb_substr($this->text, $from, $at - $from))];
            [$from, $page] = [$at, $next];
        }
        $segments[] = ['page' => $page, 'text' => trim(mb_substr($this->text, $from))];

        return $segments;
    }

    /** The detail of the `open-catalog-viewer` event for a page of the entry. */
    public function viewer(?int $page = null): ?array
    {
        $code = $this->source?->hasPageImages() === false ? null : $this->sourceVolume?->facsimile_code;

        return $code ? ['catalog' => $code, 'catalogName' => $this->source?->short_name ?: $this->source?->title,
            'volume' => $this->volume_number, 'page' => $page ?? $this->page_start] : null;
    }
}
