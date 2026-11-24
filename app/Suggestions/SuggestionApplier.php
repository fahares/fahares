<?php

namespace App\Suggestions;

use App\Helpers\TextNormalizer;
use App\Models\Manuscript;
use App\Models\ManuscriptNote;
use App\Models\SourceEntry;
use App\Models\Person;
use App\Sources\LegacyMirror;
use App\Support\HijriYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes an approved field suggestion to its record, following the field's input type
 * in SuggestionRegistry. Runs inside the reviewer's transaction.
 */
class SuggestionApplier
{
    /** clear: no one is named any more, the name, its link and (for a scribe) the autograph mark go */
    public const PERSON_ACTIONS = ['link', 'create', 'text', 'clear'];

    /**
     * @param  array  $input  applied_value, plus hijri_year (hijri_date) or person_action/person_id (person)
     * @return array{old: array, new: array, resolution: array}
     */
    public function apply(Model $record, array $field, array $input): array
    {
        $value = $this->clean($input['applied_value'] ?? null);
        $name = $field['name'];
        $resolution = [];
        $previousPersonId = null;

        if ($value === null && ! empty($field['required'])) {
            throw new InvalidArgumentException('این فیلد نمی‌تواند خالی بماند.');
        }

        // An entry in another source lives in its own table: written there, and the record itself is left as it is
        if ($field['input'] === 'catalog_page' && isset($field['entry_id'])) {
            return $this->applySourceEntry($record, $field, $value);
        }

        // A copy's typed note lives in its own table too
        if (isset($field['note_id']) || isset($field['new_note_type']) || isset($field['note_type_of'])) {
            $result = $this->applyNote($record, $field, $value);
            // the search document holds the types of the copy's notes (step 14.269)
            $record->load('notes')->searchable();

            return $result;
        }

        // A field of the copy's «مجموعه» lives in the collection's record (step 14.330)
        if (isset($field['collection'])) {
            return $this->applyCollection($record, $field, $value, ($input['collection_basis'] ?? null) === 'catalogue' ? 'catalogue' : 'editor');
        }

        switch ($field['input']) {
            case 'catalog_page':
                $page = SuggestionRegistry::parseCatalogPage($value);
                if (! $page) {
                    throw new InvalidArgumentException('نشانی را به شکل «ج ۲، ص ۷، ردیف ۳» بنویسید.');
                }
                if (isset($field['bibliography'])) {
                    // a page the bibliography cites: the citation is rewritten where it stands in the entries
                    $entries = \App\Support\BibliographyLinks::rewriteCitation((array) ($record->bibliography ?? []), $field['bibliography'], $page['volume'], $page['page']);
                    if ($entries === null) {
                        throw new InvalidArgumentException('این ارجاع در متن مآخذ خودکار بازنویسی نشد. آن را در فیلد «مآخذ کتاب‌شناسی» دستی اصلاح کنید.');
                    }
                    $record->bibliography = $entries;
                    break;
                }
                [$volumeColumn, $pageColumn] = $field['columns'];
                $rowColumn = $field['columns'][2] ?? null;
                // the entry keeps its length: its last page moves with its first
                if (($endColumn = $field['end_column'] ?? null) && $record->{$endColumn} && $record->{$pageColumn}) {
                    $record->{$endColumn} = $page['page'] + max(0, $record->{$endColumn} - $record->{$pageColumn});
                }
                $record->{$volumeColumn} = $page['volume'];
                $record->{$pageColumn} = $page['page'];
                if ($rowColumn && $page['row'] !== null) {
                    $record->{$rowColumn} = $page['row'];
                }
                break;

            case 'integer':
                if ($value !== null && ! ctype_digit($value = $this->latinDigits($value))) {
                    throw new InvalidArgumentException('مقدار این فیلد باید عدد صحیح باشد.');
                }
                if ($value !== null && (isset($field['min']) && (int) $value < $field['min'] || isset($field['max']) && (int) $value > $field['max'])) {
                    throw new InvalidArgumentException("مقدار باید بین {$field['min']} و {$field['max']} باشد.");
                }
                // The first century of the life stands only for a person of no birth year, and never after the
                // person's century; the same as that century it says one century, which the model keeps as null (step 14.247)
                if (! empty($field['century_from'])) {
                    if ($record->birth_year_hijri) {
                        throw new InvalidArgumentException('این شخص سال تولد دارد و نخستین سدۀ زندگی از آن به دست می‌آید. اگر نادرست است، سال تولد را اصلاح کنید.');
                    }
                    if ($value !== null && ! $record->century_hijri) {
                        throw new InvalidArgumentException('این شخص سده ندارد. نخست فیلد «سده (قمری)» را ثبت کنید.');
                    }
                    if ($value !== null && (int) $value > $record->century_hijri) {
                        throw new InvalidArgumentException('نخستین سدۀ زندگی نمی‌تواند پس از سدۀ شخص (سدۀ ' . \App\Support\Fa::digits((string) $record->century_hijri) . ') باشد.');
                    }
                }
                // A death year taken away must leave the life dates' wording too, or the model reads it back from
                // there and the approval changes nothing (step 14.293; suggestion 598)
                if ($record instanceof Person && $name === 'death_year_hijri' && $value === null && $record->death_year_hijri !== null) {
                    $this->dropDeathFromLifeDates($record);
                }
                $record->{$name} = $value === null ? null : (int) $value;
                break;

            case 'boolean':
                $record->{$name} = in_array($value, ['1', 'true', 'بله'], true);
                break;

            case 'hijri_date':
                $year = $input['hijri_year'] ?? null;
                $year = $year === null || $year === '' ? null : (int) $year;
                if ($year !== null && ($year < HijriYear::MIN || $year > HijriYear::MAX)) {
                    throw new InvalidArgumentException('سال هجری خارج از بازۀ مجاز است.');
                }
                $record->{$name} = $value;
                // Set explicitly so the model's saving hook keeps the reviewer's year
                $record->{$field['year_column']} = $year;
                if ($value !== null && ! empty($field['bit_column'])) {
                    $record->{$field['bit_column']} = false;
                }
                $resolution['hijri_year'] = $year;
                break;

            case 'quote':
                $record->{$name} = $value;
                $meta = $record->metadata ?? [];
                $meta[$field['meta_key']] = $value === null ? [] : [['label' => null, 'text' => $value]];
                $record->metadata = $meta;
                break;

            case 'list':
                $items = $value === null ? [] : array_values(array_filter(array_map('trim', preg_split('/\R/u', $value)), 'strlen'));
                if (isset($field['meta_key'])) {
                    $meta = $record->metadata ?? [];
                    $meta[$field['meta_key']] = $items;
                    $record->metadata = $meta;
                } else {
                    $record->{$name} = $this->listColumn((array) ($record->{$name} ?? []), $items, $field['item_key'] ?? null) ?: null;
                }
                break;

            case 'person':
                $previousPersonId = $record->{$field['id_column']};
                if (($input['person_action'] ?? null) === 'clear') {
                    $value = null;
                }
                $resolution = $this->resolvePerson($record, $field, $value, $input);
                $record->{$name} = $value;
                if ($value !== null && ! empty($field['bit_column'])) {
                    $record->{$field['bit_column']} = false;
                }
                break;

            default:
                if (isset($field['alt_title'])) {
                    $resolution = $this->altTitle($record, $field, $value, $input);
                    break;
                }
                if (isset($field['alias'])) {
                    $resolution = $this->otherName($record, $field, $value, $input);
                    break;
                }
                if (! empty($field['leaf_range'])) {
                    $range = $value === null ? [null, null, null, null] : \App\Support\CopyExtent::parseRange($value);
                    if ($range === null) {
                        throw new InvalidArgumentException('جای نسخه را به شکل «۲–۷» یا «۳۴پ–۴۳ر» بنویسید.');
                    }
                    [$record->range_from, $record->range_to, $record->range_from_number, $record->range_to_number] = $range;
                    break;
                }
                // The death year the old wording gave goes with it, and its Gregorian year too; the model's saving hook
                // then reads the new wording's death year, range and century (step 14.245)
                if (! empty($field['life_dates']) && $record->death_year_hijri !== null) {
                    $was = \App\Models\Person::deathColumns($record->{$name}, null);
                    if ($was['death_from'] === $was['death_to'] && $was['death_from'] === $record->death_year_hijri) {
                        $record->death_year_hijri = null;
                        $record->death_year_gregorian = null;
                    }
                }
                $record->{$name} = $value;
        }

        foreach ($field['mirror'] ?? [] as $column) {
            $record->{$column} = $record->{$name};
        }
        // the value that goes with the field's own, when the form sent one: a title's transliteration (step 14.286)
        if (isset($field['pair']['column']) && array_key_exists('pair_value', $input)) {
            $record->{$field['pair']['column']} = $this->clean($input['pair_value']);
        }

        $old = array_intersect_key($record->getOriginal(), $record->getDirty());
        $record->save();
        $new = $record->getChanges();
        unset($new['updated_at']);

        if ($field['input'] === 'person') {
            $this->recount($previousPersonId, $record->{$field['id_column']});
        }

        return ['old' => $old, 'new' => $new, 'resolution' => $resolution];
    }

