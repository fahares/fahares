<?php

namespace App\Suggestions;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Sources\SourceEntrySync;
use App\Support\CopyExtent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Makes a copy's record from the panel (backlog item 149, step 14.306): a treatise a researcher saw in a volume and
 * the catalogues do not list, or one a printed catalogue describes and Fankha does not. The copy goes under a work
 * the site has, under a new one, or under an untitled one made for it.
 *
 * Such a record stands in no volume of Fankha: its catalog_id, volume_number and page_start are empty and it has no
 * text of a source (raw_text). The empty catalog_id is what keeps the corpus commands away from it: they find copies
 * by an id of their decision files, by Fankha's volume, or among the copies with a catalog (fromFankha). Where the
 * knowledge came from is kept in metadata.provenance and shown on the copy's page (Manuscript::provenance).
 *
 * The audit log entry («create») holds what was made, and undo() takes it back.
 */
class ManuscriptCreator
{
    public const WORK_MODES = ['existing' => 'اثری که در سایت هست', 'new' => 'اثر تازه', 'untitled' => 'بی‌عنوان'];

    /** Where the knowledge of the copy came from */
    public const SOURCE_KINDS = [Manuscript::PROVENANCE_RESEARCHER => 'پژوهشگری که نسخه را دیده است', Manuscript::PROVENANCE_CATALOGUE => 'فهرست چاپی'];

    /** The copies a library already has under the shelfmark, written with either digits. */
    public function duplicates(?int $libraryId, ?string $shelfmark): Collection
    {
        $key = self::shelfmarkKey($shelfmark);
        if (! $libraryId || $key === '') {
            return collect();
        }

        return Manuscript::where('library_id', $libraryId)
            ->whereIn('shelfmark_key', array_unique([$key, mb_substr(trim((string) $shelfmark), 0, 100), \App\Support\Fa::digits($key)]))
            ->with('work:id,primary_title,clean_title')->limit(20)->get();
    }

