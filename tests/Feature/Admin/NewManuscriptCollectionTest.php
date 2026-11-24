<?php

namespace Tests\Feature\Admin;

use App\Auth\Permissions;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptCollection;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «نسخۀ تازه» and the «مجموعه» records (backlog items 149 and 163): a copy made in the panel joins the collection of
 * its volume, the collection counts it and reads its values, and the undo leaves the collection as it was.
 */
class NewManuscriptCollectionTest extends TestCase
{
    protected User $admin;

    protected Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permissions::flush();
        config(['scout.driver' => 'collection']);
        $this->admin = User::create(['name' => 'مدیر کل', 'email' => uniqid('nmc-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->library = Library::create(['name' => 'دانشگاه آزمون', 'city' => 'تهران', 'slug' => 'nmc-lib-' . uniqid()]);
    }

    private function copy(string $shelfmark, array $attributes = []): Manuscript
    {
        $work = Work::create(['primary_title' => 'اثر ' . uniqid(), 'clean_title' => 'اثر', 'slug' => 'nmc-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create($attributes + ['work_id' => $work->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1, 'shelfmark' => $shelfmark,
            'library_id' => $this->library->id, 'library' => $this->library->name, 'city' => $this->library->city])->fresh();
    }

    private function store(array $data)
    {
        return $this->actingAs($this->admin)->post(route('admin.manuscripts.store'), $data + [
            'library_id' => $this->library->id, 'work_mode' => 'untitled', 'source_kind' => 'researcher',
        ]);
    }

    private function created(): Manuscript
    {
        return Manuscript::whereNull('catalog_id')->latest('id')->firstOrFail();
    }

    private function undo(Manuscript $copy): void
    {
        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $copy->id)->where('action', 'create')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.audit-logs.rollback', $log))->assertSessionHas('success');
    }

    public function test_a_new_treatise_joins_its_volumes_collection_reads_from_it_and_leaves_it_as_it_was()
    {
        $first = $this->copy('3522/1', ['script_names' => 'نستعلیق', 'lines' => 15]);
        $second = $this->copy('3522/2', ['script_names' => 'نستعلیق', 'lines' => 15]);
        $collection = ManuscriptCollection::findOrFail($first->collection_id);
        $this->assertSame([$collection->id, 2, 'نستعلیق', 15], [$second->collection_id, $collection->copies_count, $collection->script_names, $collection->lines]);

        // silent on its hand and lines: it reads the volume's
        $this->store(['from_id' => $first->id, 'shelfmark' => '3522/1 مکرر', 'collection_key' => '3522', 'range' => '16ر تا 16پ'])->assertRedirect()->assertSessionMissing('error');
        $silent = $this->created();
        $this->assertSame($collection->id, $silent->collection_id);
        $this->assertFalse((bool) $silent->collection_pinned);
        $this->assertSame(3, $collection->fresh()->copies_count);
        $this->assertTrue($silent->inheritsFromCollection('script_names'));
        $this->assertTrue($silent->inheritsFromCollection('lines'));
        $this->actingAs($this->admin)->get(route('manuscripts.show', $silent))->assertOk()->assertSee('نستعلیق');
        $this->undo($silent);
        $this->assertSame([2, 'نستعلیق', 15], [$collection->fresh()->copies_count, $collection->fresh()->script_names, $collection->fresh()->lines]);

        // one that names another hand: the volume's hand is no longer agreed, and is again after the undo
        $this->store(['shelfmark' => '3522/3', 'script_names' => 'نسخ', 'lines' => 21])->assertRedirect()->assertSessionMissing('error');
        $other = $this->created();
        $this->assertSame($collection->id, $other->collection_id);
        $this->assertSame(3, $collection->fresh()->copies_count);
        $changed = $collection->fresh();
        $this->assertNotSame(['نستعلیق', 15], [$changed->script_names, $changed->lines]);
        $this->undo($other);
        $restored = $collection->fresh();
        $this->assertSame([2, 'نستعلیق', 15], [$restored->copies_count, $restored->script_names, $restored->lines]);
        $this->assertTrue(isset($restored->basis['script_names'], $restored->basis['lines']));
    }

    public function test_a_plain_numbered_volume_becomes_a_collection_with_the_new_treatise_and_stops_being_one_with_the_undo()
    {
        $existing = $this->copy('12422');
        $this->assertNull($existing->collection_id);

        $this->store(['from_id' => $existing->id, 'shelfmark' => '12422', 'collection_key' => '12422', 'confirm_duplicate' => 1, 'range' => '30-41'])->assertRedirect()->assertSessionMissing('error');
        $copy = $this->created();
        $collection = ManuscriptCollection::where('library_id', $this->library->id)->where('key', '12422')->firstOrFail();
        $this->assertSame([$collection->id, $collection->id], [$copy->collection_id, $existing->fresh()->collection_id]);
        $this->assertSame([2, ManuscriptCollection::BOUND], [$collection->copies_count, $collection->kind]);
        $this->assertNotNull($existing->fresh()->boundCollection());

        $this->undo($copy);
        $this->assertNull($existing->fresh()->collection_id);
        $this->assertNull(ManuscriptCollection::find($collection->id));
    }

    public function test_the_form_opened_from_a_treatise_an_editor_placed_starts_with_that_collection_and_the_new_one_joins_it()
    {
        $first = $this->copy('700/1');
        $this->copy('700/2');
        // a treatise under another number, placed in volume 700 by hand (step 14.330)
        $placed = $this->copy('9999');
        $this->actingAs($this->admin)->post(route('admin.manuscripts.collection', $placed->id), ['collection_key' => '700'])->assertRedirect();
        $placed = $placed->fresh();
        $this->assertSame([$first->collection_id, true], [$placed->collection_id, (bool) $placed->collection_pinned]);

        $this->actingAs($this->admin)->get(route('admin.manuscripts.create', ['from' => $placed->id]))->assertOk()
            ->assertSee('name="collection_key" id="collection_key" value="700"', false);

        // another treatise under that other number, of the same volume
        $this->store(['from_id' => $placed->id, 'shelfmark' => '9999', 'collection_key' => '700', 'confirm_duplicate' => 1])->assertRedirect()->assertSessionMissing('error');
        $copy = $this->created();
        $this->assertSame([$first->collection_id, true], [$copy->collection_id, (bool) $copy->collection_pinned]);
        $this->assertSame(4, ManuscriptCollection::find($first->collection_id)->copies_count);
        $this->assertEqualsCanonicalizing(Manuscript::where('collection_id', $first->collection_id)->pluck('id')->all(), $copy->collectionSiblings()->pluck('id')->all());

        // the rule of the shelfmark leaves it there
        \App\Support\Collections::sync([$copy->id]);
        $this->assertSame($first->collection_id, $copy->fresh()->collection_id);

        $this->undo($copy);
        $this->assertSame(3, ManuscriptCollection::find($first->collection_id)->copies_count);

        // a number that is no collection of the library is still refused
        $this->store(['shelfmark' => '500/2', 'collection_key' => '31313'])->assertRedirect()->assertSessionHas('error');
    }
}
