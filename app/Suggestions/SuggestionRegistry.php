<?php

namespace App\Suggestions;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\Person;
use App\Models\Work;
use App\Support\HijriYear;
use Illuminate\Database\Eloquent\Model;

/**
 * The single list of fields the public may suggest corrections for. It is the
 * submission whitelist, it drives the per-field buttons on the record pages and
 * it tells the reviewer desk how an approved value is applied.
 *
 * Input types:
 *   text, textarea, boolean   plain column write
 *   integer      optional min/max
 *   hijri_date   raw text column plus its numeric year column (year_column)
 *   person       name column plus a link to `people` (id_column), see SuggestionApplier
 *   quote        incipit/explicit: column plus the metadata list the page renders (meta_key)
 *   list         one item per line; stored in a metadata list (meta_key) or in the field's own
 *                JSON column, as strings or as objects keyed by item_key
 *   catalog_page where the record stands in a source, as «ج 2، ص 7» (and «، ردیف 3» with with_row):
 *                the record's own Fankha columns (columns), or its entry in another source (entry_id,
 *                in a field named source_entry_{id}, see sourceEntryField); «نیست» (absent)
 *                rejects that entry; or one page a work's bibliography cites (bibliography_page_{catalog}_{volume}_{page},
 *                see bibliographyPageField), rewritten in the bibliography text. See SuggestionApplier.
 *
 * Optional keys: required (no empty value), mirror (columns that take the same value, e.g. clean_title),
 * bit_column/bit_label (a flag meaning "the catalog says there is none").
 */
class SuggestionRegistry
{
    public const TYPES = [
        'manuscript' => Manuscript::class,
        'work' => Work::class,
        'person' => Person::class,
        'library' => Library::class,
    ];

    // note: a research note added to the record, see NoteTypes; feedback: about the site, no record
    public const KINDS = ['field', 'general', 'relation', 'note', 'feedback'];

    /** Categories of site feedback, kept in field_name. */
    public const FEEDBACK_CATEGORIES = [
        'bug' => 'اشکال فنی (چیزی کار نمی‌کند)',
        'data' => 'خطا در داده‌های این صفحه',
        'idea' => 'پیشنهاد برای سایت',
        'other' => 'موارد دیگر',
    ];