    /**
     * The corrected address of an entry in another source (backlog item 51, step 13.3): it becomes a manual, verified
     * entry at the new page, so the review badge goes away and catalogs:match-edition no longer rewrites it; a text
     * taken at the old page is marked as such until it is taken again. «نیست» rejects it, and the link leaves the
     * record page.
     */
    private function applySourceEntry(Model $record, array $field, ?string $value): array
    {
        $entry = SourceEntry::find($field['entry_id']);
        if (! $entry || $entry->citable_type !== $record::class || (int) $entry->citable_id !== (int) $record->getKey()) {
            throw new InvalidArgumentException('این ارجاع از این نسخه نیست.');
        }
        $reviewer = ['reviewed_by' => auth()->id(), 'reviewed_at' => now()];
        if ($value === SuggestionRegistry::ABSENT) {
            $entry->fill(['status' => SourceEntry::STATUS_REJECTED] + $reviewer);
        } else {
            $page = SuggestionRegistry::parseCatalogPage($value);
            if (! $page) {
                throw new InvalidArgumentException('نشانی را به شکل «ج ۲، ص ۷» بنویسید، یا «نیست» اگر نسخه در این فهرست نیامده است.');
            }
            // a machine match confirmed at its own page stays a match, now verified (backlog item 80)
            $same = $page['volume'] === $entry->volumeNumber() && $page['page'] === (int) $entry->page_start;
            $entry->fill($same ? ['status' => SourceEntry::STATUS_VERIFIED] + $reviewer : [
                'volume_number' => $page['volume'],
                'page_start' => $page['page'],
                // the entry still ends where it did when the new page falls inside it
                'page_end' => $entry->page_end && $page['page'] <= $entry->page_end && $page['page'] >= (int) $entry->page_start ? $entry->page_end : null,
                'origin' => SourceEntry::ORIGIN_MANUAL,
                'status' => SourceEntry::STATUS_VERIFIED,
            ] + $reviewer);
            if ($entry->textIsOutOfPlace() && $entry->text_status !== SourceEntry::TEXT_STALE) {
                $entry->text_status = SourceEntry::TEXT_STALE;
            }
        }
        $old = array_intersect_key($entry->getOriginal(), $entry->getDirty());
        $entry->save();
        $new = $entry->getChanges();
        unset($new['updated_at'], $new['reviewed_at']);
        LegacyMirror::reference($entry);

        // The audit log entry is the manuscript's; the entry's before and after go with it
        return ['old' => ['source_entry' => ['id' => $entry->id] + $old], 'new' => ['source_entry' => ['id' => $entry->id] + $new], 'resolution' => ['entry_id' => $entry->id]];
    }

