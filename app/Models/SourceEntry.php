<?php

namespace App\Models;

use App\Models\ManuscriptCatalogReference as Ref;
use App\Sources\LegacyMirror;
use App\Support\Fa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

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

    /** The record is only named inside another work's entry of the source (al-Dhari'a: the poet's divan). */
    public const ROLE_MENTION = 'mention';

    public const ROLE_LABELS = [
        self::ROLE_ENTRY => 'مدخل',
        self::ROLE_ADDENDUM => 'استدراک',
        self::ROLE_ERRATUM => 'اصلاحیه',
        self::ROLE_CITATION => 'یادکرد',
        self::ROLE_MENTION => 'یاد شده در مدخل دیگر',
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

    /** The printed entry this place is, when the source's entries are on the site (al-Dhari'a). */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(SourceItem::class);
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

    /** Machine matches nobody has reviewed yet: the review queue, and the ⓘ badge on the record page. */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('origin', self::ORIGIN_MATCH)->where('status', self::STATUS_UNVERIFIED);
    }

    /** Verifies or rejects a match, in the admin queue or from its ⓘ badge (backlog item 59); the audit log keeps the review it replaced. */
    public function review(User $user, string $status, ?string $ip = null): AuditLog
    {
        return DB::transaction(function () use ($user, $status, $ip) {
            $old = $this->only(['status', 'reviewed_by', 'reviewed_at']);
            $this->update(['status' => $status, 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
            LegacyMirror::reference($this);
            // the researchers' opinions on the match in «تصحیح جمعی» (backlog item 80)
            if ($decision = \App\Review\CatalogMatchQueue::decision($this)) {
                \App\Review\CollectiveReview::settle('catalog', $this->id, $decision, $user);
            }

            return $this->logReview(Ref::ACTION_REVIEW, $user, $old, $ip);
        });
    }

    /**
     * The «بازگردانی» of a review from the badge: back to the queue, only while the review is still the one this user
     * made. Returns null when someone has reviewed the entry again since.
     */
    public function reopen(User $user, string $expected, ?string $ip = null): ?AuditLog
    {
        return DB::transaction(function () use ($user, $expected, $ip) {
            $this->refresh();
            if ($this->origin !== self::ORIGIN_MATCH || $this->status !== $expected || (int) $this->reviewed_by !== $user->id) {
                return null;
            }
            $review = AuditLog::where('auditable_type', static::class)->where('auditable_id', $this->id)
                ->where('action', Ref::ACTION_REVIEW)->latest('id')->first();
            $old = $this->only(['status', 'reviewed_by', 'reviewed_at']) + ['rolled_back_log_id' => $review?->id];
            $this->update(['status' => self::STATUS_UNVERIFIED, 'reviewed_by' => null, 'reviewed_at' => null]);
            LegacyMirror::reference($this);

            return $this->logReview(Ref::ACTION_REOPEN, $user, $old, $ip);
        });
    }

    private function logReview(string $action, User $user, array $old, ?string $ip): AuditLog
    {
        $at = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v;

        return AuditLog::create([
            'user_id' => $user->id,
            'auditable_type' => static::class,
            'auditable_id' => $this->id,
            'action' => $action,
            'old_values' => array_map($at, $old),
            'new_values' => array_map($at, $this->only(['status', 'reviewed_by', 'reviewed_at'])),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }

    /** The `review` prop of its ⓘ badge (backlog item 59); the key also marks the elements a rejection hides (data-machine-item). */
    public function badgeReview(): array
    {
        return [
            'key' => $this->reviewKey(),
            'verify' => route('admin.catalog-references.verify', $this),
            'reject' => route('admin.catalog-references.reject', $this),
        ];
    }

    public function reviewKey(): string
    {
        return 'source-entry-' . $this->id;
    }

    /**
     * Verifies every machine match of the query that awaits review, in one transaction and chunks of 1,000, with one
     * reviewed_at for the batch. The audit log row keeps the ids, the filters and that time: revertBulkVerify() undoes it.
     */
    public static function bulkVerify(Builder $query, User $user, array $filters, ?string $ip = null): AuditLog
    {
        return DB::transaction(function () use ($query, $user, $filters, $ip) {
            $ids = (clone $query)->awaitingReview()->lockForUpdate()->orderBy('id')->pluck('id')->all();
            $now = now()->startOfSecond();
            $values = ['status' => self::STATUS_VERIFIED, 'reviewed_by' => $user->id, 'reviewed_at' => $now, 'updated_at' => $now];
            foreach (array_chunk($ids, 1000) as $chunk) {
                static::whereIn('id', $chunk)->update($values);
            }
            LegacyMirror::reviews($ids, $values);
            $answered = \App\Models\FieldSuggestion::where('review_queue', 'catalog')->whereIn('review_item_id', $ids)->where('status', 'pending')
                ->distinct()->pluck('review_item_id');
            foreach ($answered as $id) {
                \App\Review\CollectiveReview::settle('catalog', (int) $id, 'yes', $user);
            }

            return AuditLog::create([
                'user_id' => $user->id,
                'auditable_type' => static::class,
                'auditable_id' => 0,
                'action' => Ref::ACTION_BULK_VERIFY,
                'old_values' => ['status' => self::STATUS_UNVERIFIED, 'ids' => $ids],
                'new_values' => ['status' => self::STATUS_VERIFIED, 'count' => count($ids), 'filters' => $filters, 'reviewed_at' => $now->toDateTimeString()],
                'ip_address' => $ip,
                'created_at' => $now,
            ]);
        });
    }

    /** A bulk verification, of entries or, before step 13.3, of manuscript_catalog_references rows. */
    public static function isBulkVerify(AuditLog $log): bool
    {
        return $log->action === Ref::ACTION_BULK_VERIFY && in_array($log->auditable_type, [static::class, Ref::class], true);
    }

    /** The latest bulk verification that has not been reverted, if any. */
    public static function lastBulkVerify(): ?AuditLog
    {
        return AuditLog::whereIn('auditable_type', [static::class, Ref::class])->where('action', Ref::ACTION_BULK_VERIFY)
            ->whereNotIn('id', AuditLog::where('auditable_type', AuditLog::class)->where('action', Ref::ACTION_BULK_REVERT)->select('auditable_id'))
            ->latest('id')->first();
    }

    /**
     * Returns a bulk verification's entries to the queue: those still verified by the same reviewer at the batch's
     * time. An entry reviewed again since (rejected, or verified by hand) keeps its review. Returns [reverted, kept].
     */
    public static function revertBulkVerify(AuditLog $batch, ?User $user = null, ?string $ip = null): array
    {
        throw_unless(self::isBulkVerify($batch), new \InvalidArgumentException("Audit log #{$batch->id} is not a bulk verification."));

        return DB::transaction(function () use ($batch, $user, $ip) {
            throw_if(AuditLog::where('auditable_type', AuditLog::class)->where('action', Ref::ACTION_BULK_REVERT)->where('auditable_id', $batch->id)->lockForUpdate()->exists(),
                new \RuntimeException("تأیید گروهی #{$batch->id} پیش‌تر برگردانده شده است."));
            $ids = $batch->old_values['ids'] ?? [];
            // a batch of before step 13.3 names the references; their entries are the ones to revert
            if ($batch->auditable_type === Ref::class) {
                $ids = collect(array_chunk($ids, 1000))->flatMap(fn ($chunk) => static::where('legacy_type', 'catalog_reference')->whereIn('legacy_id', $chunk)->pluck('id'))->all();
            }
            $reverted = [];
            foreach (array_chunk($ids, 1000) as $chunk) {
                $match = static::whereIn('id', $chunk)->where('origin', self::ORIGIN_MATCH)->where('status', self::STATUS_VERIFIED)
                    ->where('reviewed_by', $batch->user_id)->where('reviewed_at', $batch->new_values['reviewed_at']);
                $found = (clone $match)->pluck('id')->all();
                $match->update(['status' => self::STATUS_UNVERIFIED, 'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => now()]);
                $reverted = array_merge($reverted, $found);
            }
            LegacyMirror::reviews($reverted, ['status' => self::STATUS_UNVERIFIED, 'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => now()]);
            $count = count($batch->old_values['ids'] ?? []);
            AuditLog::create([
                'user_id' => $user?->id,
                'auditable_type' => AuditLog::class,
                'auditable_id' => $batch->id,
                'action' => Ref::ACTION_BULK_REVERT,
                'new_values' => ['reverted' => count($reverted), 'kept' => $count - count($reverted)],
                'ip_address' => $ip,
            ]);

            return [count($reverted), $count - count($reverted)];
        });
    }

    /** Whether the record is only named inside another entry of the source. */
    public function isMention(): bool
    {
        return $this->role === self::ROLE_MENTION;
    }

    /** What the machine did, under the ⓘ badge: matched a copy by its shelfmark, or a work by its title and author. */
    public function machineNote(): string
    {
        if ($this->source_item_id && $this->match_method === 'llm') {
            return 'یک مدل زبانی این پیوند را با خواندن متن مدخل‌های صفحه‌ای که فنخا نشانی داده پیشنهاد کرده، و عبارتی که از متن مدخل شاهد آورده وارسی شده است. این پیوند هنوز به دست انسان بازبینی نشده است.';
        }
        if ($this->source_item_id) {
            return $this->isMention()
                ? 'هوشواره این اثر را در متن این مدخل یافته است، با سنجیدن عنوان و مؤلف آن. این پیوند هنوز به دست انسان بازبینی نشده است.'
                : 'هوشواره این مدخل را با سنجیدن عنوان و مؤلف اثر با مدخل‌های صفحه‌ای که فنخا نشانی داده برگزیده است. این پیوند هنوز به دست انسان بازبینی نشده است.';
        }

        return 'این نشانی را هوشواره با تطبیق شماره‌ی نسخه استخراج کرده و هنوز به دست انسان بازبینی نشده است.';
    }

    /** «ش ۱۴۴۲»: the printed entry's number, or its heading when it has none. */
    public function itemLabel(): ?string
    {
        $item = $this->sourceItem;
        if (! $item) {
            return null;
        }

        return $item->entry_number ? Fa::digits('ش ' . $item->entry_number) : '«' . $item->title . '»';
    }

    /** A machine match nobody has reviewed yet: the ⓘ badge on the record page. */
    public function isAwaitingReview(): bool
    {
        return $this->origin === self::ORIGIN_MATCH && $this->status === self::STATUS_UNVERIFIED;
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

    /** «فنخا (استدراک)»: the source, and the entry's role when it is not an entry. */
    public function sourceLabel(): string
    {
        $name = $this->origin === self::ORIGIN_FANKHA_CITATION ? ($this->sourceVolume?->title ?: $this->sourceName()) : $this->sourceName();

        return $this->role === self::ROLE_ENTRY ? $name : "{$name} ({$this->roleLabel()})";
    }

    /**
     * The entry on the record's side card: «فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم، چاپ ۱۳۸۸، ج ۲، ص ۹»,
     * «فنخا، ج ۱، ص ۱۸۵، ردیف ۲» (the row of a copy in the entry its record was made from).
     */
    public function cardLabel(?Model $record = null): string
    {
        $row = $this->is_primary && $record instanceof Manuscript && $record->sequence_number ? 'ردیف ' . $record->sequence_number : null;

        return Fa::digits(implode('، ', array_filter([
            $this->sourceLabel(),
            $this->origin !== self::ORIGIN_FANKHA_CITATION && $this->editionLabel() ? 'چاپ ' . $this->editionLabel() : null,
            $this->placeLabel($record instanceof Work),
            $this->itemLabel(),
            $row,
        ])));
    }

    /** The suggestion field of the entry's address: Fankha's own is the record's fankha_page, any other its own. */
    public function suggestionField(): ?string
    {
        if ($this->is_primary) {
            return 'fankha_page';
        }
        $name = \App\Suggestions\SuggestionRegistry::SOURCE_ENTRY_PREFIX . $this->id;

        return \App\Suggestions\SuggestionRegistry::sourceEntryField($name, $this) ? $name : null;
    }

    /**
     * The heading of the entry's text under «متن مأخذ»: «فهرست کتابخانه مسجد اعظم، چاپ ۱۳۸۸», «فهرستگان نسخه‌های خطی ایران
     * (فنخا)», «…، استدراک».
     */
    public function textTitle(): string
    {
        $title = (string) $this->source?->title;
        $short = (string) $this->source?->short_name;
        $title = match (true) {
            $this->source?->kind === Source::KIND_LIBRARY_CATALOGUE => $this->printedTextTitle(),
            $title !== '' => $title . ($short !== '' && ! str_contains($title, $short) ? " ({$short})" : ''),
            default => $this->sourceName(),
        };

        return $this->role === self::ROLE_ENTRY ? $title : "{$title}، {$this->roleLabel()}";
    }

    /** The detail of the `open-catalog-viewer` event, or null when the volume has no page images. */
    public function viewer(): ?array
    {
        $code = $this->sourceVolume?->facsimile_code;
        if (! $code || ! $this->page_start || $this->source?->hasPageImages() === false) {
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
     * The heading of a library catalogue's text under «متن مأخذ» on the record page: «فهرست کتابخانه مسجد اعظم، چاپ ۱۳۸۸»,
     * the library's catalogue and the year of the printing when its volume's label gives one (vols 3 and 4 of
     * Masjid-e A'zam have none).
     */
    public function printedTextTitle(): string
    {
        $library = (string) $this->source?->library?->name;
        $name = $library !== '' ? 'فهرست ' . (str_starts_with($library, 'کتابخانه') ? '' : 'کتابخانه ') . $library : ($this->sourceName() ?: 'فهرست کتابخانه');
        $year = preg_match('/[0-9۰-۹]{4}/u', (string) $this->sourceVolume?->edition_label, $m) ? '، چاپ ' . $m[0] : '';

        return Fa::digits($name . $year);
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