    /**
     * Relational suggestions per record type: the submitter points at another record (target).
     * Stored with kind "relation", the key in field_name and {"target_id": …} in payload.
     *
     * apply: how approval works. move/merge (RecordMerger), merge_people (the people merge tool),
     * contributor_set (the record's primary scribe or author becomes the target person),
     * contributor_add (the target person is added as a further scribe or author),
     * alias (no target: the submitted text is added to the person's other names),
     * person_role (a person connected with the copy in a role; the target is optional, a name will do),
     * record_relation (a commentary on, copied from… another record; see RecordRelations),
     * relocate (the copy is now in another library or has a new shelfmark; see ManuscriptLocation),
     * merge_library (a duplicate library, or one absorbed into another, is folded into it; see LibraryMerger),
     * rename_library (no target: the submitted text is the library's new name; see LibraryMerger),
     * remove (no target: the record should not exist and is deleted; see RecordRemover),
     * split (no target: two works or people were catalogued as one; the reviewer creates the second; see RecordSplitter).
     * needs_text: the submitted text is the value itself (another name, a new name) and may not be empty.
     * Relations with options (person_role, record_relation) carry the chosen option in payload.role.
     */
    public const RELATIONS = [
        'manuscript' => [
            'move_to_work' => ['label' => 'این نسخه از اثر دیگری است', 'target' => 'work', 'action' => 'انتقال نسخه به اثر دیگر', 'apply' => 'move'],
            'duplicate' => ['label' => 'این نسخه تکراری است (همان نسخه‌ی دیگری است)', 'target' => 'manuscript', 'action' => 'ادغام نسخه‌ی تکراری', 'apply' => 'merge'],
            'scribe_is' => ['label' => 'کاتب این نسخه همان شخصی است که در فهارس ثبت شده', 'target' => 'person', 'action' => 'پیوند کاتب به این شخص', 'apply' => 'contributor_set'],
            'scribe_also' => ['label' => 'کاتب دیگری هم در کتابت این نسخه سهم داشته است', 'target' => 'person', 'action' => 'افزودن کاتب', 'apply' => 'contributor_add'],
            'person_role' => ['label' => 'شخص دیگری با این نسخه مرتبط است (مالک، واقف، مصحح، محشی…)', 'target' => 'person', 'target_optional' => true, 'action' => 'افزودن شخص مرتبط با نسخه', 'apply' => 'person_role'],
            'manuscript_relation' => ['label' => 'این نسخه با نسخه‌ی دیگری رابطه دارد (استنساخ، مقابله، مجلد دیگر، نسخه‌ی عکسی یا میکروفیلم)', 'target' => 'manuscript', 'action' => 'ثبت رابطه‌ی دو نسخه', 'apply' => 'record_relation'],
            // The new library is optional (only the number changed); payload.shelfmark carries the new number
            'relocated' => ['label' => 'این نسخه به کتابخانه‌ی دیگری منتقل شده یا شماره‌اش تغییر کرده است', 'target' => 'library', 'target_optional' => true, 'action' => 'تغییر کتابخانه یا شماره‌ی نسخه', 'apply' => 'relocate'],
            'redundant' => ['label' => 'این رکورد زائد است (نسخه‌ای وجود ندارد؛ مثلاً توضیح یا سرعنوانی که به‌اشتباه نسخه ثبت شده)', 'target' => null, 'action' => 'حذف رکورد زائد', 'apply' => 'remove'],
        ],
        'work' => [
            'duplicate' => ['label' => 'این اثر تکراری است (همان اثر دیگری است)', 'target' => 'work', 'action' => 'ادغام اثر تکراری', 'apply' => 'merge'],
            'author_is' => ['label' => 'پدیدآور این اثر شخص دیگری است (انتساب فعلی نادرست است)', 'target' => 'person', 'action' => 'تعیین پدیدآور', 'apply' => 'contributor_set'],
            'author_also' => ['label' => 'این اثر پدیدآور دیگری هم دارد', 'target' => 'person', 'action' => 'افزودن پدیدآور', 'apply' => 'contributor_add'],
            'work_relation' => ['label' => 'این اثر با اثر دیگری رابطه دارد (شرح، حاشیه، ترجمه، تلخیص، نظم، تکمله، ردیه…)', 'target' => 'work', 'action' => 'ثبت رابطه‌ی دو اثر', 'apply' => 'record_relation'],
            'redundant' => ['label' => 'این رکورد زائد است (اثری وجود ندارد؛ مثلاً توضیح یا سرعنوانی که به‌اشتباه اثر ثبت شده)', 'target' => null, 'action' => 'حذف رکورد زائد', 'apply' => 'remove'],
            'conflated' => ['label' => 'دو اثر متفاوت در این رکورد خلط شده‌اند (برخی نسخه‌ها از اثر دیگری‌اند)', 'target' => null, 'action' => 'تفکیک اثر', 'apply' => 'split', 'needs_text' => true],
        ],
        'library' => [
            'duplicate' => ['label' => 'این کتابخانه تکراری است (همان کتابخانه‌ی دیگری است)', 'target' => 'library', 'action' => 'ادغام کتابخانه‌ی تکراری', 'apply' => 'merge_library'],
            'merged_into' => ['label' => 'این کتابخانه با کتابخانه‌ی دیگری ادغام شده و نسخه‌هایش اکنون آنجاست', 'target' => 'library', 'action' => 'ادغام در کتابخانه‌ی دیگر', 'apply' => 'merge_library'],
            'renamed' => ['label' => 'نام این کتابخانه تغییر کرده است', 'target' => null, 'action' => 'تغییر نام کتابخانه', 'apply' => 'rename_library', 'needs_text' => true],
        ],
        'person' => [
            'duplicate' => ['label' => 'این شخص همان شخص دیگری است', 'target' => 'person', 'action' => 'ادغام اشخاص', 'apply' => 'merge_people'],
            'alias' => ['label' => 'نام یا شهرت دیگری از همین شخص', 'target' => null, 'action' => 'افزودن نام دیگر', 'apply' => 'alias', 'needs_text' => true],
            'redundant' => ['label' => 'این رکورد زائد است (شخصی با این نام وجود ندارد؛ مثلاً عنوان یا عبارتی که به‌اشتباه نام شخص ثبت شده)', 'target' => null, 'action' => 'حذف رکورد زائد', 'apply' => 'remove'],
            'conflated' => ['label' => 'دو شخص متفاوت در این رکورد خلط شده‌اند (برخی آثار یا نسخه‌ها از شخص دیگری‌اند)', 'target' => null, 'action' => 'تفکیک شخص', 'apply' => 'split', 'needs_text' => true],
        ],
    ];