    /**
     * A copy's typed note (step 14.264): its text is corrected, or with an emptied value the note is removed, or a
     * new note is added after the copy's last, or the note's type is changed (step 14.269). The note becomes an editor's, and the audit log's
     * «notes» key keeps the copy's notes from fahares:import-copy-notes from then on.
     */
    private function applyNote(Model $record, array $field, ?string $value): array
    {
        if (isset($field['new_note_type'])) {
            $note = ManuscriptNote::create(['manuscript_id' => $record->getKey(), 'position' => (int) ManuscriptNote::where('manuscript_id', $record->getKey())->max('position') + 1,
                'type' => $field['new_note_type'], 'text' => $value, 'typed_by' => 'editor']);

            return ['old' => ['notes' => []], 'new' => ['notes' => ['id' => $note->id, 'type' => $note->type, 'text' => $note->text]], 'resolution' => ['note_id' => $note->id]];
        }
        $note = ManuscriptNote::find($field['note_id'] ?? $field['note_type_of']);
        if (! $note || (int) $note->manuscript_id !== (int) $record->getKey()) {
            throw new InvalidArgumentException('این یادداشت از این نسخه نیست.');
        }
        // the whole row, for the audit log to put back (step 14.318)
        $old = $note->only(['id', 'position', 'type', 'text', 'also', 'by_author', 'by_author_hedge', 'hedge', 'typed_by']);
        // The note's type, by its label or its key (step 14.269); a type it had as a second one is its second no more
        if (isset($field['note_type_of'])) {
            $type = isset(ManuscriptNote::TYPES[$value]) ? $value : array_search($value, ManuscriptNote::TYPES, true);
            if (! $type || ! in_array(ManuscriptNote::TYPES[$type], $field['options'], true)) {
                throw new InvalidArgumentException('نوع یادداشت باید یکی از نوع‌های فهرست باشد.');
            }
            $note->update(['type' => $type, 'typed_by' => 'editor', 'also' => array_values(array_diff((array) $note->also, [$type])) ?: null]);

            return ['old' => ['notes' => $old], 'new' => ['notes' => ['id' => $note->id, 'type' => $type]], 'resolution' => ['note_id' => $note->id]];
        }
        if ($value === null) {
            $note->delete();

            return ['old' => ['notes' => $old], 'new' => ['notes' => ['id' => $note->id, 'removed' => true]], 'resolution' => ['note_id' => $note->id]];
        }
        $note->update(['text' => $value, 'typed_by' => 'editor']);

        return ['old' => ['notes' => $old], 'new' => ['notes' => ['id' => $note->id, 'text' => $value]], 'resolution' => ['note_id' => $note->id]];
    }

