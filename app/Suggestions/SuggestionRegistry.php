<?php

namespace App\Suggestions;

use App\Models\Cataloger;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptNote;
use App\Models\SourceEntry;
use App\Models\Person;
use App\Models\Source;
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
        // a printed source's own page (/sources/{code}): its title, compiler, publisher… (step 14.52)
        'source' => Source::class,
    ];

    // note: a research note added to the record, see NoteTypes; feedback: about the site, no record
    public const KINDS = ['field', 'general', 'relation', 'note', 'feedback'];

    /** How the reviewer's forms name each kind. */
    public const KIND_LABELS = [
        'field' => 'اصلاح یک فیلد',
        'note' => 'یادداشت یا منبع پژوهشی',
        'general' => 'پیشنهاد کلی',
        'relation' => 'رابطه یا اصلاح ساختاری (انتقال، ادغام، تکراری…)',
        'feedback' => 'بازخورد سایت (بی‌رکورد)',
    ];

    /** Categories of site feedback, kept in field_name. */
    public const FEEDBACK_CATEGORIES = [
        'bug' => 'اشکال فنی (چیزی کار نمی‌کند)',
        'data' => 'خطا در داده‌های این صفحه',
        'idea' => 'پیشنهاد برای سایت',
        'stats' => 'درخواست آمار یا نمودار تازه',
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
     * person_role (a person connected with the copy or work in a role; the target is optional, a name will do;
 * with remove, the reviewer deletes one of the record's rows instead),
     * record_relation (a commentary on, copied from… another record; see RecordRelations),
     * relocate (the copy is now in another library or has a new shelfmark; see ManuscriptLocation),
     * merge_library (a duplicate library, or one absorbed into another, is folded into it; see LibraryMerger),
     * rename_library (no target: the submitted text is the library's new name; see LibraryMerger),
     * remove (no target: the record should not exist and is deleted; see RecordRemover),
     * split (no target: two works or people were catalogued as one; the reviewer creates the second; see RecordSplitter).
     * needs_text: the submitted text is the value itself (another name, a new name) and may not be empty.
     * person_period (a period of the person's life: its kind, and in the text where, when and the source; see PersonPeriod).
     * Relations with options (person_role, record_relation, person_period) carry the chosen option in payload.role.
     */
    public const RELATIONS = [
        'manuscript' => [
            'move_to_work' => ['label' => 'این نسخه از اثر دیگری است', 'target' => 'work', 'action' => 'انتقال نسخه به اثر دیگر', 'apply' => 'move'],
            'duplicate' => ['label' => 'این نسخه تکراری است (همان نسخۀ دیگری است)', 'target' => 'manuscript', 'action' => 'ادغام نسخۀ تکراری', 'apply' => 'merge'],
            'scribe_is' => ['label' => 'کاتب این نسخه همان شخصی است که در فهارس ثبت شده', 'target' => 'person', 'action' => 'پیوند کاتب به این شخص', 'apply' => 'contributor_set'],
            'scribe_also' => ['label' => 'کاتب دیگری هم در کتابت این نسخه سهم داشته است', 'target' => 'person', 'action' => 'افزودن کاتب', 'apply' => 'contributor_add'],
            'person_role' => ['label' => 'شخص دیگری با این نسخه مرتبط است (مالک، واقف، مصحح، محشی…)', 'target' => 'person', 'target_optional' => true, 'action' => 'افزودن شخص مرتبط با نسخه', 'apply' => 'person_role'],
            'manuscript_relation' => ['label' => 'این نسخه با نسخۀ دیگری رابطه دارد (استنساخ، مقابله، مجلد دیگر، نسخۀ عکسی یا میکروفیلم)', 'target' => 'manuscript', 'action' => 'ثبت رابطۀ دو نسخه', 'apply' => 'record_relation'],
            // The new library is optional (only the number changed); payload.shelfmark carries the new number
            'relocated' => ['label' => 'این نسخه به کتابخانۀ دیگری منتقل شده یا شماره‌اش تغییر کرده است', 'target' => 'library', 'target_optional' => true, 'action' => 'تغییر کتابخانه یا شمارۀ نسخه', 'apply' => 'relocate'],
            'redundant' => ['label' => 'این رکورد زائد است (نسخه‌ای وجود ندارد؛ مثلاً توضیح یا سرعنوانی که به‌اشتباه نسخه ثبت شده)', 'target' => null, 'action' => 'حذف رکورد زائد', 'apply' => 'remove'],
        ],
        'work' => [
            'duplicate' => ['label' => 'این اثر تکراری است (همان اثر دیگری است)', 'target' => 'work', 'action' => 'ادغام اثر تکراری', 'apply' => 'merge'],
            'author_is' => ['label' => 'پدیدآور این اثر شخص دیگری است (انتساب فعلی نادرست است)', 'target' => 'person', 'action' => 'تعیین پدیدآور', 'apply' => 'contributor_set'],
            'author_also' => ['label' => 'این اثر پدیدآور دیگری هم دارد', 'target' => 'person', 'action' => 'افزودن پدیدآور', 'apply' => 'contributor_add'],
            'person_role' => ['label' => 'شخص دیگری با این اثر مرتبط است (مترجم، تقدیم‌شده به، به سفارش، مصحح…)', 'target' => 'person', 'target_optional' => true, 'action' => 'افزودن شخص مرتبط با اثر', 'apply' => 'person_role'],
            // remove: the reviewer picks the row to delete; role, person or name only point at it
            'person_role_removed' => ['label' => 'یکی از اشخاص مرتبط با این اثر نادرست ثبت شده است و باید حذف شود', 'target' => 'person', 'target_optional' => true, 'action' => 'حذف شخص مرتبط با اثر', 'apply' => 'person_role', 'remove' => true],
            'work_relation' => ['label' => 'این اثر با اثر دیگری رابطه دارد (شرح، حاشیه، ترجمه، تلخیص، نظم، تکمله، ردیه…)', 'target' => 'work', 'action' => 'ثبت رابطۀ دو اثر', 'apply' => 'record_relation'],
            // The other work has no record on the site (step 14.280): its title and author are the submitted text, and
            // the relation is a row of work_text_relations. no_direct: there is no second record for the panel's tools.
            'work_relation_text' => ['label' => 'این اثر با اثری رابطه دارد که در فهارس رکوردی ندارد (شرح، حاشیه، ترجمه…)', 'target' => null, 'action' => 'ثبت رابطه با اثری بیرون از فهارس', 'apply' => 'text_relation', 'needs_text' => true, 'no_direct' => true],
            'redundant' => ['label' => 'این رکورد زائد است (اثری وجود ندارد؛ مثلاً توضیح یا سرعنوانی که به‌اشتباه اثر ثبت شده)', 'target' => null, 'action' => 'حذف رکورد زائد', 'apply' => 'remove'],
            'conflated' => ['label' => 'دو اثر متفاوت در این رکورد خلط شده‌اند (برخی نسخه‌ها از اثر دیگری‌اند)', 'target' => null, 'action' => 'تفکیک اثر', 'apply' => 'split', 'needs_text' => true],
        ],
        'library' => [
            'duplicate' => ['label' => 'این کتابخانه تکراری است (همان کتابخانۀ دیگری است)', 'target' => 'library', 'action' => 'ادغام کتابخانۀ تکراری', 'apply' => 'merge_library'],
            'merged_into' => ['label' => 'این کتابخانه با کتابخانۀ دیگری ادغام شده و نسخه‌هایش اکنون آنجاست', 'target' => 'library', 'action' => 'ادغام در کتابخانۀ دیگر', 'apply' => 'merge_library'],
            'renamed' => ['label' => 'نام این کتابخانه تغییر کرده است', 'target' => null, 'action' => 'تغییر نام کتابخانه', 'apply' => 'rename_library', 'needs_text' => true],
        ],
        // The people of the source's volumes (step 14.58): a cataloger from the list, or only a name; the volume is
        // optional (all volumes), in payload.volume_id. See VolumeCatalogers.
        'source' => [
            'cataloger_is' => ['label' => 'فهرست‌نگار این فهرست (یا یکی از جلدهایش) شخص دیگری است', 'target' => 'cataloger', 'target_optional' => true, 'action' => 'تعیین فهرست‌نگار جلد', 'apply' => 'volume_cataloger', 'replace' => true],
            'cataloger_also' => ['label' => 'این فهرست (یا یکی از جلدهایش) فهرست‌نگار، همکار یا ناظر دیگری هم دارد', 'target' => 'cataloger', 'target_optional' => true, 'action' => 'افزودن فهرست‌نگار جلد', 'apply' => 'volume_cataloger'],
        ],
        'person' => [
            'duplicate' => ['label' => 'این شخص همان شخص دیگری است', 'target' => 'person', 'action' => 'ادغام اشخاص', 'apply' => 'merge_people'],
            'alias' => ['label' => 'نام یا شهرت دیگری از همین شخص', 'target' => null, 'action' => 'افزودن نام دیگر', 'apply' => 'alias', 'needs_text' => true],
            // A period of the person's life (item 139, step 14.219): the kind in payload.role, where, when and the source in
            // the text; the reviewer enters the place and the years on approval. no_direct: a reviewer adds one on the person's page.
            'period' => ['label' => 'دوره‌ای از زندگی این شخص (اقامت، تحصیل، تدریس، سفر، منصب)', 'target' => null, 'action' => 'ثبت دورۀ زندگی', 'apply' => 'person_period', 'needs_text' => true, 'no_direct' => true],
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
            'one_person' => ['label' => 'همۀ نسخه‌های این رکورد از یک نفرند', 'target' => null, 'action' => 'یک نفر بودن رکورد', 'apply' => 'queue', 'queue_only' => true],
        ],
        'work' => [
            'author_not' => ['label' => 'پدیدآور این اثر آن شخص نیست', 'target' => null, 'action' => 'رد پیوند پدیدآور', 'apply' => 'queue', 'queue_only' => true],
        ],
    ];

    public static function relation(string $type, ?string $key): ?array
    {
        $relation = self::RELATIONS[self::typeKey($type)][$key] ?? self::QUEUE_RELATIONS[self::typeKey($type)][$key] ?? null;

        return $relation ? ['key' => $key] + $relation : null;
    }

    /**
     * The relations a form offers for one record type, each with its options: the public suggestion form, the
     * reviewer's convert form and the admin's direct structural tools all read this, so a new relation reaches them all.
     *
     * @return array<int, array> each relation with its key and options (null when it has none)
     */
    public static function relationChoices(string $type): array
    {
        $type = self::typeKey($type);
        $choices = [];
        foreach (self::RELATIONS[$type] ?? [] as $key => $relation) {
            $choices[] = ['key' => $key] + $relation + ['options' => self::relationOptions($type, $relation)];
        }

        return $choices;
    }

    /** The options of a relation that needs one (a person's role in the copy, a kind of relation between records). */
    public static function relationOptions(string $type, array $relation): ?array
    {
        return match ($relation['apply'] ?? null) {
            'person_role' => self::personRoles($type),
            'record_relation' => RecordRelations::options($type),
            // the same kinds, the other work being the one the submitter names
            'text_relation' => array_map(fn ($label) => str_replace('اثر انتخاب‌شده', 'اثری که نام می‌برید', $label), RecordRelations::options($type)),
            'volume_cataloger' => VolumeCatalogers::ROLES,
            'person_period' => \App\Models\PersonPeriod::KINDS,
            default => null,
        };
    }

    /** The roles a person can have in a copy (owner, endower…) or a work (translator, dedicatee…), besides scribe and author. */
    public static function personRoles(string $type): array
    {
        return self::typeKey($type) === 'work' ? \App\Models\WorkPerson::ROLES : \App\Models\ManuscriptPerson::ROLES;
    }

    /**
     * A record's fields as the suggestion forms describe them to the browser: input type and the address field's
     * flags, with the dynamic fields of the record (source_entry_N, bibliography_page_*) when a record is given.
     *
     * @return array<string, array{name: string, label: string, input: string, withRow: bool, canBeAbsent: bool, unit: string}>
     */
    public static function fieldChoices(string $type, ?Model $record = null): array
    {
        $choices = [];
        foreach ($record ? self::recordFields($record) : self::fields($type) as $name => $def) {
            $choices[$name] = [
                'name' => $name,
                'label' => $def['label'],
                'input' => $def['input'],
                'withRow' => ! empty($def['with_row']),
                'canBeAbsent' => ! empty($def['can_be_absent']),
                'unit' => $def['unit'] ?? 'ص',
                'clearable' => self::clearable($def),
                'pair' => $def['pair']['label'] ?? null,
            ];
            // What the record holds now, so a reviewer who converts a suggestion sees what it would replace
            if ($record) {
                $choices[$name]['current'] = self::currentValue($record, ['name' => $name] + $def);
                $choices[$name]['pairCurrent'] = self::pairCurrent($record, ['name' => $name] + $def);
            }
        }

        return $choices;
    }

    private const FLAG_LABELS = [
        'is_autograph' => 'اصل نسخه (دستخط مؤلف)',
        'has_author_marginalia' => 'حواشی به خط مؤلف',
        'corrected_by_author' => 'مقابله یا تصحیح به دست مؤلف',
        'collated_with_author_copy' => 'مقابله با نسخۀ مؤلف',
        'copied_from_author_copy' => 'کتابت از روی نسخۀ مؤلف',
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
            'source' => self::sourceFields(),
            default => [],
        };
    }

    public static function field(string $type, ?string $name): ?array
    {
        if ($name === null) {
            return null;
        }
        $field = self::fields($type)[$name] ?? match (self::typeKey($type)) {
            'manuscript' => self::sourceEntryField($name) ?? self::noteField($name) ?? self::collectionField($name),
            'work' => self::bibliographyPageField($name) ?? self::altTitleField($name),
            'person' => self::aliasField($name),
            default => null,
        };

        return $field ? ['name' => $name] + $field : null;
    }

    /**
     * A field of this very record, or null: an address field must be one of the record's own, its entry in another
     * source or a page its bibliography cites. Used by the public form and by the reviewer's convert form.
     */
    public static function fieldOf(Model $record, ?string $name): ?array
    {
        $field = self::field($record::class, $name);
        // one of the record's other titles, or the field that adds one: the record's own, with its title in the label
        if ($field && (isset($field['alt_title']) || isset($field['alias']))) {
            $own = self::recordFields($record)[$field['name']] ?? null;

            return $own ? ['name' => $field['name']] + $own : null;
        }
        if (! $field || (isset($field['entry_id']) && ! SourceEntry::whereKey($field['entry_id'])->where('citable_type', $record::class)->where('citable_id', $record->id)->exists())
            || (isset($field['bibliography']) && ! array_key_exists($field['name'], self::recordFields($record)))
            // a note of this very copy, or a new one of a type the copy can take
            || ((isset($field['note_id']) || isset($field['new_note_type']) || isset($field['note_type_of'])) && ! array_key_exists($field['name'], self::recordFields($record)))
            // a field of the copy's «مجموعه»: the copy is a treatise of one
            || (isset($field['collection']) && ! ($record instanceof Manuscript && $record->boundCollection()))) {
            return null;
        }

        return $field;
    }

    /**
     * The submitted value in its stored form: an address is written as «ج 2، ص 7» whatever the submitter typed.
     * Throws ValidationException (suggested_value) when an address has no volume and page.
     */
    public static function submittedValue(array $field, ?string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
        // one of the field's own values (step 14.269)
        if ($field['input'] === 'choice' && ! in_array($value, $field['options'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['suggested_value' => 'یکی از گزینه‌های فهرست را برگزینید.']);
        }
        if ($field['input'] !== 'catalog_page' || (! empty($field['can_be_absent']) && $value === self::ABSENT)) {
            return $value;
        }
        $page = self::parseCatalogPage($value);
        if (! $page) {
            throw \Illuminate\Validation\ValidationException::withMessages(['suggested_value' => 'شمارۀ جلد و صفحه را بنویسید.']);
        }

        return self::formatCatalogPage($page['volume'], $page['page'], $page['row'], $field['unit'] ?? 'ص', isset($field['entry_id']) ? 'ش' : 'ردیف');
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

    /**
     * The record types with structural tools (backlog item 45): a source has relations of its own (its volumes'
     * catalogers), applied only through a reviewed suggestion.
     */
    public const STRUCTURE_TYPES = ['manuscript', 'work', 'person', 'library'];

    /** What a relation may point at: a record type, or a cataloger (step 14.58), which takes no suggestions itself. */
    public const TARGETS = ['cataloger' => Cataloger::class];

    public static function targetClass(?string $target): ?string
    {
        return $target === null ? null : (self::TARGETS[$target] ?? self::modelClass($target));
    }

    /** Short identification of a record for pickers and the reviewer desk. */
    public static function describe(Model $record): array
    {
        if ($record instanceof Cataloger) {
            return ['label' => $record->name, 'sub' => implode(' · ', array_filter([$record->life_years_text,
                $record->volumes_count ? \App\Support\Fa::num($record->volumes_count) . ' جلد فهرست' : null])), 'url' => route('catalogers.show', $record->getRouteKey()), 'id' => $record->id];
        }

        return match (self::typeKey($record::class)) {
            'manuscript' => [
                'label' => trim(($record->work?->primary_title ?? 'نسخه') . ' — ' . ($record->libraryRecord?->name ?? $record->library ?? '') . ' ' . ($record->shelfmark ?? '')),
                'sub' => implode(' · ', array_filter([$record->city, $record->scribe_name ? 'کاتب: ' . $record->scribe_name : null, $record->copy_date_raw])),
                'url' => route('manuscripts.show', $record),
            ],
            'work' => [
                'label' => $record->primary_title,
                'sub' => implode(' · ', array_filter([$record->author_name, $record->composition_date_raw, \App\Support\Fa::num($record->manuscripts_count) . ' نسخه'])),
                'url' => route('works.show', $record),
            ],
            'person' => [
                'label' => $record->name,
                'sub' => implode(' · ', array_filter([
                    \App\Http\Controllers\SuggestionController::lifeYears($record),
                    $record->works_count ? \App\Support\Fa::num($record->works_count) . ' اثر' : null,
                    $record->manuscripts_count ? \App\Support\Fa::num($record->manuscripts_count) . ' نسخه' : null,
                ])),
                'url' => route('people.show', $record),
            ],
            'library' => ['label' => $record->name, 'sub' => $record->city, 'url' => route('libraries.show', $record)],
            'source' => ['label' => $record->title, 'sub' => implode(' · ', array_filter([Source::KIND_LABELS[$record->kind] ?? null, $record->compiler])), 'url' => $record->code ? route('sources.show', $record->code) : route('sources.index')],
            default => ['label' => '#' . $record->getKey(), 'sub' => null, 'url' => null],
        } + ['id' => $record->getKey()];
    }

    public static function typeLabel(?string $type): string
    {
        if ($type === null) {
            return 'بازخورد سایت';
        }

        return match (self::typeKey($type) ?? $type) {
            'manuscript' => 'نسخه',
            'work' => 'اثر',
            'person' => 'شخص',
            'library' => 'کتابخانه',
            'cataloger' => 'فهرست‌نگار',
            'source' => 'منبع',
            default => class_basename($type),
        };
    }

    /** The address field of a manuscript's entry in another source (step 13.3). */
    public const SOURCE_ENTRY_PREFIX = 'source_entry_';

    /** The name that field had before step 13.3, by the manuscript_catalog_references row. */
    public const CATALOG_REFERENCE_PREFIX = 'catalog_reference_';

    /**
     * Whether a suggestion may ask for the field to be emptied: a yes/no flag has no empty state, a required field
     * may not be empty, and a copy missing from another source is said with «نیست» instead.
     */
    public static function clearable(array $field): bool
    {
        return $field['input'] !== 'boolean' && empty($field['required']) && empty($field['can_be_absent']);
    }

    /** Shown for a suggestion that asks for the field to be emptied (payload clear). */
    public const CLEAR_LABEL = '— این فیلد خالی شود —';

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
        if ($record instanceof Manuscript) {
            // each typed note of the copy is a field of its own, and a seal, an ownership note, an endowment or an
            // acquisition can be added (step 14.264), to a copy without a note too (step 14.318); the list of them
            // all is then no field, unless the page still shows it (Manuscript::keepsSealsList)
            foreach ($record->notes as $note) {
                $fields[self::NOTE_PREFIX . $note->id] = self::noteField(self::NOTE_PREFIX . $note->id, $note);
                // ...and so is its type (step 14.269)
                $fields[self::NOTE_TYPE_PREFIX . $note->id] = self::noteField(self::NOTE_TYPE_PREFIX . $note->id, $note);
            }
            $provenance = ! $record->keepsSealsList() || ! $record->ownership_and_seals_list;
            if ($provenance) {
                unset($fields['ownership_and_seals']);
            }
            // a note of any type can be added (step 14.269); one of provenance not beside the old list of them
            foreach (ManuscriptNote::GROUPS as $key => $group) {
                foreach ($key === 'provenance' && ! $provenance ? [] : $group['types'] as $type) {
                    if ($field = self::noteField(self::NEW_NOTE_PREFIX . $type)) {
                        $fields[self::NEW_NOTE_PREFIX . $type] = $field;
                    }
                }
            }
        }
        if ($record instanceof Manuscript && $record->boundCollection()) {
            // what is its «مجموعه»'s: corrected for the whole volume from any of its treatises' pages (step 14.330)
            foreach (array_keys(self::COLLECTION_FIELDS) as $column) {
                $fields[self::COLLECTION_PREFIX . $column] = self::collectionField(self::COLLECTION_PREFIX . $column);
            }
        }
        if ($record instanceof Person) {
            // each other name is a field of its own, and one more adds a name (step 14.299)
            foreach ((array) $record->aliases as $alias) {
                if (is_string($alias) && trim($alias) !== '') {
                    $fields[self::aliasName($alias)] = self::aliasField(self::aliasName($alias), trim($alias));
                }
            }
            $fields[self::ALIAS_NEW] = self::aliasField(self::ALIAS_NEW);
        }
        if ($record instanceof Work) {
            foreach (\App\Support\BibliographyLinks::viewerPages($record->sources()->bibliography()->pluck('citation_text')) as $vp) {
                $name = self::bibliographyPageName($vp);
                $fields[$name] = self::bibliographyPageField($name);
            }
            // each other title is a field of its own, and one more adds a title (step 14.286)
            foreach ((array) $record->alternative_titles as $item) {
                $title = is_array($item) ? trim((string) ($item['title'] ?? '')) : '';
                if ($title !== '') {
                    $fields[self::altTitleName($title)] = self::altTitleField(self::altTitleName($title), $title);
                }
            }
            $fields[self::ALT_TITLE_NEW] = self::altTitleField(self::ALT_TITLE_NEW);
        }

        return $fields;
    }

    public const ALIAS_PREFIX = 'alias_';

    /** The field that adds another name to a person. */
    public const ALIAS_NEW = 'alias_new';

    /** «alias_3f2a9c01de»: one of a person's other names, named by its text (as altTitleName). */
    public static function aliasName(string $alias): string
    {
        return self::ALIAS_PREFIX . substr(md5(trim($alias)), 0, 10);
    }

    /**
     * The field of one of a person's other names (step 14.299), or of the name to add: the name with its
     * transliteration beside it. Emptied, the name is removed. `alias` is the field's own name, or «new».
     */
    public static function aliasField(string $name, ?string $alias = null): ?array
    {
        $pair = ['label' => 'آوانگاری'];
        if ($name === self::ALIAS_NEW) {
            return ['label' => 'افزودن نام دیگر', 'input' => 'text', 'alias' => 'new', 'required' => true, 'pair' => $pair];
        }
        if (! preg_match('/^' . self::ALIAS_PREFIX . '[0-9a-f]{10}$/', $name)) {
            return null;
        }

        return ['label' => $alias !== null ? "نام دیگر «{$alias}»" : 'نام دیگر', 'input' => 'text', 'alias' => $name, 'pair' => $pair];
    }

    /** The other name of the person a field names, with its place in the list and its transliteration; null for the adding field. */
    public static function aliasItem(Model $record, array $field): ?array
    {
        foreach ($record->aliasItems() as $index => $item) {
            if (self::aliasName($item['name']) === ($field['alias'] ?? null)) {
                return ['index' => $index] + $item;
            }
        }

        return null;
    }

    public const ALT_TITLE_PREFIX = 'alternative_title_';

    /** The field that adds another title to a work. */
    public const ALT_TITLE_NEW = 'alternative_title_new';

    /** «alternative_title_3f2a9c01de»: one of a work's other titles, named by its text so that it survives a reorder. */
    public static function altTitleName(string $title): string
    {
        return self::ALT_TITLE_PREFIX . substr(md5(trim($title)), 0, 10);
    }

    /**
     * The field of one of a work's other titles (step 14.286), or of the title to add: its text with its
     * transliteration beside it. Emptied, the title is removed. `alt_title` is the field's own name, or «new».
     */
    public static function altTitleField(string $name, ?string $title = null): ?array
    {
        $pair = ['label' => 'آوانگاری'];
        if ($name === self::ALT_TITLE_NEW) {
            return ['label' => 'افزودن عنوان دیگر', 'input' => 'text', 'alt_title' => 'new', 'required' => true, 'pair' => $pair];
        }
        if (! preg_match('/^' . self::ALT_TITLE_PREFIX . '[0-9a-f]{10}$/', $name)) {
            return null;
        }

        return ['label' => $title !== null ? "عنوان دیگر «{$title}»" : 'عنوان دیگر', 'input' => 'text', 'alt_title' => $name, 'pair' => $pair];
    }

    /** The item of the work's other titles a field names, with its place in the list; null for the adding field. */
    public static function altTitleItem(Model $record, array $field): ?array
    {
        foreach (array_values((array) ($record->alternative_titles ?? [])) as $index => $item) {
            if (is_array($item) && isset($item['title']) && self::altTitleName((string) $item['title']) === ($field['alt_title'] ?? null)) {
                return ['index' => $index] + $item;
            }
        }

        return null;
    }

    /** The value that goes with the field's own (a title's transliteration) as the record has it now; null without one. */
    public static function pairCurrent(Model $record, array $field): ?string
    {
        if (! isset($field['pair'])) {
            return null;
        }
        if (isset($field['alt_title'])) {
            return self::altTitleItem($record, $field)['transliteration'] ?? null;
        }
        if (isset($field['alias'])) {
            return self::aliasItem($record, $field)['transliteration'] ?? null;
        }

        return $record->{$field['pair']['column']};
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

    /** One typed note of a copy, «note_{id}», and a new note of a type, «note_new_{type}» (steps 14.264, 14.269). */
    public const NOTE_PREFIX = 'note_';

    public const NEW_NOTE_PREFIX = 'note_new_';

    /** The type of one note, «note_type_{id}» (step 14.269) */
    public const NOTE_TYPE_PREFIX = 'note_type_';

    /** What the form calls a new note of a type, where «افزودن» and the type's short label do not say it */
    public const NEW_NOTE_LABELS = ['acquisition' => 'افزودن ورود به کتابخانه', 'annex' => 'افزودن یادداشت افزوده‌ها', 'cataloguer' => 'افزودن یادداشت فهرست‌نگار',
        'contents' => 'افزودن یادداشت محتوا', 'codicology' => 'افزودن یادداشت نسخه‌شناسی', 'transcription' => 'افزودن یادداشت کتابت'];

    /** «افزودن مهر»; a note of no type («other») is not added, a type is always chosen */
    public static function newNoteLabel(string $type): ?string
    {
        return $type === 'other' || ! isset(ManuscriptNote::TYPES[$type]) ? null : (self::NEW_NOTE_LABELS[$type] ?? 'افزودن ' . ManuscriptNote::TYPES[$type]);
    }

    /**
     * The field of one note of a copy: its text, which an emptied value removes; or of a note to add, by its type.
     */
    public static function noteField(string $name, ?ManuscriptNote $note = null): ?array
    {
        if (str_starts_with($name, self::NEW_NOTE_PREFIX)) {
            $type = substr($name, strlen(self::NEW_NOTE_PREFIX));

            return ($label = self::newNoteLabel($type)) ? ['label' => $label, 'input' => 'textarea', 'new_note_type' => $type, 'required' => true] : null;
        }
        if (str_starts_with($name, self::NOTE_TYPE_PREFIX)) {
            $note ??= ctype_digit($id = substr($name, strlen(self::NOTE_TYPE_PREFIX))) ? ManuscriptNote::find((int) $id) : null;

            // one of the types, by its label; «other» only where the note has it
            return $note ? ['label' => 'نوع یادداشت «' . \Illuminate\Support\Str::limit($note->text, 40) . '»', 'input' => 'choice',
                'options' => array_values(array_diff_key(ManuscriptNote::TYPES, $note->type === 'other' ? [] : ['other' => 1])), 'note_type_of' => $note->id, 'required' => true] : null;
        }
        if (! $note && preg_match('/^' . self::NOTE_PREFIX . '(\d+)$/', $name, $m)) {
            $note = ManuscriptNote::find((int) $m[1]);
        }

        return $note ? ['label' => 'یادداشت ' . (ManuscriptNote::TYPES[$note->type] ?? $note->type), 'input' => 'textarea', 'note_id' => $note->id] : null;
    }

    public const COLLECTION_PREFIX = 'collection_';

    /**
     * The fields of a copy's «مجموعه» (backlog item 163, step 14.330), named collection_{column} on the copy: the
     * suggestion is made on a treatise's page and the approved value is written to the collection's record as an
     * editor's (SuggestionApplier::applyCollection), which the derivation from the treatises then leaves alone.
     */
    public const COLLECTION_FIELDS = [
        'binding' => ['label' => 'جلد مجموعه', 'input' => 'textarea'],
        'dimensions' => ['label' => 'اندازۀ مجموعه', 'input' => 'text'],
        // the leaves of the whole volume, which no treatise's entry gives: a library's catalogue or an editor does (step 14.331)
        'folios' => ['label' => 'شمار برگ کل مجموعه', 'input' => 'integer'],
        'paper' => ['label' => 'کاغذ مجموعه', 'input' => 'textarea'],
        'script_names' => ['label' => 'نوع خط مجموعه (خط همۀ رساله‌هایی که خودشان خطی ندارند)', 'input' => 'text'],
        'lines' => ['label' => 'تعداد سطر مجموعه (سطر همۀ رساله‌هایی که خودشان شماری ندارند)', 'input' => 'integer'],
    ];

    /** «collection_binding»: a field of the collection the copy is a treatise of. */
    public static function collectionField(string $name): ?array
    {
        $column = str_starts_with($name, self::COLLECTION_PREFIX) ? substr($name, strlen(self::COLLECTION_PREFIX)) : null;

        return isset(self::COLLECTION_FIELDS[$column]) ? self::COLLECTION_FIELDS[$column] + ['collection' => $column] : null;
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
        // the number the source gives at that place: a copy's row under its work in Fankha («ردیف»), an entry's printed
        // number in another source («ش»), step 14.405
        $parsed = ['volume' => $read('ج'), 'page' => $read('ص|ستون'), 'row' => $read('ردیف|شمارۀ|شماره|ش')];

        return $parsed['volume'] && $parsed['page'] ? $parsed : null;
    }

    public static function formatCatalogPage(?int $volume, ?int $page, ?int $row = null, string $unit = 'ص', string $number = 'ردیف'): ?string
    {
        if (! $volume || ! $page) {
            return null;
        }

        return \App\Support\Fa::digits("ج {$volume}، {$unit} {$page}" . ($row ? "، {$number} {$row}" : ''));
    }

    /** The field's current value as plain text, for the suggestion form and the reviewer's comparison. */
    public static function currentValue(Model $record, array $field): ?string
    {
        $name = $field['name'];

        if (isset($field['alt_title'])) {
            return self::altTitleItem($record, $field)['title'] ?? null;
        }
        if (isset($field['alias'])) {
            return self::aliasItem($record, $field)['name'] ?? null;
        }
        if (isset($field['note_type_of'])) {
            return ManuscriptNote::TYPES[ManuscriptNote::find($field['note_type_of'])?->type] ?? null;
        }
        if (isset($field['note_id']) || isset($field['new_note_type'])) {
            return isset($field['note_id']) ? ManuscriptNote::find($field['note_id'])?->text : null;
        }
        if (isset($field['collection'])) {
            $value = $record instanceof Manuscript ? $record->boundCollection()?->{$field['collection']} : null;

            return $value === null || $value === '' ? null : (string) $value;
        }

        // «2–7», «34پ–43ر»: both ends of the copy's place in its volume are one field
        if (! empty($field['leaf_range'])) {
            return $record->range_from === null ? null : ($record->range_from === $record->range_to ? $record->range_from : $record->range_from . '–' . $record->range_to);
        }

        $value = match ($field['input']) {
            'boolean' => $record->{$name} ? 'بله' : 'خیر',
            'quote' => implode("\n\n", array_column(self::metaList($record, $field['meta_key']), 'text')) ?: $record->{$name},
            'list' => implode("\n", self::listItems($record, $field)),
            'catalog_page' => match (true) {
                isset($field['entry_id']) => (fn ($e) => $e ? self::formatCatalogPage($e->volumeNumber(), $e->page_start, $e->entry_number, $field['unit'] ?? 'ص', 'ش') : null)(SourceEntry::find($field['entry_id'])),
                isset($field['bibliography']) => self::formatCatalogPage($field['bibliography']['volume'], $field['bibliography']['page'], null, $field['unit']),
                // the place is the record's Fankha entry's (step 14.399, backlog item 170); a copy's row is its entry_number
                default => (function () use ($record, $field) {
                    $held = \App\Sources\RecordEntries::fankhaOf($record);
                    $of = fn (string $column) => $held[$column === 'sequence_number' ? 'entry_number' : $column] ?? null;

                    return self::formatCatalogPage($of($field['columns'][0]), $of($field['columns'][1]), isset($field['columns'][2]) ? $of($field['columns'][2]) : null);
                })(),
            },
            // a copy whose scribe is only linked has no name text of its own: the linked person's name stands
            'person' => $record->{$name} ?: Person::find($record->{$field['id_column']})?->name,
            default => $record->{$name},
        };

        // An autograph copy: its scribe is the author, as the record page says
        if ($field['input'] === 'person' && ! empty($field['autograph_column']) && $record->{$field['autograph_column']}) {
            return 'دستخط مؤلف' . ($value ? " ({$value})" : '');
        }

        // «بی‌کاتب» / «بی‌تاریخ»: the catalog states the copy has none
        if (($value === null || $value === '') && ! empty($field['bit_column']) && $record->{$field['bit_column']}) {
            return $field['bit_label'];
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @return string[] */
    public static function listItems(Model $record, array $field): array
    {
        // a work's bibliography is its entries' (step 14.399, backlog item 170)
        $items = isset($field['meta_key']) ? self::metaList($record, $field['meta_key'])
            : ($record instanceof \App\Models\Work && $field['name'] === 'bibliography' ? \App\Sources\RecordEntries::bibliographyOf($record) : (array) ($record->{$field['name']} ?? []));
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
            'scribe_name' => ['label' => 'کاتب', 'input' => 'person', 'id_column' => 'scribe_id', 'role' => 'scribe', 'bit_column' => 'is_bika', 'bit_label' => 'بی‌کاتب', 'autograph_column' => 'is_autograph'],
            'copy_date_raw' => ['label' => 'تاریخ کتابت', 'input' => 'hijri_date', 'year_column' => 'copy_date_hijri_year', 'bit_column' => 'is_bita', 'bit_label' => 'بی‌تاریخ'],
            'copy_place' => ['label' => 'محل کتابت', 'input' => 'text'],
            'commissioned_by' => ['label' => 'سفارش‌دهندۀ کتابت', 'input' => 'text'],
            'shelfmark' => ['label' => 'شمارۀ نسخه', 'input' => 'text'],
            'script_names' => ['label' => 'نوع خط', 'input' => 'text'],
            'script_style' => ['label' => 'کیفیت خط', 'input' => 'text'],
            'folios' => ['label' => 'تعداد برگ', 'input' => 'integer'],
            // the page count and the copy's place in its volume, which only the Fankha text filled (step 14.244)
            'pages' => ['label' => 'تعداد صفحه', 'input' => 'integer'],
            'range_from' => ['label' => 'جای نسخه در مجموعه (برگ یا صفحه؛ مثلاً ۲–۷ یا ۳۴پ–۴۳ر)', 'input' => 'text', 'leaf_range' => true],
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
            // the title is corrected with its transliteration, in one suggestion (step 14.286)
            'primary_title' => ['label' => 'عنوان اثر', 'input' => 'text', 'mirror' => ['clean_title'], 'required' => true,
                'pair' => ['label' => 'آوانگاری عنوان', 'column' => 'transliteration']],
            'alternative_titles' => ['label' => 'عناوین دیگر', 'input' => 'list', 'item_key' => 'title'],
            'transliteration' => ['label' => 'آوانگاری عنوان', 'input' => 'text'],
            'author_name' => ['label' => 'پدیدآور', 'input' => 'person', 'id_column' => 'author_id', 'role' => 'author'],
            'composition_date_raw' => ['label' => 'تاریخ تألیف', 'input' => 'hijri_date', 'year_column' => 'composition_year_hijri'],
            'composition_place' => ['label' => 'محل تألیف', 'input' => 'text'],
            // the wording and the links to the site's subjects and languages change together (step 14.415, WorkTerms)
            'subject_summary' => ['label' => 'موضوع', 'input' => 'text', 'terms' => true],
            'language_summary' => ['label' => 'زبان', 'input' => 'text', 'terms' => true],
            'work_form' => ['label' => 'قالب (نثر، نظم و…)', 'input' => 'text'],
            'dedication' => ['label' => 'اهدا / به درخواست', 'input' => 'text'],
            'description' => ['label' => 'معرفی اثر', 'input' => 'textarea'],
            'incipit_text' => ['label' => 'آغاز اثر', 'input' => 'textarea'],
            'explicit_text' => ['label' => 'انجام اثر', 'input' => 'textarea'],
            'print_info' => ['label' => 'چاپ‌ها و سوابق اثر', 'input' => 'textarea'],
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
            'century_hijri' => ['label' => 'سده (قمری)', 'input' => 'integer', 'min' => 1, 'max' => 15],
            // the first century of a life in two centuries, for a person of no birth year (step 14.247)
            'century_from' => ['label' => 'نخستین سدۀ زندگی (قمری؛ برای کسی که در دو سده زیسته و سال تولدش دانسته نیست، مثلاً ۹ برای «سده‌های ۹ و ۱۰»)', 'input' => 'integer', 'min' => 1, 'max' => 15, 'century_from' => true],
            'birth_year_hijri' => ['label' => 'سال تولد (قمری)', 'input' => 'integer', 'min' => 1, 'max' => HijriYear::MAX],
            'death_year_hijri' => ['label' => 'سال وفات (قمری)', 'input' => 'integer', 'min' => 1, 'max' => HijriYear::MAX],
            'death_year_gregorian' => ['label' => 'سال وفات (میلادی)', 'input' => 'integer', 'min' => 500, 'max' => 2100],
            // the catalogue's wording of the life, which the death year and range are read from (step 14.245)
            'life_dates_raw' => ['label' => 'تاریخ زندگی به شیوۀ فهرست (مثلاً «۸۱۷ - ۸۹۸ قمری»، «- ۹۴۰ ؟ قمری»، «قرن ۱۰»، یا سالی که زنده بوده: «۹۳۰ قمری»)', 'input' => 'text', 'life_dates' => true],
            'bio_notes' => ['label' => 'زندگی‌نامۀ کوتاه', 'input' => 'textarea'],
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

    /** A source's own columns (step 14.52); the catalogers of each volume are linked records, suggested in general. */
    private static function sourceFields(): array
    {
        return [
            'title' => ['label' => 'عنوان منبع', 'input' => 'text', 'required' => true],
            'compiler' => ['label' => 'پدیدآورنده (مؤلف یا فهرست‌نگار)', 'input' => 'text'],
            'publication_place' => ['label' => 'محل نشر', 'input' => 'text'],
            'publisher' => ['label' => 'ناشر', 'input' => 'text'],
            'publication_year' => ['label' => 'سال نشر', 'input' => 'text'],
            'description' => ['label' => 'معرفی منبع', 'input' => 'textarea'],
        ];
    }
}
