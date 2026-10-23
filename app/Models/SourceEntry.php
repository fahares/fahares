<?php

namespace App\Models;

use App\Support\Fa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Where a work, manuscript or person stands in a source, and the text printed there (step 13.1). The text keeps
 * the place it was taken from: when the entry's address is corrected, a text taken elsewhere is stale until it is
 * taken again at the new place or kept by a reviewer.
 */
class SourceEntry extends Model
{
    public const ROLE_ENTRY = 'entry';

    public const ROLE_ADDENDUM = 'addendum';

    public const ROLE_ERRATUM = 'erratum';

    public const ROLE_CITATION = 'citation';

    public const ROLE_LABELS = [
        self::ROLE_ENTRY => 'مدخل',
        self::ROLE_ADDENDUM => 'استدراک',
        self::ROLE_ERRATUM => 'اصلاحیه',
        self::ROLE_CITATION => 'یادکرد',
    ];

    /** Fankha's own entry of the record. */
    public const ORIGIN_FANKHA = 'fankha';

    /** A library catalogue page Fankha cites, «[ف: 30-576]». */
    public const ORIGIN_FANKHA_CITATION = 'fankha_citation';

    /** A work's bibliography as Fankha gives it, «الذریعه 101/25». */
    public const ORIGIN_BIBLIOGRAPHY = 'bibliography';

    /** Found by matching the shelfmark in another printing (catalogs:match-edition). */
    public const ORIGIN_MATCH = 'match';

    public const ORIGIN_MANUAL = 'manual';

    public const TEXT_NONE = 'none';

    public const TEXT_AUTO = 'auto';

    public const TEXT_EDITED = 'edited';

    public const TEXT_STALE = 'stale';

    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected $attributes = ['role' => self::ROLE_ENTRY, 'text_status' => self::TEXT_NONE, 'status' => self::STATUS_UNVERIFIED];

    protected function casts(): array
    {
        return [
            'volume_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'entry_number' => 'integer',
            'shelfmark_page' => 'integer',
            'is_primary' => 'boolean',
            'locations' => 'array',
            'text_volume_number' => 'integer',
            'text_page' => 'integer',
            'confidence' => 'integer',
            'reviewed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function citable(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function sourceVolume(): BelongsTo
    {
        return $this->belongsTo(SourceVolume::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function hasText(): bool
    {
        return filled($this->entry_text) || filled($this->copy_text);
    }

    /** «استدراک», «اصلاحیه»: the label a copied citation had, or its role's. */
    public function roleLabel(): string
    {
        return $this->notes && $this->legacy_type === 'source_citation' ? $this->notes : (self::ROLE_LABELS[$this->role] ?? $this->role);
    }

    /** A machine match nobody has reviewed yet: the ⓘ badge on the record page. */
    public function isAwaitingReview(): bool
    {
        return $this->origin === self::ORIGIN_MATCH && $this->status === self::STATUS_UNVERIFIED;
    }

    /**
     * The manuscript_catalog_references row this entry was copied from: the suggestion fields still name it until
     * corrections are written here (step 13.3).
     */
    public function referenceId(): ?int
    {
        return $this->legacy_type === 'catalog_reference' ? $this->legacy_id : null;
    }

    /** The source as a page names it: «فنخا», «الذریعة», «فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم». */
    public function sourceName(): string
    {
        return $this->source?->short_name ?: ($this->source?->title ?? '');
    }

    public function volumeNumber(): ?int
    {
        return $this->volume_number ?? $this->sourceVolume?->volume_number;
    }

    /** «ج 2، ص 414» (or «ستون» in the books numbered by column), with «–415» when the entry runs on. */
    public function placeLabel(bool $range = false): string
    {
        $unit = $this->source?->unit === 'ستون' ? 'ستون' : 'ص';

        return Fa::digits(implode('، ', array_filter([
            $this->volumeNumber() ? 'ج ' . $this->volumeNumber() : null,
            $this->page_start ? "{$unit} {$this->page_start}" . ($range && $this->page_end > $this->page_start ? "–{$this->page_end}" : '') : null,
        ])));
    }

    /** The detail of the `open-catalog-viewer` event, or null when the volume has no page images. */
    public function viewer(): ?array
    {
        $code = $this->sourceVolume?->facsimile_code;
        if (! $code || ! $this->page_start) {
            return null;
        }

        return ['catalog' => $code, 'catalogName' => $this->sourceName(), 'volume' => $this->volumeNumber() ?? 1, 'page' => $this->page_start];
    }

    /** The page image the viewer opens, small enough for the side card. */
    public function thumbUrl(): ?string
    {
        $v = $this->viewer();

        return $v ? asset('storage/catalogs/' . $v['catalog'] . '/v' . str_pad($v['volume'], 2, '0', STR_PAD_LEFT) . '/p' . str_pad($v['page'], 4, '0', STR_PAD_LEFT) . '.webp') : null;
    }

    /** The printing as its volume, or else its source, names it: «۱۳۸۸، به کوشش رضا استادی». */
    public function editionLabel(): ?string
    {
        return $this->sourceVolume?->edition_label ?: $this->source?->edition_label;
    }

    /**
     * The heading of the printed text on the record page: «متن فهرست مسجد اعظم، چاپ ۱۳۸۸», the library's catalogue
     * and the year of the printing when its label gives one (vols 3 and 4 of Masjid-e A'zam have none).
     */
    public function printedTextTitle(): string
    {
        $library = $this->source?->library?->name;
        $name = $library ? 'فهرست ' . $library : ($this->sourceName() ?: 'فهرست کتابخانه');
        $year = preg_match('/[0-9۰-۹]{4}/u', (string) $this->sourceVolume?->edition_label, $m) ? '، چاپ ' . $m[0] : '';

        return Fa::digits("متن {$name}{$year}");
    }

    /** The text as the page shows it: Fankha's page marks out, blank lines and spaces folded. */
    public static function cleanText(?string $text): ?string
    {
        if (blank($text)) {
            return null;
        }
        $cleaned = preg_replace('/<!--\s*page:\s*\d+\s*-->/u', '', $text);
        $cleaned = str_replace(["\r\n", "\r"], "\n", $cleaned);
        $cleaned = preg_replace('/^[ \t]+$/m', '', $cleaned);
        $cleaned = preg_replace('/[ \t]{2,}/u', ' ', $cleaned);
        $cleaned = preg_replace("/\n{3,}/u", "\n\n", $cleaned);

        return trim($cleaned);
    }

    /** The entry's own text, cleaned: a work's entry, or a copy's line. */
    public function cleanEntryText(): ?string
    {
        return self::cleanText($this->entry_text ?? $this->copy_text);
    }

    /** Whether the text was taken at another place than the one the entry now has. */
    public function textIsOutOfPlace(): bool
    {
        return $this->hasText() && $this->text_page !== null
            && ($this->text_page !== $this->page_start || $this->text_volume_number !== $this->volume_number);
    }
}