    /**
     * A value of the copy's «مجموعه» (backlog item 163, step 14.330): written to the collection's record with the
     * basis «editor», so the derivation from the treatises leaves it alone; an emptied value gives the field back to
     * the derivation. The treatises that read the collection are found again and sent to the search index.
     */
    private function applyCollection(Model $record, array $field, ?string $value, string $from = 'editor'): array
    {
        $collection = $record instanceof Manuscript ? $record->boundCollection() : null;
        if (! $collection) {
            throw new InvalidArgumentException('این نسخه در مجموعه‌ای نیست.');
        }
        $column = $field['collection'];
        if ($column === 'lines' && $value !== null && (! ctype_digit($value = $this->latinDigits($value)) || (int) $value < 1 || (int) $value > 200)) {
            throw new InvalidArgumentException('شمار سطر باید عددی میان ۱ و ۲۰۰ باشد.');
        }
        if ($column === 'folios' && $value !== null && (! ctype_digit($value = $this->latinDigits($value)) || (int) $value < 1 || (int) $value > 65000)) {
            throw new InvalidArgumentException('شمار برگ باید عدد باشد.');
        }
        $columns = \App\Support\CollectionValues::columnsOf($column);
        $old = ['id' => $collection->id, 'field' => $column, 'basis' => $collection->basis[$column] ?? null] + $collection->only($columns);
        $basis = $collection->basis ?? [];
        if ($value === null) {
            unset($basis[$column]);
        } else {
            // «catalogue»: a library's own catalogue gave it and no reviewer read it (sources:read-copy-lines, step 14.334)
            $basis[$column] = $from;
        }
        // the hand's style and the lines' wording are the treatises': an editor's value stands alone
        $collection->forceFill(array_fill_keys($columns, null) + ['basis' => $basis ?: null])->forceFill([$column => in_array($column, ['lines', 'folios'], true) && $value !== null ? (int) $value : $value])->save();
        \App\Support\Collections::derive([$collection->id]);
        \App\Support\Collections::reindex([$collection->id]);
        $collection->refresh();
        $record->setRelation('collection', $collection);

        return ['old' => ['collection' => $old],
            'new' => ['collection' => ['id' => $collection->id, 'field' => $column, 'basis' => $collection->basis[$column] ?? null] + $collection->only($columns)],
            'resolution' => ['collection_id' => $collection->id]];
    }

