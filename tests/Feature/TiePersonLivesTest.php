<?php

namespace Tests\Feature;

use App\Console\Commands\TiePersonLivesCommand;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use Tests\TestCase;

/** Step 14.116: people tied to their lives found by name and death year, from a judged list. */
class TiePersonLivesTest extends TestCase
{
    public function test_candidates_by_name_and_death_year_and_the_judged_ties(): void
    {
        $code = 'lives-name-' . uniqid();
        $source = Source::create(['code' => $code, 'kind' => Source::KIND_BIOGRAPHICAL, 'rank' => 30, 'title' => 'الأعلام', 'short_name' => 'الأعلام']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 2, 'facsimile_code' => $code]);
        $life = fn (string $key, string $title, string $text) => SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id,
            'volume_number' => 2, 'item_key' => $key, 'position' => 1, 'kind' => 'entry', 'page_start' => 10, 'page_end' => 10,
            'title' => $title, 'title_normalized' => $title, 'text' => $text, 'text_status' => 'auto']);
        $his = $life('10#1', 'يوسف بن يحيى القرشي الدمشقي', 'ابن الزكي 640- 685 ه يوسف بن يحيى القرشي الدمشقي: آخر القضاة.');
        $life('10#2', 'محمد بن احمد الطوسي', 'محمد بن احمد الطوسي 600- 685 ه');
        $person = Person::create(['name' => 'دمشقی، یوسف بن یحیی', 'normalized_name' => 'دمشقی یوسف بن یحیی', 'slug' => 'yusuf-' . uniqid(),
            'death_year_hijri' => 685, 'death_from' => 685, 'death_to' => 685]);
        $other = Person::create(['name' => 'طوسی، محمد بن احمد', 'normalized_name' => 'طوسی محمد بن احمد', 'slug' => 'tusi-' . uniqid(),
            'death_year_hijri' => 685, 'death_from' => 685, 'death_to' => 685]);
        // a life from his works already: none here
        SourceEntry::create(['citable_type' => Person::class, 'citable_id' => $other->id, 'source_id' => $source->id, 'source_item_id' => $his->id,
            'volume_number' => 2, 'page_start' => 10, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_MATCH, 'match_method' => 'via_works', 'confidence' => 80]);

        $report = storage_path('framework/testing-lives.json');
        $this->artisan('sources:find-person-lives', ['--source' => [$code], '--report' => $report])->assertSuccessful();
        $found = collect(json_decode(file_get_contents($report), true));
        $this->assertSame([$his->id], array_column($found->firstWhere('person_id', $person->id)['lives'], 'item_id'));
        $this->assertNull($found->firstWhere('person_id', $other->id));

        $file = 'storage/framework/testing-lives-ties.json';
        file_put_contents(base_path($file), json_encode(['ties' => [
            ['person_slug' => $person->slug, 'source' => $code, 'item_key' => '10#1', 'volume' => 2, 'method' => 'name_death_llm', 'confidence' => 90],
            ['person_slug' => $other->slug, 'source' => $code, 'item_key' => '10#2', 'volume' => 2, 'method' => 'name_death_llm', 'confidence' => 90],
            ['person_slug' => $person->slug, 'source' => $code, 'item_key' => '99#9', 'volume' => 2, 'method' => 'name_death_llm', 'confidence' => 90],
        ]]));
        $this->artisan('sources:tie-person-lives', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $tie = SourceEntry::where('legacy_type', TiePersonLivesCommand::LEGACY_TYPE)->sole();
        $this->assertSame([$person->id, $his->id, SourceEntry::STATUS_UNVERIFIED], [$tie->citable_id, $tie->source_item_id, $tie->status]);

        // a second run makes nothing, and tie-lives-to-people leaves these ties alone
        $this->artisan('sources:tie-person-lives', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $this->artisan('sources:tie-lives-to-people', ['--source' => [$code]])->assertSuccessful();
        $this->assertSame(1, SourceEntry::where('legacy_type', TiePersonLivesCommand::LEGACY_TYPE)->count());
        $this->get(route('people.show', $person))->assertOk()->assertSee('زندگی‌نامه در منابع');
        @unlink($report);
        @unlink(base_path($file));
    }
}
