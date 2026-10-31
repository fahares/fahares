<?php

namespace App\Support;

use App\Models\Source;
use App\Models\SourceEntry;
use Illuminate\Support\Collection;

/**
 * A record's source entries as its page shows them (steps 13.2 and 13.4): all of them alike, in the order of their
 * sources' rank, so a library's own catalogue comes before the bibliographies and Fankha, compiled from the others,
 * last. The entry the record was made from (is_primary) is data only and stands above no other.
 */
class RecordSources
{
    /** The relations to load with a record's sourceEntries. */
    public const RELATIONS = ['sourceEntries.source.library', 'sourceEntries.sourceVolume', 'sourceEntries.sourceItem'];

    /** @param  Collection<int, SourceEntry>  $entries */
    public function __construct(public readonly Collection $entries)
    {
    }

    /** The entry the record was made from: its Fankha entry. */
    public function primary(): ?SourceEntry
    {
        return $this->entries->first(fn (SourceEntry $e) => $e->is_primary);
    }

    /**
     * The record's places in the sources for the side card, in the order of their sources' rank: every entry of a
     * registered source but the bibliography's, not rejected. No entry stands above another but by its source's rank.
     */
    public function cards(): Collection
    {
        return $this->ranked($this->shown()->filter(fn (SourceEntry $e) => $e->source_id));
    }

    /** The texts printed at those places, in the same order. */
    public function texts(): Collection
    {
        return $this->ranked($this->shown()->filter(fn (SourceEntry $e) => $e->hasText()));
    }

    /** The work's bibliography (مآخذ کتاب‌شناسی) as Fankha gives it, in its order. */
    public function bibliography(): Collection
    {
        return $this->entries->filter(fn (SourceEntry $e) => $e->role === SourceEntry::ROLE_CITATION)->sortBy('sort_order')->values();
    }

    /**
     * Whether a line of the bibliography says nothing the cards do not: it is only source names and citations, and
     * every place it cites is in the printed entry of a card the page shows (an entry, or a mention «یاد شده در …»).
     * The page then leaves the line out but in suggestion mode; the entry stays as Fankha's words.
     */
    public function isCovered(SourceEntry $citation): bool
    {
        $places = $citation->locations ?? ($citation->source_id ? [['source_id' => $citation->source_id, 'volume' => $citation->volume_number, 'page' => $citation->page_start]] : []);
        if (! $places || ! BibliographyLinks::onlyCitations((string) $citation->citation_text)) {
            return false;
        }
        $items = $this->cards()->filter(fn (SourceEntry $e) => $e->sourceItem);

        return collect($places)->every(fn ($p) => $items->contains(fn (SourceEntry $e) => (int) $e->source_id === (int) $p['source_id']
            && (int) $e->volumeNumber() === (int) $p['volume']
            && $p['page'] >= $e->sourceItem->page_start && $p['page'] <= ($e->sourceItem->page_end ?? $e->sourceItem->page_start)));
    }

    private function shown(): Collection
    {
        return $this->entries->filter(fn (SourceEntry $e) => $e->role !== SourceEntry::ROLE_CITATION && $e->status !== SourceEntry::STATUS_REJECTED);
    }

    private function ranked(Collection $entries): Collection
    {
        // within a rank, a page seen in the source before one known only from Fankha's citation of it
        return $entries->sortBy(fn (SourceEntry $e) => [$e->source?->rank ?? Source::KIND_RANKS[Source::KIND_OTHER], $e->origin === SourceEntry::ORIGIN_FANKHA_CITATION,
            $e->source_id, $e->volumeNumber() ?? 0, $e->page_start ?? 0, $e->id])->values();
    }
}