    private function resolvePerson(Model $record, array $field, ?string $name, array $input): array
    {
        $action = $input['person_action'] ?? 'text';
        if (! in_array($action, self::PERSON_ACTIONS, true)) {
            throw new InvalidArgumentException('گزینۀ پیوند شخص نامعتبر است.');
        }

        if ($action === 'link') {
            $person = Person::find($input['person_id'] ?? null);
            if (! $person) {
                throw new InvalidArgumentException('شخص انتخاب‌شده پیدا نشد.');
            }
            $record->{$field['id_column']} = $person->id;

            return ['person_action' => 'link', 'person_id' => $person->id];
        }

        if ($action === 'create') {
            if ($name === null) {
                throw new InvalidArgumentException('برای ساخت شخص تازه، نام لازم است.');
            }
            $person = Person::create([
                'name' => $name,
                'normalized_name' => mb_substr(TextNormalizer::normalize($name), 0, 600),
                'slug' => 'person-' . Str::random(12),
                'is_author' => $field['role'] === 'author',
                'is_scribe' => $field['role'] === 'scribe',
            ]);
            $person->update(['slug' => (Str::slug($name, '-', null) ?: 'person') . '-' . $person->id]);
            $record->{$field['id_column']} = $person->id;

            return ['person_action' => 'create', 'person_id' => $person->id];
        }

        if ($action === 'clear') {
            $record->{$field['id_column']} = null;
            // the author's own hand would name the author as scribe
            if (! empty($field['autograph_column'])) {
                $record->{$field['autograph_column']} = false;
            }

            return ['person_action' => 'clear'];
        }

        return ['person_action' => 'text'];
    }

    /**
     * Rebuilds a JSON list column from one item per line. For object items (e.g. alternative titles)
     * an unchanged item keeps its other keys, such as its transliteration.
     */
    /**
     * One of a work's other titles (step 14.286): the adding field appends a title, a title's own field rewrites it,
     * and emptied removes it. The transliteration is written when the form sent one. The resolution names the field
     * the title has afterwards, so the suggestion stays with its title.
     */
    private function altTitle(Model $record, array $field, ?string $value, array $input): array
    {
        $items = array_values((array) ($record->alternative_titles ?? []));
        $pairGiven = array_key_exists('pair_value', $input);
        $pair = $pairGiven ? $this->clean($input['pair_value']) : null;
        $taken = fn (string $title, ?int $except = null) => trim((string) $record->primary_title) === $title
            || (($found = SuggestionRegistry::altTitleItem($record, ['alt_title' => SuggestionRegistry::altTitleName($title)])) && $found['index'] !== $except);

        if ($field['alt_title'] === 'new') {
            if ($taken((string) $value)) {
                throw new InvalidArgumentException('این عنوان هم‌اکنون در رکورد هست.');
            }
            $items[] = ['title' => $value] + ($pair !== null ? ['transliteration' => $pair] : []);
        } else {
            $item = SuggestionRegistry::altTitleItem($record, $field);
            if (! $item) {
                throw new InvalidArgumentException('این عنوان دیگر اکنون در رکورد نیست؛ شاید پیش‌تر ویرایش یا حذف شده است.');
            }
            if ($value === null) {
                array_splice($items, $item['index'], 1);
            } else {
                if ($taken($value, $item['index'])) {
                    throw new InvalidArgumentException('این عنوان هم‌اکنون در رکورد هست.');
                }
                $items[$item['index']]['title'] = $value;
                if ($pairGiven && $pair !== null) {
                    $items[$item['index']]['transliteration'] = $pair;
                } elseif ($pairGiven) {
                    unset($items[$item['index']]['transliteration']);
                }
            }
        }
        $record->alternative_titles = $items ?: null;

        return $value !== null ? ['field_name' => SuggestionRegistry::altTitleName($value)] : [];
    }

