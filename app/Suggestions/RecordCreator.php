<?php

namespace App\Suggestions;

use App\Helpers\TextNormalizer;
use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Sources\SourceEntrySync;
use App\Support\PersonNameMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * «شخص تازه» and «اثر تازه» (backlog item 161, step 14.381): a person's or a work's record made from the panel, as
 * ManuscriptCreator makes a copy's. Such a record stands in no volume of Fankha; where the knowledge came from is a
 * card of a source the site has (a manual entry, verified by its maker) or a line of the audit log.
 *
 * The person a work or copy form names and the site lacks is made here too (newPerson), so the form is not left.
 * Every record is checked for namesakes before it is made; the forms show them and make it only when told again.
 *
 * The audit log entry («create», new_values.panel) holds what was made, and undo() takes it back.
 */
class RecordCreator
{
    /** The people with this name or one of these other names, written with either letter forms. */
    public function personDuplicates(?string $name, array $aliases = []): Collection
    {
        $matcher = new PersonNameMatcher;
        $ids = [];
        foreach (array_filter([$name, ...$aliases], fn ($n) => is_string($n) && trim($n) !== '') as $n) {
            array_push($ids, ...$matcher->ids(PersonNameMatcher::clean($n)));
        }

        return $ids ? Person::whereIn('id', array_unique($ids))->orderByDesc('works_count')->orderByDesc('manuscripts_count')->limit(20)->get() : collect();
    }

    /**
     * The works with the same title: by the same author when one is given, else by anyone. A title is compared with
     * the primary, clean and other titles, written with either letter forms.
     */
    public function workDuplicates(?string $title, array $otherTitles = [], ?int $authorId = null): Collection
    {
        $wanted = array_values(array_unique(array_filter(array_map(fn ($t) => TextNormalizer::normalize(trim((string) $t)), [$title, ...$otherTitles]))));
        if (! $wanted) {
            return collect();
        }
        $query = Work::query()->select(['id', 'primary_title', 'clean_title', 'alternative_titles', 'author_id', 'author_name', 'manuscripts_count', 'slug']);
        if ($authorId) {
            // an author's works are few: every title of each is compared
            $works = $query->where(fn ($q) => $q->where('author_id', $authorId)
                ->orWhereIn('id', DB::table('work_people')->where('person_id', $authorId)->where('role', \App\Models\WorkPerson::AUTHOR)->select('work_id')))->get();
        } else {
            $raw = array_values(array_unique(array_filter(array_map('trim', [(string) $title, ...$otherTitles]))));
            $works = $query->where(fn ($q) => $q->whereIn('primary_title', [...$raw, ...$wanted])->orWhereIn('clean_title', [...$raw, ...$wanted]))->limit(50)->get();
        }

        return $works->filter(function (Work $w) use ($wanted) {
            $titles = [$w->primary_title, $w->clean_title];
            foreach ((array) $w->alternative_titles as $item) {
                $titles[] = is_array($item) ? ($item['title'] ?? null) : $item;
            }

            return (bool) array_intersect($wanted, array_map(fn ($t) => TextNormalizer::normalize((string) $t), array_filter($titles)));
        })->take(20)->values();
    }

    /**
     * @param  array  $data  the form's fields, validated (Admin\PersonController::create)
     */
    public function createPerson(array $data, ?int $userId = null, ?string $ip = null): Person
    {
        return DB::transaction(function () use ($data, $userId, $ip) {
            $person = $this->makePerson($data);
            $entry = $this->sourceEntry($person, $data, $userId);
            $person->refresh()->searchable();

            $this->log($person, ['person_id' => $person->id, 'person_ids' => [], 'source_entry_ids' => $entry ? [$entry->id] : []], $data, $userId, $ip,
                ['name', 'aliases', 'wrong_names', 'transliteration', 'birth_year_hijri', 'death_year_hijri', 'century_hijri', 'century_from', 'death_year_gregorian', 'bio_notes']);

            return $person;
        });
    }