    /**
     * Answers of «تصحیح جمعی» (backlog item 80) that keep the data as it is: kept out of the suggestion forms, filed
     * only by a queue, and decided in the panel's queue (apply «queue»).
     */
    public const QUEUE_RELATIONS = [
        'person' => [
            'namesakes_apart' => ['label' => 'این اشخاص هم‌نام چند نفرند و جدا می‌مانند', 'target' => null, 'action' => 'جدا ماندن اشخاص هم‌نام', 'apply' => 'queue', 'queue_only' => true],
            'one_person' => ['label' => 'همه‌ی نسخه‌های این رکورد از یک نفرند', 'target' => null, 'action' => 'یک نفر بودن رکورد', 'apply' => 'queue', 'queue_only' => true],
        ],
    ];

    public static function relation(string $type, ?string $key): ?array
    {
        $relation = self::RELATIONS[self::typeKey($type)][$key] ?? self::QUEUE_RELATIONS[self::typeKey($type)][$key] ?? null;

        return $relation ? ['key' => $key] + $relation : null;
    }

    private const FLAG_LABELS = [
        'is_autograph' => 'اصل نسخه (دستخط مؤلف)',
        'has_author_marginalia' => 'حواشی به خط مؤلف',
        'is_illuminated' => 'تذهیب و سرلوح',
        'is_illustrated' => 'نگاره و نقاشی',
        'is_corrected' => 'تصحیح‌شده (مصحح)',
        'has_marginal_notes' => 'دارای حواشی (محشی)',
        'is_ruled' => 'سطور مجدول (جدول‌بندی)',
        'has_catchwords' => 'دارای رکابه',
        'is_collated' => 'مقابله و بلاغ',
        'is_facsimile' => 'نسخه عکسی / میکروفیلم',
    ];

    public static function fields(string $type): array
    {
        return match (self::typeKey($type)) {
            'manuscript' => self::manuscriptFields(),
            'work' => self::workFields(),
            'person' => self::personFields(),
            'library' => self::libraryFields(),
            default => [],
        };
    }

    public static function field(string $type, ?string $name): ?array
    {
        if ($name === null) {
            return null;
        }
        $field = self::fields($type)[$name] ?? match (self::typeKey($type)) {
            'manuscript' => self::sourceEntryField($name),
            'work' => self::bibliographyPageField($name),
            default => null,
        };

        return $field ? ['name' => $name] + $field : null;
    }

    /** Accepts a short key ('manuscript') or a model class. */
    public static function typeKey(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if (isset(self::TYPES[$type])) {
            return $type;
        }
        $key = array_search(ltrim($type, '\\'), self::TYPES, true);

        return $key === false ? null : $key;
    }

    public static function modelClass(string $type): ?string
    {
        $key = self::typeKey($type);

        return $key ? self::TYPES[$key] : null;
    }

    /** Short identification of a record for pickers and the reviewer desk. */
    public static function describe(Model $record): array
    {
        return match (self::typeKey($record::class)) {
            'manuscript' => [
                'label' => trim(($record->work?->primary_title ?? 'نسخه') . ' — ' . ($record->libraryRecord?->name ?? $record->library ?? '') . ' ' . ($record->shelfmark ?? '')),
                'sub' => implode(' · ', array_filter([$record->city, $record->scribe_name ? 'کاتب: ' . $record->scribe_name : null, $record->copy_date_raw])),
                'url' => route('manuscripts.show', $record),
            ],
            'work' => [
                'label' => $record->primary_title,
                'sub' => implode(' · ', array_filter([$record->author_name, $record->composition_date_raw, $record->manuscripts_count . ' نسخه'])),
                'url' => route('works.show', $record),
            ],
            'person' => [
                'label' => $record->name,
                'sub' => implode(' · ', array_filter([
                    \App\Http\Controllers\SuggestionController::lifeYears($record),
                    $record->works_count ? $record->works_count . ' اثر' : null,
                    $record->manuscripts_count ? $record->manuscripts_count . ' نسخه' : null,
                ])),
                'url' => route('people.show', $record),
            ],
            'library' => ['label' => $record->name, 'sub' => $record->city, 'url' => route('libraries.show', $record)],
            default => ['label' => '#' . $record->getKey(), 'sub' => null, 'url' => null],
        } + ['id' => $record->getKey()];
    }

