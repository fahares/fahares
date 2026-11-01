<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Support\SearchFilters;
use Illuminate\Http\Request;
use Tests\TestCase;

/** The manuscripts a printed catalogue volume describes, as a filter of the manuscripts tab (step 13.116) */
class SearchVolumeFilterTest extends TestCase
{
    private function entry(Manuscript $ms, SourceVolume $volume, string $status = SourceEntry::STATUS_VERIFIED): SourceEntry
    {
        return SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $ms->id, 'source_id' => $volume->source_id,
            'source_volume_id' => $volume->id, 'volume_number' => $volume->volume_number, 'page_start' => 10,
            'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_MANUAL, 'status' => $status]);
    }

    /** @return array{0: Source, 1: SourceVolume, 2: SourceVolume, 3: array<string, Manuscript>} */
    private function catalogue(): array
    {
        $source = Source::create(['code' => 'vol-filter-test', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 30,
            'title' => 'فهرست نسخه‌های آزمون جلد', 'short_name' => 'فهرست آزمون', 'volumes_count' => 2]);
        $one = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 1]);
        $three = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 3]);
        $work = Work::create(['primary_title' => 'اثر جلد', 'clean_title' => 'اثر جلد', 'slug' => 'vol-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $ms = [];
        foreach (['V-1', 'V-2', 'V-3', 'V-4'] as $shelfmark) {
            $ms[$shelfmark] = Manuscript::create(['work_id' => $work->id, 'shelfmark' => $shelfmark, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'raw_text' => 'نسخه']);
        }
        // only this catalogue's entries, not the one a new copy gets from its own volume and page
        SourceEntry::where('citable_type', Manuscript::class)->whereIn('citable_id', collect($ms)->pluck('id'))->delete();
        $this->entry($ms['V-1'], $one);
        $this->entry($ms['V-2'], $one);
        $this->entry($ms['V-2'], $three);
        $this->entry($ms['V-3'], $three);
        $this->entry($ms['V-4'], $one, SourceEntry::STATUS_REJECTED);

        return [$source, $one, $three, $ms];
    }

    public function test_the_volume_filter_is_cleaned_and_turned_into_an_engine_filter_and_a_chip(): void
    {
        [, $one, $three] = $this->catalogue();
        $filters = SearchFilters::fromRequest(Request::create('/search', 'GET', ['source_volume_id' => (string) $three->id]), 'manuscripts');

        $this->assertSame("(source_volume_ids = {$three->id})", $filters->engine());
        $this->assertSame([['در فهرست: فهرست آزمون ج ۳', ['source_volume_id']]], $filters->active());

        foreach (['999999', 'abc', '-1'] as $bad) {
            $this->assertTrue(SearchFilters::fromRequest(Request::create('/search', 'GET', ['source_volume_id' => $bad]), 'manuscripts')->isEmpty(), $bad);
        }
        $this->assertTrue(SearchFilters::fromRequest(Request::create('/search', 'GET', ['source_volume_id' => $one->id]), 'works')->isEmpty(), 'only the manuscripts tab');
    }

    public function test_manuscripts_are_browsed_by_volume_without_rejected_entries(): void
    {
        [, $one, $three] = $this->catalogue();
        $see = fn (SourceVolume $v, array $yes, array $no) => tap($this->get(route('search', ['type' => 'manuscripts', 'source_volume_id' => $v->id]))->assertOk(),
            fn ($r) => [array_map(fn ($s) => $r->assertSee($s), $yes), array_map(fn ($s) => $r->assertDontSee($s), $no)]);

        $see($one, ['V-1', 'V-2', 'در فهرست: فهرست آزمون ج ۱'], ['V-3', 'V-4']);
        $see($three, ['V-2', 'V-3', 'فهرست آزمون ج ۳'], ['V-1', 'V-4']);
    }

    public function test_the_index_document_carries_every_volume_of_the_copy(): void
    {
        [, $one, $three, $ms] = $this->catalogue();
        $doc = fn (string $s) => Manuscript::query()->tap(fn ($q) => (new Manuscript)->makeAllSearchableUsing($q))->find($ms[$s]->id)->toSearchableArray()['source_volume_ids'];

        $this->assertEqualsCanonicalizing([$one->id, $three->id], $doc('V-2'));
        $this->assertSame([], $doc('V-4'), 'a rejected entry is not a volume of the copy');
        $this->assertSame([$three->id], $ms['V-3']->fresh()->toSearchableArray()['source_volume_ids'], 'loaded on demand too');
        $this->assertContains('source_volume_ids', config('scout.meilisearch.index-settings.' . Manuscript::class . '.filterableAttributes'));
    }

    public function test_a_source_page_links_each_volumes_count_to_its_manuscripts(): void
    {
        [$source, $one, $three] = $this->catalogue();

        $this->get(route('sources.show', $source->code))->assertOk()
            ->assertSee('data-volume-manuscripts', false)
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'source_volume_id' => $one->id])), false)
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'source_volume_id' => $three->id])), false);
    }
}