    /**
     * @param  array  $data  the form's fields, validated (Admin\WorkController::rules)
     */
    public function createWork(array $data, ?int $userId = null, ?string $ip = null): Work
    {
        return DB::transaction(function () use ($data, $userId, $ip) {
            $title = self::text($data['title'] ?? null) ?? throw new InvalidArgumentException('عنوان اثر را بنویسید.');
            [$author, $made] = $this->contributor($data, 'author');
            $others = self::lines($data['alternative_titles'] ?? null);

            $work = Work::create([
                'primary_title' => mb_substr($title, 0, 255),
                'clean_title' => mb_substr($title, 0, 255),
                'slug' => 'work-' . Str::random(12),
                'alternative_titles' => $others ? array_map(fn ($t) => ['title' => $t], $others) : null,
                'transliteration' => self::text($data['transliteration'] ?? null),
                'author_id' => $author?->id,
                'author_name' => self::text($data['author_name'] ?? null) ?? $author?->name,
                'composition_date_raw' => self::text($data['composition_date_raw'] ?? null),
                'composition_place' => self::text($data['composition_place'] ?? null),
                'subject_summary' => self::text($data['subject_summary'] ?? null),
                'language_summary' => self::text($data['language_summary'] ?? null),
                'work_form' => self::text($data['work_form'] ?? null),
                'incipit_text' => self::text($data['incipit_text'] ?? null),
                'explicit_text' => self::text($data['explicit_text'] ?? null),
                'description' => self::text($data['description'] ?? null),
                'manuscripts_count' => 0,
                'catalog_id' => null,
                'volume_number' => null,
                'page_start' => null,
            ]);
            $work->update(['slug' => mb_substr(Str::slug($title, '-', null) ?: 'work', 0, 180) . '-' . $work->id]);
            $this->linkTerms($work);
            $entry = $this->sourceEntry($work, $data, $userId);
            $work->refresh()->searchable();

            $this->log($work, ['work_id' => $work->id, 'person_ids' => array_filter([$made]), 'source_entry_ids' => $entry ? [$entry->id] : []], $data, $userId, $ip,
                ['primary_title', 'alternative_titles', 'transliteration', 'author_id', 'author_name', 'composition_date_raw', 'composition_place',
                    'subject_summary', 'language_summary', 'work_form', 'incipit_text', 'explicit_text', 'description']);

            return $work;
        });
    }

    /**
     * The person a form names in its picker, or the one it makes for the name it gives (`{role}_new`), or none.
     * The caller has already shown the namesakes (personDuplicates) and been told to go on.
     *
     * @return array{0: ?Person, 1: ?int} the person, and its id when it was made here
     */
    public function contributor(array $data, string $role): array
    {
        if (filled($data["{$role}_id"] ?? null)) {
            return [Person::find($data["{$role}_id"]) ?? throw new InvalidArgumentException('شخصی با این شناسه پیدا نشد.'), null];
        }
        if (empty($data["{$role}_new"])) {
            return [null, null];
        }
        $name = self::text($data["{$role}_name"] ?? null) ?? throw new InvalidArgumentException('نام شخص تازه را بنویسید.');
        $person = $this->makePerson(['name' => $name, 'death_year_hijri' => $data["{$role}_death_year"] ?? null]);
        $person->searchable();

        return [$person, $person->id];
    }

    /** The person's row, from the fields the person forms share with the edit form (Admin\PersonController::update). */
    private function makePerson(array $data): Person
    {
        $name = self::text($data['name'] ?? null) ?? throw new InvalidArgumentException('نام شخص را بنویسید.');
        $name = mb_substr(PersonNameMatcher::clean($name), 0, 600);
        $person = Person::create([
            'name' => $name,
            // as the edit form writes it: the name without its vowel marks
            'normalized_name' => preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $name),
            'slug' => 'person-' . Str::random(12),
            'aliases' => self::lines(\App\Http\Controllers\Admin\PersonController::withoutTransliterations($data['aliases'] ?? null)),
            'alias_transliterations' => \App\Http\Controllers\Admin\PersonController::transliterations($data['aliases'] ?? null),
            'wrong_names' => self::lines($data['wrong_names'] ?? null),
            'transliteration' => self::text($data['transliteration'] ?? null),
            'birth_year_hijri' => filled($data['birth_year_hijri'] ?? null) ? (int) $data['birth_year_hijri'] : null,
            'death_year_hijri' => filled($data['death_year_hijri'] ?? null) ? (int) $data['death_year_hijri'] : null,
            // left out when empty, so the model derives it from the death year
            ...(filled($data['century_hijri'] ?? null) ? ['century_hijri' => (int) $data['century_hijri']] : []),
            ...(empty($data['birth_year_hijri']) && filled($data['century_from'] ?? null) ? ['century_from' => (int) $data['century_from']] : []),
            'death_year_gregorian' => filled($data['death_year_gregorian'] ?? null) ? (int) $data['death_year_gregorian'] : null,
            'bio_notes' => self::text($data['bio_notes'] ?? null),
            'works_count' => 0,
            'manuscripts_count' => 0,
        ]);
        $person->update(['slug' => (Str::slug($name, '-', null) ?: 'person') . '-' . $person->id]);