    public static function typeLabel(?string $type): string
    {
        if ($type === null) {
            return 'بازخورد سایت';
        }

        return match (self::typeKey($type)) {
            'manuscript' => 'نسخه',
            'work' => 'اثر',
            'person' => 'شخص',
            'library' => 'کتابخانه',
            default => class_basename($type),
        };
    }

    /** The address field of a manuscript's entry in another source (step 13.3). */
    public const SOURCE_ENTRY_PREFIX = 'source_entry_';

    /** The name that field had before step 13.3, by the manuscript_catalog_references row. */
    public const CATALOG_REFERENCE_PREFIX = 'catalog_reference_';

    /** Value of a catalog_page field that says the record is not in that source at all. */
    public const ABSENT = 'نیست';

    /**
     * The fields of one record: the fixed list plus, for a manuscript, one address field per catalogue
     * reference shown on its page (backlog item 51).
     */
    public static function recordFields(Model $record): array
    {
        $fields = self::fields($record::class);
        if ($record instanceof Manuscript) {
            // the record's entries the page shows, each with its own address field (Fankha's own is fankha_page)
            foreach ($record->sources()->cards() as $entry) {
                if ($field = self::sourceEntryField(self::SOURCE_ENTRY_PREFIX . $entry->id, $entry)) {
                    $fields[self::SOURCE_ENTRY_PREFIX . $entry->id] = $field;
                }
            }
        }
        if ($record instanceof Work) {
            foreach (\App\Support\BibliographyLinks::viewerPages($record->sources()->bibliography()->pluck('citation_text')) as $vp) {
                $name = self::bibliographyPageName($vp);
                $fields[$name] = self::bibliographyPageField($name);
            }
        }

        return $fields;
    }

    /**
     * The address field of a manuscript's entry in another source, «source_entry_{id}» (step 13.3). A suggestion made
     * before it names the manuscript_catalog_references row, «catalog_reference_{id}», and finds the entry copied from it.
     */
    public static function sourceEntryField(string $name, ?SourceEntry $entry = null): ?array
    {
        if (! $entry) {
            $entry = match (true) {
                (bool) preg_match('/^' . self::SOURCE_ENTRY_PREFIX . '(\d+)$/', $name, $m) => SourceEntry::find((int) $m[1]),
                (bool) preg_match('/^' . self::CATALOG_REFERENCE_PREFIX . '(\d+)$/', $name, $m) => SourceEntry::where('legacy_type', 'catalog_reference')->where('legacy_id', (int) $m[1])->first(),
                default => null,
            };
        }
        // Fankha's own entry is fankha_page; the page Fankha cites has no image to compare with
        if (! $entry || $entry->is_primary || $entry->role !== SourceEntry::ROLE_ENTRY || $entry->origin === SourceEntry::ORIGIN_FANKHA_CITATION) {
            return null;
        }

        return [
            'label' => 'نشانی در ' . implode('، ', array_filter([$entry->sourceName(), $entry->editionLabel() ? 'چاپ ' . $entry->editionLabel() : null])),
            'input' => 'catalog_page',
            'entry_id' => $entry->id,
            'can_be_absent' => true,
        ];
    }

    public const BIBLIOGRAPHY_PAGE_PREFIX = 'bibliography_page_';

    /** The address field of one page a work's bibliography cites, by the viewer detail of that page. */
    public static function bibliographyPageName(array $viewer): string
    {
        return self::BIBLIOGRAPHY_PAGE_PREFIX . "{$viewer['catalog']}_{$viewer['volume']}_{$viewer['page']}";
    }

    /** «bibliography_page_dharia_25_101»: page 101 of volume 25 of al-Dharia, as cited in the work's bibliography. */
    public static function bibliographyPageField(string $name): ?array
    {
        if (! preg_match('/^' . self::BIBLIOGRAPHY_PAGE_PREFIX . '([a-z0-9-]+)_(\d+)_(\d+)$/', $name, $m)) {
            return null;
        }
        $source = collect(config('bibliography.sources', []))->first(fn ($src) => ($src['viewer']['catalog'] ?? null) === $m[1]);
        if (! $source) {
            return null;
        }

        return [
            'label' => 'نشانی در ' . $source['viewer']['name'],
            'input' => 'catalog_page',
            'bibliography' => ['catalog' => $m[1], 'volume' => (int) $m[2], 'page' => (int) $m[3]],
            'unit' => $source['unit'] ?? 'ص',
        ];
    }

