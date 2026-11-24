<?php

namespace App\Suggestions;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptCollection;
use App\Models\ManuscriptNote;
use App\Models\Person;
use App\Models\Script;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Sources\SourceEntrySync;
use App\Support\CopyExtent;
use App\Support\ScriptNameCleaner;
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

    /** The yes-or-no features of a copy, as its page names them */
    public const FLAGS = [
        'is_autograph' => 'اصل نسخه (دستخط مؤلف)',
        'has_author_marginalia' => 'حواشی به خط مؤلف',
        'corrected_by_author' => 'مقابله یا تصحیح به دست مؤلف',
        'collated_with_author_copy' => 'مقابله با نسخۀ مؤلف',
        'copied_from_author_copy' => 'کتابت از روی نسخۀ مؤلف',
        'is_illuminated' => 'تذهیب و سرلوح',
        'is_illustrated' => 'نگاره و نقاشی',
        'is_corrected' => 'تصحیح‌شده',
        'has_marginal_notes' => 'حواشی (محشّی)',
        'is_ruled' => 'جدول‌کشی',
        'has_catchwords' => 'رکابه',
        'is_collated' => 'مقابله و بلاغ',
        'is_facsimile' => 'نسخۀ عکسی یا میکروفیلم',
    ];

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
            [$key, $placed] = $this->collectionKey($library, $shelfmark, $data['collection_key'] ?? null);
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
                // placed by hand in a collection its shelfmark does not name (step 14.353): the rule of the shelfmark leaves it there
                ...($placed ? ['collection_id' => $placed->id, 'collection_pinned' => true] : []),
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
                // the copy's body, as its maker describes it
                'copy_place' => self::text($data['copy_place'] ?? null),
                'commissioned_by' => self::text($data['commissioned_by'] ?? null),
                'dimensions' => self::text($data['dimensions'] ?? null),
                'paper' => self::text($data['paper'] ?? null),
                'binding' => self::text($data['binding'] ?? null),
                // the count alone: lines_raw is the wording of a catalogue's text, which this copy has none of
                'lines' => filled($data['lines'] ?? null) ? (int) $data['lines'] : null,
                'script_style' => self::text($data['script_style'] ?? null),
                ...array_fill_keys(array_values(array_intersect(array_keys(self::FLAGS), array_keys(array_filter($data['flags'] ?? [])))), true),
            ]);
            $made['manuscript_id'] = $manuscript->id;
            $this->scripts($manuscript, self::text($data['script_names'] ?? null));
            $this->notes($manuscript, $data['notes'] ?? []);

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
                    + array_filter($manuscript->only(['work_id', 'library_id', 'shelfmark', 'collection_key', ...($placed ? ['collection_id', 'collection_pinned'] : []), 'scribe_id', 'scribe_name', 'copy_date_raw',
                        'folios', 'pages', 'range_from', 'range_to', 'incipit_text', 'explicit_text', 'metadata', 'copy_place', 'commissioned_by', 'dimensions', 'paper', 'binding',
                        'lines', 'script_names', 'script_style', ...array_keys(self::FLAGS)]), fn ($v) => $v !== null && $v !== false),
                'ip_address' => $ip,
            ]);

            return $manuscript;
        });
    }

    /**
     * «نوع خط» as the copy's page shows it, and the scripts it names as links (manuscript_script), which the filters
     * and counts read: the first, in the order ScriptNameCleaner gives them, is the copy's main hand. A wording that names no script the site knows is kept as text.
     */
    private function scripts(Manuscript $manuscript, ?string $names): void
    {
        if ($names === null) {
            return;
        }
        $clean = ScriptNameCleaner::clean($names);
        $manuscript->update(['script_names' => $clean['scripts'] ? ($clean['wording'] ?? $names) : $names]);
        foreach (array_values($clean['scripts']) as $i => $name) {
            $script = Script::where('name', $name)->first();
            if (! $script) {
                $script = Script::create(['name' => $name, 'slug' => Str::uuid()->toString()]);
                $script->update(['slug' => (Str::slug($name) ?: 'script') . '-' . $script->id]);
            }
            DB::table('manuscript_script')->insertOrIgnore(['manuscript_id' => $manuscript->id, 'script_id' => $script->id, 'is_primary' => $i === 0]);
            $script->update(['manuscripts_count' => DB::table('manuscript_script')->where('script_id', $script->id)->count()]);
        }
    }

    /**
     * The copy's notes with their types (ManuscriptNote), in the order given: seals, ownership, endowment, what the
     * copy lacks and the rest. Typed by an editor, so a later import of notes keeps them.
     *
     * @param  array<int, array{type?: string, text?: string}>  $notes
     */
    private function notes(Manuscript $manuscript, array $notes): void
    {
        $position = 0;
        foreach ($notes as $note) {
            $text = self::text($note['text'] ?? null);
            if ($text === null) {
                continue;
            }
            if (! isset(ManuscriptNote::TYPES[$note['type'] ?? ''])) {
                throw new InvalidArgumentException('نوع یادداشت را انتخاب کنید.');
            }
            ManuscriptNote::create(['manuscript_id' => $manuscript->id, 'position' => $position++, 'type' => $note['type'], 'text' => $text, 'typed_by' => 'editor']);
        }
    }

    /**
     * The volume the copy is bound in: the one its shelfmark names («2897/3»), or the given one when the shelfmark is
     * that volume's plain number («3522» in volume 3522), as fahares:link-plain-volumes keys such copies.
     *
     * Where the shelfmark names another volume or none and the given number is a «مجموعه» the library has (backlog
     * item 163), the copy is placed in that collection by hand, as Admin\ManuscriptController::moveCollection does:
     * a treatise under another number bound in the volume. Its collection_key stays what its shelfmark says.
     *
     * @return array{0: ?string, 1: ?ManuscriptCollection} the key, and the collection the copy is pinned to
     */
    private function collectionKey(Library $library, string $shelfmark, ?string $given): array
    {
        $given = self::text($given) === null ? null : self::shelfmarkKey($given);
        $key = Manuscript::resolvedCollectionKey($shelfmark, null, $given);
        if ($given === null || $key === $given) {
            return [$key, null];
        }
        $collection = ManuscriptCollection::where('library_id', $library->id)->where('key', $given)->first();
        if (! $collection) {
            throw new InvalidArgumentException("شمارۀ نسخه با مجموعۀ «{$given}» نمی‌خواند و کتابخانه مجموعه‌ای با این شماره ندارد: یا همان شمارۀ مجموعه را بنویسید، یا شماره‌ای با بخش، مانند «{$given}/۳».");
        }

        return [Manuscript::resolvedCollectionKey($shelfmark, null, null), $collection];
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
