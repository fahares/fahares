<?php

namespace App\Suggestions;

use App\Models\EntityRedirect;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies the library suggestions: a duplicate record (or a library absorbed into another) is
 * folded into the one that is kept, and a renamed library takes its new name. The name that goes
 * stays among the library's other names (aliases), since older catalogs cite it. Both are undone
 * from the audit log with what these methods return.
 */
class LibraryMerger
{
    /**
     * Folds $source into $target and deletes it behind a permanent redirect. $fields holds the admin's choice for each
     * field the two libraries hold differently (MergeComparison); a field not chosen takes the default.
     */
    public function merge(Library $source, Library $target, ?array $fields = null): array
    {
        if ($source->is($target)) {
            throw new InvalidArgumentException('کتابخانه‌ی مبدأ و مقصد یکی است.');
        }
        $snapshot = $source->getAttributes();

        // Copies are grouped by the name and city text they carried, so an undo writes each back
        $copies = [];
        foreach (Manuscript::where('library_id', $source->id)->get(['id', 'library', 'city']) as $m) {
            $copies[($m->library ?? '') . '|' . ($m->city ?? '')][] = $m->id;
        }
        $volumes = $source->catalogVolumes()->pluck('id')->all();
        $suggestions = FieldSuggestion::where('suggestable_type', Library::class)->where('suggestable_id', $source->id)
            ->where('status', 'pending')->pluck('id')->all();
        $pointers = $this->pendingPointersTo($source);
        $formerLocations = $this->formerLocationsOf($source);

        $added = [];
        $aliases = (array) ($target->aliases ?? []);
        foreach (array_merge([$source->name, $source->full_name], (array) ($source->aliases ?? [])) as $name) {
            $name = trim((string) $name);
            if ($name !== '' && ! $target->knowsName($name) && ! in_array($name, $added, true)) {
                $aliases[] = $added[] = $name;
                $target->aliases = $aliases;
            }
        }
        // After the names, so a full name the kept library takes stays among its other names too
        $compared = MergeComparison::apply($source, $target, $fields);
        $target->save();

        Manuscript::where('library_id', $source->id)->update(['library_id' => $target->id, 'library' => $target->name, 'city' => $target->city]);
        $source->catalogVolumes()->update(['library_id' => $target->id]);
        FieldSuggestion::whereIn('id', $suggestions)->update(['suggestable_id' => $target->id]);
        $this->repoint($pointers, $target->id);
        $this->repointFormerLocations($formerLocations, $source->id, $target->id);

        EntityRedirect::recordRedirect('libraries', $source->id, $target->id);
        $source->delete();

        ManuscriptLocation::recountLibraries([$target->id]);
        Manuscript::whereIn('id', array_merge(...array_values($copies ?: [[]])))->searchable();

        return [
            'source_id' => $snapshot['id'],
            'source_snapshot' => $snapshot,
            'manuscripts' => $copies,
            'catalog_volume_ids' => $volumes,
            'suggestion_ids' => $suggestions,
            'pointer_ids' => $pointers,
            'former_location_ids' => $formerLocations,
            'fields' => $compared,
            'added_aliases' => $added,
        ];
    }

    /** Undoes merge() from the values it returned. */
    public function undoMerge(Library $target, array $old): Library
    {
        $snapshot = $old['source_snapshot'] ?? null;
        if (! $snapshot || ! ($sourceId = $old['source_id'] ?? null)) {
            throw new InvalidArgumentException('اطلاعات کتابخانه‌ی حذف‌شده در این لاگ ثبت نشده است.');
        }
        if (Library::find($sourceId)) {
            throw new InvalidArgumentException("کتابخانه‌ای با شناسه‌ی #{$sourceId} هم‌اکنون وجود دارد.");
        }
        if (Library::where('city', $snapshot['city'])->where('name', $snapshot['name'])->exists()) {
            throw new InvalidArgumentException("کتابخانه‌ی دیگری با نام «{$snapshot['name']}» در {$snapshot['city']} ثبت شده است.");
        }
        DB::table('libraries')->insert($snapshot);
        $source = Library::findOrFail($sourceId);

        $ids = [];
        foreach ((array) ($old['manuscripts'] ?? []) as $key => $group) {
            [$name, $city] = explode('|', $key, 2) + [1 => ''];
            Manuscript::whereIn('id', $group)->where('library_id', $target->id)
                ->update(['library_id' => $sourceId, 'library' => $name !== '' ? $name : null, 'city' => $city !== '' ? $city : null]);
            array_push($ids, ...$group);
        }
        DB::table('catalog_volumes')->whereIn('id', (array) ($old['catalog_volume_ids'] ?? []))->where('library_id', $target->id)->update(['library_id' => $sourceId]);
        FieldSuggestion::whereIn('id', (array) ($old['suggestion_ids'] ?? []))->where('suggestable_id', $target->id)->update(['suggestable_id' => $sourceId]);
        $this->repoint((array) ($old['pointer_ids'] ?? []), $sourceId);
        $this->repointFormerLocations((array) ($old['former_location_ids'] ?? []), $target->id, $sourceId);

        $target->aliases = array_values(array_diff((array) ($target->aliases ?? []), (array) ($old['added_aliases'] ?? []))) ?: null;
        // A merge of before backlog item 46 filled empty columns only
        foreach ((array) ($old['filled'] ?? []) as $column => $value) {
            if ($target->{$column} === $value) {
                $target->{$column} = null;
            }
        }
        MergeComparison::undo($target, $old['fields'] ?? []);
        $target->save();

        EntityRedirect::removeRedirect('libraries', (int) $sourceId);
        ManuscriptLocation::recountLibraries([$target->id, $sourceId]);
        Manuscript::whereIn('id', $ids)->searchable();

        return $source;
    }

