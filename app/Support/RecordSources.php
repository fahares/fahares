<?php

namespace App\Support;

use App\Models\Source;
use App\Models\SourceEntry;
use Illuminate\Support\Collection;

/**
 * A record's source entries as its page shows them (step 13.2): each group in the order of its sources' rank, so a
 * library's own catalogue comes before the bibliographies and Fankha, compiled from the others, last.
 */
class RecordSources
{
    /** The relations to load with a record's sourceEntries. */
    public const RELATIONS = ['sourceEntries.source.library', 'sourceEntries.sourceVolume'];

    /** @param  Collection<int, SourceEntry>  $entries */
    public function __construct(public readonly Collection $entries)
    {
    }

    /** The entry the record was made from: its Fankha entry. */
    public function primary(): ?SourceEntry
    {
        return $this->entries->first(fn (SourceEntry $e) => $e->is_primary);
    }

    /** Entries of the record in other sources that have a page image, for the side card: a library catalogue first. */
    public function withPages(): Collection
    {
        return $this->ranked($this->placed()->filter(fn (SourceEntry $e) => $e->viewer()));
    }

    /** Entries of other sources with their printed text, shown above Fankha's text (backlog item 60). */
    public function printed(): Collection
    {
        return $this->ranked($this->placed()->filter(fn (SourceEntry $e) => $e->hasText()));
    }

    /** The library catalogue page Fankha cites, «[ف: 30-576]». */
    public function cited(): ?SourceEntry
    {
        return $this->entries->first(fn (SourceEntry $e) => $e->origin === SourceEntry::ORIGIN_FANKHA_CITATION && $e->source_volume_id);
    }

    /** Further places of the record in a source it is already in: addenda and errata. */
    public function others(): Collection
    {
        return $this->entries->filter(fn (SourceEntry $e) => ! $e->is_primary
            && in_array($e->role, [SourceEntry::ROLE_ADDENDUM, SourceEntry::ROLE_ERRATUM], true))->values();
    }

    /** The work's bibliography (مآخذ کتاب‌شناسی) as Fankha gives it, in its order. */
    public function bibliography(): Collection
    {
        return $this->entries->filter(fn (SourceEntry $e) => $e->role === SourceEntry::ROLE_CITATION)->sortBy('sort_order')->values();
    }

    /** Entries of the record in another source than the one it was made from, not rejected. */
    private function placed(): Collection
    {
        return $this->entries->filter(fn (SourceEntry $e) => ! $e->is_primary && $e->role === SourceEntry::ROLE_ENTRY
            && $e->status !== SourceEntry::STATUS_REJECTED && $e->origin !== SourceEntry::ORIGIN_FANKHA_CITATION);
    }

    private function ranked(Collection $entries): Collection
    {
        return $entries->sortBy(fn (SourceEntry $e) => [$e->source?->rank ?? Source::KIND_RANKS[Source::KIND_OTHER], $e->id])->values();
    }
}
