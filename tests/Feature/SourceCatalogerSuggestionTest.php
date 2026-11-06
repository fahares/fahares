<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\User;
use App\Models\Work;
use App\Suggestions\VolumeCatalogers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «The cataloger of this catalogue, or of one of its volumes, is someone else» on a source's page (step 14.58): the
 * researcher picks the volume and the cataloger or writes a name; the reviewer's approval changes the volume's people
 * through VolumeEditor, so its copies get the new cataloger, and reopening the suggestion takes it back.
 */
class SourceCatalogerSuggestionTest extends TestCase
{
    protected User $admin;

    protected User $researcher;

    protected Source $source;

    protected Library $library;

    protected Cataloger $wrong;

    protected Cataloger $right;

    /** @var list<int> the catalogue volumes, which are also the source's volumes */
    protected array $volumes = [];

    protected Manuscript $copy;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->admin = User::create(['name' => 'داور', 'email' => uniqid('vc-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->researcher = User::create(['name' => 'پژوهشگر', 'email' => uniqid('vc-') . '@example.test', 'password' => 'password123', 'role' => 'user']);
        $this->library = Library::create(['name' => 'کتابخانه‌ی آزمون فهرست‌نگار', 'city' => 'قم', 'slug' => 'vc-lib-' . uniqid()]);
        $this->wrong = Cataloger::create(['name' => 'فهرست‌نگار نادرست', 'slug' => 'vc-wrong-' . uniqid()]);
        $this->right = Cataloger::create(['name' => 'سید احمد حسینی اشکوری', 'slug' => 'vc-right-' . uniqid()]);
        $this->source = Source::create(['code' => 'vc-cat-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست آزمون فهرست‌نگار',
            'library_id' => $this->library->id, 'volumes_count' => 2]);
        foreach ([1, 2] as $n) {
            $id = DB::table('catalog_volumes')->insertGetId(['title' => $this->source->title, 'volume_number' => $n, 'library_id' => $this->library->id]);
            DB::table('source_volumes')->updateOrInsert(['id' => $id], ['source_id' => $this->source->id, 'volume_number' => $n]);
            DB::table('catalog_volume_cataloger')->insert(['catalog_volume_id' => $id, 'cataloger_id' => $this->wrong->id, 'role' => 'cataloger']);
            $this->volumes[] = $id;
        }
        $work = Work::create(['primary_title' => 'اثر آزمون', 'clean_title' => 'اثر آزمون', 'slug' => 'vc-w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $this->copy = Manuscript::create(['work_id' => $work->id, 'library_id' => $this->library->id, 'library' => $this->library->name, 'shelfmark' => '7',
            'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1, 'catalog_volume_id' => $this->volumes[0], 'cataloger_id' => $this->wrong->id]);
    }

    protected function suggest(array $data)
    {
        return $this->actingAs($this->researcher)->postJson('/suggestions', $data + [
            'type' => 'source', 'id' => $this->source->id, 'kind' => 'relation', 'field_name' => 'cataloger_is', 'role' => 'cataloger',
            'rationale_citation' => 'صفحه‌ی عنوان جلد یکم',
        ]);
    }

    public function test_the_source_page_offers_the_cataloger_suggestion_beside_each_volume()
    {
        $html = $this->actingAs($this->researcher)->get(route('sources.show', $this->source->code))->assertOk()
            ->assertSee('cataloger_is', false)->assertSee('cataloger_also', false)->getContent();
        foreach ($this->volumes as $id) {
            $this->assertStringContainsString('data-suggest-cataloger="' . $id . '"', $html);
        }
        // the picker finds catalogers by name and by their page's link
        $this->getJson('/api/suggestions/lookup?type=cataloger&q=' . urlencode('اشکوری'))->assertOk()->assertJsonFragment(['id' => $this->right->id]);
        $this->getJson('/api/suggestions/lookup?type=cataloger&q=' . urlencode('https://fahares.net/catalogers/' . $this->right->getRouteKey()))
            ->assertOk()->assertJsonPath('0.id', $this->right->id);
    }

    public function test_a_suggestion_is_validated_and_stored_with_its_volume()
    {
        $this->suggest(['target_id' => $this->right->id, 'role' => ''])->assertStatus(422)->assertJsonValidationErrors('role');
        $this->suggest(['target_id' => null, 'role_name' => ''])->assertStatus(422)->assertJsonValidationErrors('role_name');
        $this->suggest(['target_id' => $this->right->id, 'volume_id' => 999999999])->assertStatus(422)->assertJsonValidationErrors('volume_id');

        $this->suggest(['target_id' => $this->right->id, 'volume_id' => $this->volumes[0]])->assertOk();
        $s = FieldSuggestion::where('suggestable_type', Source::class)->sole();
        $this->assertSame(['relation', 'cataloger_is'], [$s->kind, $s->field_name]);
        $this->assertSame(['target_id' => $this->right->id, 'role' => 'cataloger', 'volume_id' => $this->volumes[0]], $s->payload);
        $this->assertTrue($s->relationTarget()->is($this->right));

        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()
            ->assertSee('فهرست‌نگار پیشنهادی')->assertSee($this->right->name)->assertSee('name="volume_ids[]"', false);
    }

    public function test_approval_replaces_the_volumes_cataloger_and_reopening_takes_it_back()
    {
        $this->suggest(['target_id' => $this->right->id, 'volume_id' => $this->volumes[0]])->assertOk();
        $s = FieldSuggestion::where('suggestable_type', Source::class)->sole();

        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/approve", [
            'volume_ids' => [$this->volumes[0]], 'cataloger_choice' => 'target', 'role' => 'cataloger', 'mode' => 'replace',
        ])->assertSessionHasNoErrors()->assertRedirect('/admin/suggestions');

        $this->assertSame([['cataloger_id' => $this->right->id, 'role' => 'cataloger']], VolumeCatalogers::people($this->volumes[0]));
        $this->assertSame([['cataloger_id' => $this->wrong->id, 'role' => 'cataloger']], VolumeCatalogers::people($this->volumes[1]), 'the other volume is left as it was');
        $this->assertSame($this->right->id, (int) $this->copy->fresh()->cataloger_id, 'the volume\'s copy follows its cataloger');
        $this->assertSame(1, (int) $this->right->fresh()->volumes_count);
        $this->assertSame('approved', $s->fresh()->status);
        $this->get(route('sources.show', $this->source->code))->assertSee($this->right->name);

        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/reopen")->assertSessionHas('success');
        $this->assertSame([['cataloger_id' => $this->wrong->id, 'role' => 'cataloger']], VolumeCatalogers::people($this->volumes[0]));
        $this->assertSame($this->wrong->id, (int) $this->copy->fresh()->cataloger_id);
        $this->assertSame('pending', $s->fresh()->status);
    }

    public function test_a_name_only_suggestion_adds_a_new_cataloger_to_every_volume()
    {
        $this->suggest(['field_name' => 'cataloger_also', 'role' => 'supervisor', 'role_name' => 'سید محمود مرعشی'])->assertOk();
        $s = FieldSuggestion::where('suggestable_type', Source::class)->sole();

        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/approve", [
            'volume_ids' => $this->volumes, 'cataloger_choice' => 'new', 'cataloger_name' => 'سید محمود مرعشی', 'role' => 'supervisor', 'mode' => 'add',
        ])->assertSessionHasNoErrors()->assertRedirect('/admin/suggestions');

        $made = Cataloger::where('name', 'سید محمود مرعشی')->sole();
        foreach ($this->volumes as $id) {
            $this->assertSame([['cataloger_id' => $this->wrong->id, 'role' => 'cataloger'], ['cataloger_id' => $made->id, 'role' => 'supervisor']], VolumeCatalogers::people($id));
        }
        $this->assertTrue($s->fresh()->resolution['created_cataloger']);
    }