    /**
     * Gives the library its new name; the former one is kept among its other names unless the
     * reviewer says it was only a misspelling. Returns old and new values for the audit log.
     */
    public function rename(Library $library, string $name, bool $keepFormer = true): array
    {
        $name = trim($name);
        if ($name === '' || $name === $library->name) {
            throw new InvalidArgumentException('نام تازه همان نام کنونی کتابخانه است.');
        }
        if ($other = Library::where('city', $library->city)->where('name', $name)->whereKeyNot($library->id)->first()) {
            throw new InvalidArgumentException("کتابخانه‌ی دیگری با این نام در همین شهر ثبت شده است (#{$other->id}). اگر همان است، ادغام را پیشنهاد کنید.");
        }

        $old = ['name' => $library->name, 'aliases' => $library->aliases];
        $aliases = array_values(array_filter((array) ($library->aliases ?? []), fn ($a) => \App\Helpers\TextNormalizer::normalize($a) !== \App\Helpers\TextNormalizer::normalize($name)));
        if ($keepFormer && ! in_array($library->name, $aliases, true)) {
            $aliases[] = $library->name;
        }
        $library->forceFill(['name' => $name, 'aliases' => $aliases ?: null])->save();
        $library->syncManuscriptCopies();

        return ['old' => $old, 'new' => ['name' => $library->name, 'aliases' => $library->aliases]];
    }

    /** Pending suggestions elsewhere that name this library as their target (a copy moved there). */
    private function pendingPointersTo(Library $library): array
    {
        return FieldSuggestion::where('kind', 'relation')->where('status', 'pending')
            ->where(function ($q) {
                foreach ($this->libraryTargetKeys() as $type => $keys) {
                    $q->orWhere(fn ($w) => $w->where('suggestable_type', SuggestionRegistry::TYPES[$type])->whereIn('field_name', $keys));
                }
            })
            ->where('payload->target_id', $library->id)->pluck('id')->all();
    }

    private function repoint(array $ids, int $libraryId): void
    {
        foreach (FieldSuggestion::whereIn('id', $ids)->get() as $suggestion) {
            $suggestion->update(['payload' => ['target_id' => $libraryId] + (array) $suggestion->payload]);
        }
    }

    /** Copies whose former location (see ManuscriptLocation) was in this library. */
    private function formerLocationsOf(Library $library): array
    {
        return Manuscript::whereJsonContains('metadata->former_locations', ['library_id' => $library->id])->pluck('id')->all();
    }

    private function repointFormerLocations(array $ids, int $from, int $to): void
    {
        foreach (Manuscript::whereIn('id', $ids)->get() as $m) {
            $meta = $m->metadata;
            $meta['former_locations'] = array_map(fn ($l) => (int) ($l['library_id'] ?? 0) === $from ? ['library_id' => $to] + $l : $l, (array) ($meta['former_locations'] ?? []));
            $m->metadata = $meta;
            $m->saveQuietly();
        }
    }

    /** @return array<string, string[]> relation keys whose target is a library, per record type */
    private function libraryTargetKeys(): array
    {
        $keys = [];
        foreach (SuggestionRegistry::RELATIONS as $type => $relations) {
            foreach ($relations as $key => $relation) {
                if (($relation['target'] ?? null) === 'library') {
                    $keys[$type][] = $key;
                }
            }
        }

        return $keys;
    }
}