    /**
     * The death year is cleared: where the catalogue's life dates give that very year («- 940 ؟ ق», «817 - 898 قمری»),
     * the wording loses it («817 - قمری» keeps the birth year; with no birth year the wording goes), and the
     * Gregorian year with it. Where the wording would still give a year of death, the reviewer is told to correct
     * the life dates instead of being shown an approval that did nothing.
     */
    private function dropDeathFromLifeDates(Person $person): void
    {
        $text = $person->life_dates_raw;
        $given = Person::deathColumns($text, null);
        if ($given['death_from'] !== null && $given['death_from'] === $given['death_to'] && $given['death_from'] === (int) $person->death_year_hijri
            && Person::lifeCalendar($text) === null) {
            $birth = Person::yearBeforeDash($text);
            $text = $birth ? "{$birth} - قمری" : null;
        }
        $left = Person::deathColumns($text, null);
        if ($left['death_from'] !== null && $left['death_from'] === $left['death_to']) {
            throw new InvalidArgumentException('سال وفات این شخص از متن «تاریخ زندگی» («' . $person->life_dates_raw . '») خوانده می‌شود. نخست فیلد «تاریخ زندگی» را اصلاح کنید.');
        }
        $person->life_dates_raw = $text;
        $person->death_year_gregorian = null;
    }

    /**
     * One of a person's other names (step 14.299), as altTitle for a work's other titles: the adding field appends a
     * name, a name's own field rewrites it, and emptied removes it; the transliteration is written when the form
     * sent one. The names stay a list of strings; their transliterations are a map beside it.
     */
    private function otherName(Model $record, array $field, ?string $value, array $input): array
    {
        $names = array_values((array) ($record->aliases ?? []));
        $map = (array) ($record->alias_transliterations ?? []);
        $pairGiven = array_key_exists('pair_value', $input);
        $pair = $pairGiven ? $this->clean($input['pair_value']) : null;
        $taken = fn (string $name, ?int $except = null) => trim((string) $record->name) === $name
            || (($at = array_search($name, $names, true)) !== false && $at !== $except);

        if ($field['alias'] === 'new') {
            if ($taken((string) $value)) {
                throw new InvalidArgumentException('این نام هم‌اکنون در رکورد هست.');
            }
            $names[] = $value;
            $map = array_filter([$value => $pair] + $map, fn ($t) => $t !== null);
        } else {
            $item = SuggestionRegistry::aliasItem($record, $field);
            if (! $item) {
                throw new InvalidArgumentException('این نام دیگر اکنون در رکورد نیست؛ شاید پیش‌تر ویرایش یا حذف شده است.');
            }
            $before = $item['name'];
            if ($value === null) {
                array_splice($names, $item['index'], 1);
            } else {
                if ($taken($value, $item['index'])) {
                    throw new InvalidArgumentException('این نام هم‌اکنون در رکورد هست.');
                }
                $names[$item['index']] = $value;
                // the transliteration stays with the name unless the form sent another, or an empty one
                $kept = $pairGiven ? $pair : ($map[$before] ?? null);
                unset($map[$before]);
                if ($kept !== null) {
                    $map[$value] = $kept;
                }
            }
        }
        $record->aliases = $names ?: null;
        $record->alias_transliterations = $map ?: null;

        return $value !== null ? ['field_name' => SuggestionRegistry::aliasName($value)] : [];
    }

    private function listColumn(array $existing, array $lines, ?string $itemKey): array
    {
        if ($itemKey === null) {
            return $lines;
        }
        $byText = [];
        foreach ($existing as $item) {
            if (is_array($item) && isset($item[$itemKey])) {
                $byText[trim((string) $item[$itemKey])] = $item;
            }
        }

        // «عنوان | آوانگاری»: a line may bring the item's transliteration with it (step 14.286)
        return array_map(function ($line) use ($byText, $itemKey) {
            [$text, $transliteration] = array_pad(array_map('trim', explode('|', $line, 2)), 2, null);
            $item = $byText[$text] ?? [$itemKey => $text];

            if ($transliteration !== null && $transliteration !== '') {
                $item['transliteration'] = $transliteration;
            }

            return $item;
        }, $lines);
    }

    private function recount(?int $previousId, ?int $currentId): void
    {
        foreach (array_unique(array_filter([$previousId, $currentId])) as $id) {
            Person::find($id)?->recountContributions();
        }
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));

        return $value === '' ? null : $value;
    }

    private function latinDigits(string $value): string
    {
        return strtr($value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }
}