    /** «ج 2، ص 7، ردیف 3» read back into numbers; null when the volume or the page is missing. */
    public static function parseCatalogPage(?string $value): ?array
    {
        $value = strtr((string) $value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $read = fn (string $key) => preg_match('/(?:' . $key . ')\s*\.?\s*:?\s*(\d+)/u', $value, $m) ? (int) $m[1] : null;
        $parsed = ['volume' => $read('ج'), 'page' => $read('ص|ستون'), 'row' => $read('ردیف')];

        return $parsed['volume'] && $parsed['page'] ? $parsed : null;
    }

    public static function formatCatalogPage(?int $volume, ?int $page, ?int $row = null, string $unit = 'ص'): ?string
    {
        if (! $volume || ! $page) {
            return null;
        }

        return \App\Support\Fa::digits("ج {$volume}، {$unit} {$page}" . ($row ? "، ردیف {$row}" : ''));
    }

    /** The field's current value as plain text, for the suggestion form and the reviewer's comparison. */
    public static function currentValue(Model $record, array $field): ?string
    {
        $name = $field['name'];

        $value = match ($field['input']) {
            'boolean' => $record->{$name} ? 'بله' : 'خیر',
            'quote' => implode("\n\n", array_column(self::metaList($record, $field['meta_key']), 'text')) ?: $record->{$name},
            'list' => implode("\n", self::listItems($record, $field)),
            'catalog_page' => match (true) {
                isset($field['entry_id']) => (fn ($e) => $e ? self::formatCatalogPage($e->volumeNumber(), $e->page_start) : null)(SourceEntry::find($field['entry_id'])),
                isset($field['bibliography']) => self::formatCatalogPage($field['bibliography']['volume'], $field['bibliography']['page'], null, $field['unit']),
                default => self::formatCatalogPage($record->{$field['columns'][0]}, $record->{$field['columns'][1]}, isset($field['columns'][2]) ? $record->{$field['columns'][2]} : null),
            },
            default => $record->{$name},
        };

        // «بی‌کاتب» / «بی‌تاریخ»: the catalog states the copy has none
        if (($value === null || $value === '') && ! empty($field['bit_column']) && $record->{$field['bit_column']}) {
            return $field['bit_label'];
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @return string[] */
    public static function listItems(Model $record, array $field): array
    {
        $items = isset($field['meta_key']) ? self::metaList($record, $field['meta_key']) : (array) ($record->{$field['name']} ?? []);
        if (isset($field['item_key'])) {
            $items = array_map(fn ($item) => is_array($item) ? ($item[$field['item_key']] ?? '') : (string) $item, $items);
        }

        return array_values(array_filter(array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : '', $items), 'strlen'));
    }

    private static function metaList(Model $record, string $key): array
    {
        $list = $record->metadata[$key] ?? [];

        return is_array($list) ? array_values(array_filter($list)) : [];
    }

    private static function manuscriptFields(): array
    {
        $fields = [
            'scribe_name' => ['label' => 'کاتب', 'input' => 'person', 'id_column' => 'scribe_id', 'role' => 'scribe', 'bit_column' => 'is_bika', 'bit_label' => 'بی‌کاتب'],
            'copy_date_raw' => ['label' => 'تاریخ کتابت', 'input' => 'hijri_date', 'year_column' => 'copy_date_hijri_year', 'bit_column' => 'is_bita', 'bit_label' => 'بی‌تاریخ'],
            'copy_place' => ['label' => 'محل کتابت', 'input' => 'text'],
            'commissioned_by' => ['label' => 'سفارش‌دهنده‌ی کتابت', 'input' => 'text'],
            'shelfmark' => ['label' => 'شماره‌ی نسخه', 'input' => 'text'],
            'script_names' => ['label' => 'نوع خط', 'input' => 'text'],
            'script_style' => ['label' => 'کیفیت خط', 'input' => 'text'],
            'folios' => ['label' => 'تعداد برگ', 'input' => 'integer'],
            'lines' => ['label' => 'تعداد سطر', 'input' => 'integer'],
            'dimensions' => ['label' => 'ابعاد', 'input' => 'text'],
            'paper' => ['label' => 'کاغذ', 'input' => 'textarea'],
            'binding' => ['label' => 'جلد', 'input' => 'textarea'],
            'incipit_text' => ['label' => 'آغاز نسخه', 'input' => 'quote', 'meta_key' => 'incipits'],
            'explicit_text' => ['label' => 'انجام نسخه', 'input' => 'quote', 'meta_key' => 'explicits'],
            'ownership_and_seals' => ['label' => 'مهرها، تملک‌ها و وقف‌نامه‌ها', 'input' => 'list', 'meta_key' => 'ownership_and_seals'],
            // Where the copy stands in Fankha; the library catalogues get source_entry_{id} fields
            'fankha_page' => ['label' => 'نشانی در فنخا', 'input' => 'catalog_page', 'columns' => ['volume_number', 'page_start', 'sequence_number'], 'with_row' => true],
        ];

        foreach (self::FLAG_LABELS as $name => $label) {
            $fields[$name] = ['label' => $label, 'input' => 'boolean'];
        }

        return $fields;
    }

    private static function workFields(): array
    {
        return [
            'primary_title' => ['label' => 'عنوان اثر', 'input' => 'text', 'mirror' => ['clean_title'], 'required' => true],
            'alternative_titles' => ['label' => 'عناوین دیگر', 'input' => 'list', 'item_key' => 'title'],
            'transliteration' => ['label' => 'آوانگاری عنوان', 'input' => 'text'],
            'author_name' => ['label' => 'پدیدآور', 'input' => 'person', 'id_column' => 'author_id', 'role' => 'author'],
            'composition_date_raw' => ['label' => 'تاریخ تألیف', 'input' => 'hijri_date', 'year_column' => 'composition_year_hijri'],
            'composition_place' => ['label' => 'محل تألیف', 'input' => 'text'],
            'language_summary' => ['label' => 'زبان', 'input' => 'text'],
            'work_form' => ['label' => 'قالب (نثر، نظم و…)', 'input' => 'text'],
            'dedication' => ['label' => 'اهدا / به درخواست', 'input' => 'text'],
            'related_work' => ['label' => 'اثر مرتبط / وابسته به', 'input' => 'textarea'],
            'description' => ['label' => 'معرفی اثر', 'input' => 'textarea'],
            'incipit_text' => ['label' => 'آغاز اثر', 'input' => 'textarea'],
            'explicit_text' => ['label' => 'انجام اثر', 'input' => 'textarea'],
            'print_info' => ['label' => 'چاپ‌ها و سوابق اثر', 'input' => 'textarea'],
            'commentaries_and_glosses' => ['label' => 'شروح، حواشی و منظومه‌ها', 'input' => 'list'],
            'bibliography' => ['label' => 'مآخذ کتاب‌شناسی', 'input' => 'list'],
            // Where the work's entry starts in Fankha (page_end moves with it); each cited bibliography page gets a
            // bibliography_page_{catalog}_{volume}_{page} field
            'fankha_page' => ['label' => 'نشانی در فنخا', 'input' => 'catalog_page', 'columns' => ['volume_number', 'page_start'], 'end_column' => 'page_end'],
        ];
    }

    private static function personFields(): array
    {
        return [
            'name' => ['label' => 'نام', 'input' => 'text', 'required' => true],
            'aliases' => ['label' => 'شهرت‌ها و القاب', 'input' => 'list'],
            'transliteration' => ['label' => 'آوانگاری نام', 'input' => 'text'],
            'birth_year_hijri' => ['label' => 'سال تولد (قمری)', 'input' => 'integer', 'min' => 1, 'max' => HijriYear::MAX],
            'death_year_hijri' => ['label' => 'سال وفات (قمری)', 'input' => 'integer', 'min' => 1, 'max' => HijriYear::MAX],
            'death_year_gregorian' => ['label' => 'سال وفات (میلادی)', 'input' => 'integer', 'min' => 500, 'max' => 2100],
            'bio_notes' => ['label' => 'زندگی‌نامه‌ی کوتاه', 'input' => 'textarea'],
        ];
    }

    // Name and city are left out: they are copied onto every manuscript and the search index,
    // so a change goes through the library admin page (reported as a general suggestion)
    private static function libraryFields(): array
    {
        return [
            'full_name' => ['label' => 'نام کامل', 'input' => 'text'],
            'country' => ['label' => 'کشور', 'input' => 'text'],
            'description' => ['label' => 'معرفی کتابخانه', 'input' => 'textarea'],
        ];
    }
}