    public function test_a_suggestion_filed_on_the_library_converts_to_a_cataloger_suggestion()
    {
        // as #106: «the cataloger is Seyyed Ahmad Eshkevari», sent as the library's description
        $s = FieldSuggestion::create(['suggestable_type' => Library::class, 'suggestable_id' => $this->library->id, 'kind' => 'field', 'field_name' => 'description',
            'suggested_value' => 'نام فهرستنگار سید احمد اشکوری است', 'user_id' => $this->researcher->id, 'guest_name' => $this->researcher->name, 'status' => 'pending']);

        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/convert", [
            'target_type' => 'source', 'target_ref' => 'https://fahares.net/sources/' . $this->source->code, 'kind' => 'relation', 'field_name' => 'cataloger_is',
            'relation_target' => 'https://fahares.net/catalogers/' . $this->right->getRouteKey(), 'role' => 'cataloger',
            'suggested_value' => 'نام فهرستنگار سید احمد اشکوری است', 'rationale_citation' => 'پیشنهاد #106', 'close_original' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $derived = FieldSuggestion::findOrFail(last($s->fresh()->resolution['converted_to']));
        $this->assertSame([Source::class, $this->source->id, 'cataloger_is', $this->right->id],
            [$derived->suggestable_type, $derived->suggestable_id, $derived->field_name, $derived->payload['target_id']]);
        $this->assertSame('converted', $s->fresh()->status);
    }
}