    private static function shelfmarkKey(?string $shelfmark): string
    {
        return mb_substr(trim(strtr((string) $shelfmark, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])), 0, 100);
    }

    /**
     * @param  array  $data  the form's fields, validated (Admin\ManuscriptController::rules)
     */
    public function create(array $data, ?int $userId = null, ?string $ip = null): Manuscript
    {
        return DB::transaction(function () use ($data, $userId, $ip) {
            $library = Library::find($data['library_id'] ?? 0) ?? throw new InvalidArgumentException('کتابخانه پیدا نشد.');
            $shelfmark = self::shelfmarkKey($data['shelfmark'] ?? '');
            if ($shelfmark === '') {
                throw new InvalidArgumentException('شمارۀ نسخه را بنویسید.');
            }
            $key = $this->collectionKey($shelfmark, $data['collection_key'] ?? null);
            $made = ['manuscript_id' => null, 'work_id' => null, 'source_entry_ids' => [], 'keyed_manuscript_ids' => []];

            [$work, $made['work_id']] = $this->work($data, $library, $shelfmark);
            $range = filled($data['range'] ?? null)
                ? (CopyExtent::parseRange($data['range']) ?? throw new InvalidArgumentException('جای نسخه را به شکل «۷پ تا ۱۲پ» یا «۱–۱۲» بنویسید.'))
                : null;
            $scribe = filled($data['scribe_id'] ?? null) ? (Person::find($data['scribe_id']) ?? throw new InvalidArgumentException('کاتبی با این شناسه پیدا نشد.')) : null;

            $manuscript = Manuscript::create([
                'work_id' => $work->id,
                'catalog_id' => null,
                'volume_number' => null,
                'page_start' => null,
                // after the work's copies of the catalogues, in the order they were added
                'sequence_number' => (int) Manuscript::where('work_id', $work->id)->max('sequence_number') + 1,
                'library_id' => $library->id,
                'library' => $library->name,
                'city' => $library->city,
                'shelfmark' => $shelfmark,
                'collection_key' => $key,
                'scribe_id' => $scribe?->id,
                'scribe_name' => self::text($data['scribe_name'] ?? null) ?? $scribe?->name,
                'copy_date_raw' => self::text($data['copy_date_raw'] ?? null),
                'folios' => filled($data['folios'] ?? null) ? (int) $data['folios'] : null,
                'pages' => filled($data['pages'] ?? null) ? (int) $data['pages'] : null,
                'range_from' => $range[0] ?? null,
                'range_to' => $range[1] ?? null,
                'range_from_number' => $range[2] ?? null,
                'range_to_number' => $range[3] ?? null,
                'incipit_text' => self::text($data['incipit_text'] ?? null),
                'explicit_text' => self::text($data['explicit_text'] ?? null),
                'metadata' => ['provenance' => $this->provenance($data)],
            ]);
            $made['manuscript_id'] = $manuscript->id;

            if ($entry = $this->catalogueEntry($manuscript, $data, $userId)) {
                $made['source_entry_ids'][] = $entry->id;
            }
            $made['keyed_manuscript_ids'] = $this->keySiblings($manuscript, $data['from_id'] ?? null);

            $this->recount($work->id, $library->id);
            $manuscript->refresh()->searchable();

            AuditLog::create([
                'user_id' => $userId,
                'auditable_type' => Manuscript::class,
                'auditable_id' => $manuscript->id,
                'action' => 'create',
                'old_values' => ['created' => $made],
                // the suggestion that reported the copy is in metadata.provenance: not under suggestion_id, which
                // would make this entry the change that suggestion's approval made (AuditLog::activeForSuggestion)
                'new_values' => ['panel' => true]
                    + array_filter($manuscript->only(['work_id', 'library_id', 'shelfmark', 'collection_key', 'scribe_id', 'scribe_name', 'copy_date_raw',
                        'folios', 'pages', 'range_from', 'range_to', 'incipit_text', 'explicit_text', 'metadata']), fn ($v) => $v !== null),
                'ip_address' => $ip,
            ]);

            return $manuscript;
        });
    }

    /**
     * The volume the copy is bound in: the one its shelfmark names («2897/3»), or the given one when the shelfmark is
     * that volume's plain number («3522» in volume 3522), as fahares:link-plain-volumes keys such copies.
     */
    private function collectionKey(string $shelfmark, ?string $given): ?string
    {
        $given = self::text($given) === null ? null : self::shelfmarkKey($given);
        $key = Manuscript::resolvedCollectionKey($shelfmark, null, $given);
        if ($given !== null && $key !== $given) {
            throw new InvalidArgumentException("شمارۀ نسخه با مجموعۀ «{$given}» نمی‌خواند: یا همان شمارۀ مجموعه را بنویسید، یا شماره‌ای با بخش، مانند «{$given}/۳».");
        }

        return $key;
    }

    /** @return array{0: Work, 1: ?int} the copy's work, and its id when it was made here */
    private function work(array $data, Library $library, string $shelfmark): array
    {
        $mode = $data['work_mode'] ?? 'existing';
        if ($mode === 'existing') {
            return [Work::find($data['work_id'] ?? 0) ?? throw new InvalidArgumentException('اثر را از فهرست پیشنهادها انتخاب کنید.'), null];
        }
        $author = $mode === 'new' && filled($data['author_id'] ?? null)
            ? (Person::find($data['author_id']) ?? throw new InvalidArgumentException('پدیدآوری با این شناسه پیدا نشد.'))
            : null;
        $title = $mode === 'new' ? self::text($data['work_title'] ?? null) : 'بی‌عنوان (' . $library->name . '، ش ' . $shelfmark . ')';
        if ($title === null) {
            throw new InvalidArgumentException('عنوان اثر تازه را بنویسید.');
        }
        $work = Work::create([
            'primary_title' => mb_substr($title, 0, 255),
            'clean_title' => mb_substr($title, 0, 255),
            'slug' => 'work-' . Str::random(12),
            'author_id' => $author?->id,
            'author_name' => $mode === 'new' ? (self::text($data['author_name'] ?? null) ?? $author?->name) : null,
            'catalog_id' => null,
            'volume_number' => null,
            'page_start' => null,
        ]);
        $work->update(['slug' => mb_substr(Str::slug($title, '-', null) ?: 'work', 0, 180) . '-' . $work->id]);

        return [$work, $work->id];
    }

    /** What the copy's page says of where the knowledge came from (Manuscript::provenance). */
    private function provenance(array $data): array
    {
        $kind = $data['source_kind'] ?? null;
        if (! isset(self::SOURCE_KINDS[$kind])) {
            throw new InvalidArgumentException('منبع اطلاع را انتخاب کنید.');
        }
        $suggestion = filled($data['suggestion_id'] ?? null)
            ? (FieldSuggestion::find($data['suggestion_id'])?->id ?? throw new InvalidArgumentException('پیشنهادی با این شماره پیدا نشد.'))
            : null;

        return array_filter(['kind' => $kind, 'text' => self::text($data['source_text'] ?? null), 'suggestion_id' => $suggestion], fn ($v) => $v !== null);
    }

    /** The copy's place in a printed catalogue the site has, as a card of its page: a manual entry, verified by its maker. */
    private function catalogueEntry(Manuscript $manuscript, array $data, ?int $userId): ?SourceEntry
    {
        if (($data['source_kind'] ?? null) !== Manuscript::PROVENANCE_CATALOGUE || blank($data['source_id'] ?? null)) {
            return null;
        }
        $source = Source::find($data['source_id']) ?? throw new InvalidArgumentException('منبعی با این شناسه پیدا نشد.');
        $place = SuggestionRegistry::parseCatalogPage($data['source_place'] ?? null)
            ?? throw new InvalidArgumentException('نشانی در فهرست را به شکل «ج ۶، ص ۶۰۱» بنویسید.');

        return SourceEntry::create([
            'citable_type' => Manuscript::class,
            'citable_id' => $manuscript->id,
            'source_id' => $source->id,
            'source_volume_id' => SourceEntrySync::volumeIds([$source->id])[$source->id][$place['volume']] ?? null,
            'volume_number' => $place['volume'],
            'page_start' => $place['page'],
            'role' => SourceEntry::ROLE_ENTRY,
            'origin' => SourceEntry::ORIGIN_MANUAL,
            'status' => SourceEntry::STATUS_VERIFIED,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * «جزء دیگری از همین مجموعه»: the copy the form was opened from joins the new one's volume when it has no volume
     * yet and its plain shelfmark is the volume's number («12422» of a library that gave the volume one number).
     *
     * @return int[] the copies given the key
     */
    private function keySiblings(Manuscript $manuscript, mixed $fromId): array
    {
        $from = $manuscript->collection_key !== null && filled($fromId) ? Manuscript::find($fromId) : null;
        if (! $from || $from->collection_key !== null || (int) $from->library_id !== (int) $manuscript->library_id
            || Manuscript::plainVolumeKeyOf($from->shelfmark, $from->raw_text) !== $manuscript->collection_key) {
            return [];
        }
        $from->update(['collection_key' => $manuscript->collection_key]);
        // a «verify» entry, as a reviewer's edit of the field: fahares:link-plain-volumes, which keys such copies by
        // the run of their leaves, then keeps the key (ProtectedColumns)
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Manuscript::class,
            'auditable_id' => $from->id,
            'action' => 'verify',
            'old_values' => ['collection_key' => null],
            'new_values' => ['collection_key' => $manuscript->collection_key, 'direct_edit' => true],
        ]);

        return [$from->id];
    }

    /** Takes back what create() made, from its audit log entry. */
    public function undo(AuditLog $log): void
    {
        $made = $log->old_values['created'] ?? null;
        if (empty($log->new_values['panel']) || ! $made) {
            throw new InvalidArgumentException('این رکورد از پنل ساخته نشده است و بازگرداندنی نیست.');
        }
        $manuscript = Manuscript::find($made['manuscript_id']) ?? throw new InvalidArgumentException("نسخۀ #{$made['manuscript_id']} دیگر وجود ندارد.");
        $workId = $manuscript->work_id;
        $libraryId = $manuscript->library_id;
        $key = $manuscript->collection_key;

        // the record is not coming back under its id: its places in the sources go with it
        SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $manuscript->id)->delete();
        app(RecordRemover::class)->remove($manuscript);

        // the work made for it, unless it has since been given another copy
        if (($made['work_id'] ?? null) && ($work = Work::find($made['work_id'])) && ! Manuscript::where('work_id', $work->id)->exists()) {
            SourceEntry::where('citable_type', Work::class)->where('citable_id', $work->id)->delete();
            app(RecordRemover::class)->remove($work);
        }
        // the copy keyed to the volume loses the key when no other copy of the volume is left
        if ($key !== null && ($made['keyed_manuscript_ids'] ?? [])
            && ! Manuscript::where('library_id', $libraryId)->where('collection_key', $key)->whereNotIn('id', $made['keyed_manuscript_ids'])->exists()) {
            Manuscript::whereIn('id', $made['keyed_manuscript_ids'])->where('collection_key', $key)->get()->each->update(['collection_key' => null]);
        }
        $this->recount($workId, $libraryId);
    }

    private function recount(?int $workId, ?int $libraryId): void
    {
        if ($workId && ($work = Work::find($workId))) {
            $work->update(['manuscripts_count' => Manuscript::where('work_id', $workId)->count()]);
            $work->searchable();
        }
        if ($libraryId) {
            DB::table('libraries')->where('id', $libraryId)->update(['manuscripts_count' => Manuscript::where('library_id', $libraryId)->count()]);
        }
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
