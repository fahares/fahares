<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use Tests\TestCase;

/** Step 14.230: a reviewer settles a person's machine-made life tie, from the queue or from the badge on his page. */
class PersonLivesReviewTest extends TestCase
{
    private function tie(string $method = 'name_death_llm'): array
    {
        $code = 'lives-review-' . uniqid();
        $source = Source::create(['code' => $code, 'kind' => Source::KIND_BIOGRAPHICAL, 'rank' => 30, 'title' => 'طبقات آزمون', 'short_name' => 'طبقات آزمون']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 3, 'facsimile_code' => $code]);
        $item = SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id, 'volume_number' => 3, 'item_key' => '20#1',
            'position' => 1, 'kind' => 'entry', 'page_start' => 20, 'page_end' => 20, 'title' => 'الشيخ يوسف بن يحيى الدمشقي',
            'title_normalized' => 'الشيخ يوسف بن يحيى الدمشقي', 'text' => 'الشيخ يوسف بن يحيى الدمشقي المتوفى سنة 685 له كتاب الدرر.', 'text_status' => 'auto']);
        $person = Person::create(['name' => 'دمشقی، یوسف بن یحیی', 'normalized_name' => 'دمشقی یوسف بن یحیی', 'slug' => 'yusuf-' . uniqid(),
            'death_year_hijri' => 685, 'death_from' => 685, 'death_to' => 685, 'life_dates_raw' => '- 685 قمری', 'works_count' => 1]);
        Work::create(['primary_title' => 'الدرر الآزمونیة', 'clean_title' => 'الدرر الآزمونیة', 'slug' => 'durar-' . uniqid(), 'volume_number' => 1,
            'page_start' => 1, 'author_id' => $person->id]);
        $tie = SourceEntry::create(['citable_type' => Person::class, 'citable_id' => $person->id, 'source_id' => $source->id,
            'source_volume_id' => $volume->id, 'source_item_id' => $item->id, 'volume_number' => 3, 'page_start' => 20, 'role' => SourceEntry::ROLE_ENTRY,
            'origin' => SourceEntry::ORIGIN_MATCH, 'match_method' => $method, 'confidence' => 88, 'status' => SourceEntry::STATUS_UNVERIFIED,
            'legacy_type' => 'person_life_by_name', 'legacy_id' => $person->id]);

        return [$tie, $person, $source];
    }

    private function admin(): User
    {
        $admin = User::firstOrCreate(['email' => 'lives-admin@fahares.net'], ['name' => 'داور زندگی‌نامه‌ها', 'password' => 'password123', 'role' => 'admin']);
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    public function test_the_queue_lists_the_tie_with_the_person_and_the_entry(): void
    {
        [$tie, $person, $source] = $this->tie();
        $this->get(route('admin.person-lives.index'))->assertRedirect();

        $this->actingAs($this->admin())->get(route('admin.person-lives.index', ['source' => $source->code]))->assertOk()
            ->assertSee('data-person-life="' . $tie->id . '"', false)
            ->assertSee($person->name)->assertSee('الدرر الآزمونیة')->assertSee('الشيخ يوسف بن يحيى الدمشقي')
            ->assertSee('نام و سال وفات، داوری مدل')
            ->assertSee(route('admin.catalog-references.verify', $tie), false);
        $this->get(route('admin.person-lives.index', ['source' => $source->code, 'method' => 'via_works']))->assertOk()
            ->assertDontSee('data-person-life="' . $tie->id . '"', false);
    }

    public function test_the_badge_on_the_person_page_verifies_and_rejects(): void
    {
        [$tie, $person] = $this->tie();
        // a reader sees the badge without the buttons
        $this->get(route('people.show', $person))->assertOk()->assertSee('زندگی‌نامه در منابع')
            ->assertSee('یک مدل زبانی آن را با متن مدخل سنجیده است')->assertDontSee('data-machine-badge=', false);

        $this->actingAs($this->admin());
        $this->get(route('people.show', $person))->assertOk()->assertSee('data-machine-badge="' . $tie->reviewKey() . '"', false)
            ->assertSee('data-machine-item="' . $tie->reviewKey() . '"', false);

        $this->postJson(route('admin.catalog-references.verify', $tie))->assertOk()
            ->assertJsonPath('status', SourceEntry::STATUS_VERIFIED)->assertJsonPath('message', 'زندگی‌نامۀ «' . $person->name . '» تأیید شد.');
        $this->assertSame($this->admin()->id, $tie->fresh()->reviewed_by);
        $this->get(route('people.show', $person))->assertOk()->assertSee('زندگی‌نامه در منابع')->assertDontSee('data-machine-badge=', false);

        $this->postJson(route('admin.catalog-references.reopen', $tie), ['status' => SourceEntry::STATUS_VERIFIED])->assertOk();
        $this->postJson(route('admin.catalog-references.reject', $tie))->assertOk()->assertJsonPath('status', SourceEntry::STATUS_REJECTED);
        $this->get(route('people.show', $person))->assertOk()->assertDontSee('زندگی‌نامه در منابع');
    }

    public function test_a_tie_from_the_works_keeps_its_own_note(): void
    {
        [$tie] = $this->tie('via_works');
        $this->assertStringContainsString('از روی آثار این شخص', $tie->machineNote());
    }

    public function test_an_unsure_tie_of_the_model_is_shown_to_reviewers_only(): void
    {
        [$tie, $person] = $this->tie('name_llm');
        $tie->update(['confidence' => SourceEntry::LIFE_SHOWN_FROM - 1]);
        $this->get(route('people.show', $person))->assertOk()->assertDontSee('زندگی‌نامه در منابع');
        $tie->update(['confidence' => SourceEntry::LIFE_SHOWN_FROM]);
        $this->get(route('people.show', $person))->assertOk()->assertSee('زندگی‌نامه در منابع');

        $tie->update(['confidence' => 70]);
        $this->actingAs($this->admin())->get(route('people.show', $person))->assertOk()->assertSee('زندگی‌نامه در منابع');
        $tie->review($this->admin(), SourceEntry::STATUS_VERIFIED);
        auth()->logout();
        $this->get(route('people.show', $person))->assertOk()->assertSee('زندگی‌نامه در منابع');
    }

    public function test_a_judged_file_keeps_its_evidence_and_is_taken_back(): void
    {
        [$tie, $person, $source] = $this->tie();
        $item = $tie->sourceItem;
        $tie->delete();
        $file = 'storage/framework/testing-lives-llm.json';
        file_put_contents(base_path($file), json_encode(['ties' => [
            ['person_slug' => $person->slug, 'source' => $source->code, 'item_key' => $item->item_key, 'volume' => 3, 'method' => 'name_llm',
                'confidence' => 96, 'evidence' => 'المتوفى سنة 685'],
        ]]));
        $this->artisan('sources:tie-person-lives', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $made = SourceEntry::where('citable_type', Person::class)->where('citable_id', $person->id)->sole();
        $this->assertSame(['name_llm', 96, 'شاهد: المتوفى سنة 685'], [$made->match_method, $made->confidence, $made->notes]);

        $this->artisan('sources:tie-person-lives', ['--file' => $file, '--undo' => true])->assertSuccessful();
        $this->assertTrue($made->fresh()->exists);
        $this->artisan('sources:tie-person-lives', ['--file' => $file, '--undo' => true, '--apply' => true])->assertSuccessful();
        $this->assertNull($made->fresh());

        // a tie a person has reviewed is not taken back
        $this->artisan('sources:tie-person-lives', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $kept = SourceEntry::where('citable_type', Person::class)->where('citable_id', $person->id)->sole();
        $kept->review($this->admin(), SourceEntry::STATUS_VERIFIED);
        $this->artisan('sources:tie-person-lives', ['--file' => $file, '--undo' => true, '--apply' => true])->assertSuccessful();
        $this->assertNotNull($kept->fresh());
        @unlink(base_path($file));
    }
}
