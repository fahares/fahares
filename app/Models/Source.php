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

    /** Whether the site has the source's page images; a source it has as text only («فهرستواره منزوی») has none. */
    public function hasPageImages(): bool
    {
        return ($this->metadata['images'] ?? true) !== false;
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

    /** The printing this one reprints (the Masjid-e A'zam catalogue of 1388 is a printing of the one Fankha cites). */
    public function editionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'edition_of_id');
    }

    public function editions(): HasMany
    {
        return $this->hasMany(self::class, 'edition_of_id');
    }
}