        return $person;
    }

    /**
     * The subjects and languages the summaries name, linked by name as the corpus commands link them
     * (AddCorpusWorksCommand::attachTerms); a name the site lacks stays in the summary only.
     *
     * @return string[] the names left unlinked
     */
    public function linkTerms(Work $work): array
    {
        $unlinked = [];
        foreach (['subject_summary' => ['subjects', 'subject_work', 'subject_id'], 'language_summary' => ['languages', 'language_work', 'language_id']] as $field => [$table, $pivot, $column]) {
            $ids = [];
            foreach (self::terms($work->{$field}) as $name) {
                $id = DB::table($table)->where('name', $name)->value('id');
                $id ? $ids[] = $id : $unlinked[] = $name;
            }
            foreach (array_unique($ids) as $id) {
                DB::table($pivot)->insertOrIgnore([$column => $id, 'work_id' => $work->id]);
            }
            self::recountTerms($table, $pivot, $column, $ids);
        }

        return $unlinked;
    }

    /** «فقه، اصول» → the names, split on either comma or «/». */
    public static function terms(?string $summary): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[،,\/]+/u', (string) $summary)), 'strlen')));
    }

    /** The works counts of these subjects or languages, and of the parent subjects, which count their children's works too. */
    private static function recountTerms(string $table, string $pivot, string $column, array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            DB::table($table)->where('id', $id)->update(['works_count' => DB::table($pivot)->where($column, $id)->count()]);
        }
        if ($table !== 'subjects' || ! $ids) {
            return;
        }
        foreach (DB::table('subjects')->whereIn('id', $ids)->whereNotNull('parent_id')->pluck('parent_id')->unique() as $parentId) {
            $family = DB::table('subjects')->where('parent_id', $parentId)->pluck('id')->push($parentId)->all();
            DB::table('subjects')->where('id', $parentId)->update(['works_count' => DB::table('subject_work')->whereIn('subject_id', $family)->distinct()->count('work_id')]);
        }
    }

    /** The record's place in a source the site has, as a card of its page: a manual entry, verified by its maker. */
    private function sourceEntry(Person|Work $record, array $data, ?int $userId): ?SourceEntry
    {
        if (blank($data['source_id'] ?? null)) {
            return null;
        }
        $source = Source::find($data['source_id']) ?? throw new InvalidArgumentException('منبعی با این شناسه پیدا نشد.');
        $place = SuggestionRegistry::parseCatalogPage($data['source_place'] ?? null)
            ?? throw new InvalidArgumentException('نشانی در منبع را به شکل «ج ۶، ص ۶۰۱» بنویسید.');

        return SourceEntry::create([
            'citable_type' => $record::class,
            'citable_id' => $record->id,
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

    private function log(Person|Work $record, array $made, array $data, ?int $userId, ?string $ip, array $columns): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'auditable_type' => $record::class,
            'auditable_id' => $record->id,
            'action' => 'create',
            'old_values' => ['created' => $made],
            // where the knowledge came from, in the editor's words: the record has no column for it
            'new_values' => ['panel' => true]
                + array_filter(['source_text' => self::text($data['source_text'] ?? null)])
                + array_filter($record->only($columns), fn ($v) => $v !== null && $v !== []),
            'ip_address' => $ip,
        ]);
    }

    /**
     * Takes back what createPerson() or createWork() made, from its audit log entry: a work while it has no copy,
     * a person while nothing names them.
     */
    public function undo(AuditLog $log): void
    {
        $made = $log->old_values['created'] ?? null;
        if (empty($log->new_values['panel']) || ! $made) {
            throw new InvalidArgumentException('این رکورد از پنل ساخته نشده است و بازگرداندنی نیست.');
        }
        if ($id = $made['work_id'] ?? null) {
            $work = Work::find($id) ?? throw new InvalidArgumentException("اثر #{$id} دیگر وجود ندارد.");
            if (Manuscript::where('work_id', $work->id)->exists()) {
                throw new InvalidArgumentException('این اثر اکنون نسخه دارد؛ نخست نسخه‌هایش را منتقل یا حذف کنید.');
            }
            SourceEntry::where('citable_type', Work::class)->where('citable_id', $work->id)->delete();
            app(RecordRemover::class)->remove($work);
        }
        if ($id = $made['person_id'] ?? null) {
            $this->removePerson(Person::find($id) ?? throw new InvalidArgumentException("شخص #{$id} دیگر وجود ندارد."), true);
        }
        $this->removePeople($made['person_ids'] ?? []);
    }

    /** The people a form made beside its record, once nothing names them any more; one that is named stays. */
    public function removePeople(array $ids): void
    {
        foreach (Person::whereIn('id', $ids)->get() as $person) {
            $this->removePerson($person, false);
        }
    }

    private function removePerson(Person $person, bool $strict): void
    {
        $remover = app(RecordRemover::class);
        if (! $strict && $remover->blockers($person)) {
            return;
        }
        SourceEntry::where('citable_type', Person::class)->where('citable_id', $person->id)->delete();
        $remover->remove($person);
    }

    /** @return string[]|null one item a line, without repeats */
    private static function lines(?string $text): ?array
    {
        $lines = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', (string) $text)), 'strlen')));

        return $lines ?: null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
