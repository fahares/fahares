<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A printed work records are described in (step 13.1): Fankha, a library's catalogue, a bibliography or a
 * biographical dictionary. Sources copied from catalogs keep its ids.
 */
class Source extends Model
{
    public const KIND_LIBRARY_CATALOGUE = 'library_catalogue';

    public const KIND_BIBLIOGRAPHY = 'bibliography';

    public const KIND_BIOGRAPHICAL = 'biographical';

    public const KIND_UNION_CATALOGUE = 'union_catalogue';

    public const KIND_OTHER = 'other';

    /** The year of a volume that was never printed. */
    public const UNPUBLISHED = 'مخطوط';

    /**
     * The default rank of each kind: a library's catalogue, written by one who saw the copies, first; Fankha,
     * compiled from those catalogues, last.
     */
    public const KIND_RANKS = [
        self::KIND_LIBRARY_CATALOGUE => 10,
        self::KIND_BIBLIOGRAPHY => 20,
        self::KIND_BIOGRAPHICAL => 30,
        self::KIND_OTHER => 50,
        self::KIND_UNION_CATALOGUE => 90,
    ];

    public const KIND_LABELS = [
        self::KIND_LIBRARY_CATALOGUE => 'فهرست کتابخانه',
        self::KIND_BIBLIOGRAPHY => 'کتاب‌شناسی',
        self::KIND_BIOGRAPHICAL => 'تراجم',
        self::KIND_OTHER => 'دیگر',
        self::KIND_UNION_CATALOGUE => 'فهرستگان',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'volumes_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * A journal (step 14.394, «تراثنا»): each volume is an issue, its pages belong to articles, and it is cited as a
     * journal article, not as a book. Set by `metadata.periodical`.
     */
    public function isPeriodical(): bool
    {
        return ($this->metadata['periodical'] ?? false) === true;
    }

    /** Whether the site has the source's page images; a source it has as text only («فهرستواره منزوی») has none. */
    public function hasPageImages(): bool
    {
        return ($this->metadata['images'] ?? true) !== false;
    }

    /**
     * The signs this source prints its entries with (step 14.159): `origin` and `items` of sign and meaning, from
     * `metadata.legend` or config/source_legends.php by the source's code; a printing without its own has the book's.
     * With a text, only the signs found in it.
     */
    public function legend(?string $text = null): ?array
    {
        $legend = $this->metadata['legend'] ?? config('source_legends.' . $this->code);
        if (! is_array($legend) || empty($legend['items'])) {
            return $this->edition_of_id ? $this->editionOf?->legend($text) : null;
        }

        if ($text !== null) {
            $text = strtr($text, ['ي' => 'ی', 'ك' => 'ک']);
            $legend['items'] = array_values(array_filter($legend['items'], fn (array $item) => preg_match(
                $item['match'] ?? '/(?<![\p{L}\x{200C}])' . preg_quote($item['sign'], '/') . '(?![\p{L}\x{200C}])/u', $text) === 1));
        }

        return $legend['items'] ? $legend : null;
    }

    /**
     * The cover of the printed book (step 13.81): public/images/sources/covers/{code}.webp, mostly from mtif.org
     * (scripts/sources/mtif_covers.py); a printing without its own shows the book's.
     */
    public function coverUrl(): ?string
    {
        if (is_file(public_path("images/sources/covers/{$this->code}.webp"))) {
            return asset("images/sources/covers/{$this->code}.webp");
        }

        return $this->edition_of_id ? $this->editionOf?->coverUrl() : null;
    }

    /** The source of a code, or the one a folded source's code now stands for. */
    public static function byCode(string $code): ?self
    {
        return static::where('code', $code)->first() ?? static::mergedInto($code);
    }

    /** The source a folded source's code now stands for (sources:merge-split keeps it in metadata.merged_codes). */
    public static function mergedInto(string $code): ?self
    {
        return static::whereJsonContains('metadata->merged_codes', $code)->first();
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(SourceVolume::class)->orderBy('volume_number')->orderBy('id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SourceEntry::class);
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    /**
     * Whether the book was never printed: each of its volumes has «مخطوط» for its year, as the catalogue of Masjid-e
     * A'zam that Fankha cites as «ف مخ». A printed catalogue tied to it is another book of the same library, with its
     * own volumes and pages, not another printing of it (step 14.240).
     */
    public function isUnpublished(): bool
    {
        return $this->volumes->isNotEmpty() && $this->volumes->every(fn (SourceVolume $v) => $v->publication_year === self::UNPUBLISHED);
    }

    /**
     * The book this one is tied to: the printing it reprints, or, when that book is unpublished (isUnpublished), the
     * unprinted catalogue of the same library (the Masjid-e A'zam catalogue of 1388 and the one Fankha cites).
     */
    public function editionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'edition_of_id');
    }

    public function editions(): HasMany
    {
        return $this->hasMany(self::class, 'edition_of_id');
    }
}
