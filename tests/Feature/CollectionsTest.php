<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptCollection;
use App\Support\Collections;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The «مجموعه» record of a bound volume follows its treatises' library and collection_key (item 163, step 14.322). */
class CollectionsTest extends TestCase
{
    private array $works = [];

    private array $manuscripts = [];

    private array $libraries = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
    }

    protected function tearDown(): void
    {
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->orWhereIn('work_id', $this->works)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        // the collections go with their library
        DB::table('libraries')->whereIn('id', $this->libraries)->delete();
        parent::tearDown();
    }

    private function library(): int
    {
        return $this->libraries[] = DB::table('libraries')->insertGetId(['city' => 'تهران', 'name' => 'کتابخانۀ آزمایشی مجموعه ' . uniqid(), 'slug' => 'test-collections-' . uniqid()]);
    }

    private function work(): int
    {
        return $this->works[] = DB::table('works')->insertGetId(['primary_title' => 'رسالۀ آزمایشی مجموعه', 'clean_title' => 'رسالۀ آزمایشی مجموعه',
            'slug' => 'test-collections-' . uniqid(), 'volume_number' => 99, 'page_start' => 1]);
    }

    private function copy(int $library, string $shelfmark, array $columns = []): int
    {
        return $this->manuscripts[] = DB::table('manuscripts')->insertGetId($columns + [
            'work_id' => $this->work(), 'sequence_number' => 1, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1, 'city' => 'تهران',
            'library' => 'کتابخانۀ آزمایشی', 'library_id' => $library, 'shelfmark' => $shelfmark, 'shelfmark_key' => $shelfmark,
            'collection_key' => Manuscript::collectionKeyOf($shelfmark), 'raw_text' => 'تهران؛ کتابخانۀ آزمایشی',
        ]);
    }

    private function collectionOf(int $copy): ?ManuscriptCollection
    {
        return ManuscriptCollection::find(DB::table('manuscripts')->where('id', $copy)->value('collection_id'));
    }

    public function test_a_copy_with_two_shelfmarks_is_of_the_volume_the_first_one_names()
    {
        $this->assertSame(['2297-ف', '240', '1376-عکسی', '3430', '1440'], array_map(fn ($s) => Manuscript::collectionKeyOf($s),
            ['2297/1-ف و 2715/1-ف', '240/5 و 6927/2-عکس', '1376/2عکسی و 1578/2عکسی', '3430/1 و 6898-عکس', '1440/1 و 1440/2']));
        // the first names no part: the second's volume
        $this->assertSame('4981-ف', Manuscript::collectionKeyOf('9364 و 4981/3ف'));
        // two reels joined by a dash: the mark is the last piece, and the part the first reel's
        $this->assertSame('462-ف', Manuscript::collectionKeyOf('462/24(2آ)-463/24(ب)-ف'));
        $this->assertSame('24', Manuscript::collectionKey('462/24(2آ)-463/24(ب)-ف')['part']);
        // an old number after the dash is no second shelfmark's mark
        $this->assertSame(['8740', '4/120'], [Manuscript::collectionKeyOf('8740/7-58/190'), Manuscript::collectionKeyOf('4/120/1-5957')]);
        $this->assertNull(Manuscript::collectionKeyOf('39و40د'));
    }

    public function test_the_command_makes_a_record_for_each_volume_and_links_its_treatises()
    {
        $library = $this->library();
        $other = $this->library();
        $one = $this->copy($library, '9101/1');
        $two = $this->copy($library, '9101/2');
        $marked = $this->copy($library, '9101/3-طباطبائی');
        $elsewhere = $this->copy($other, '9101/1');
        $plain = $this->copy($library, '4825');

        $this->artisan('fahares:build-collections')->expectsOutputToContain('Dry run: nothing written.')->assertSuccessful();
        $this->assertNull($this->collectionOf($one));
        $this->artisan('fahares:build-collections', ['--apply' => true])->assertSuccessful();

        $volume = $this->collectionOf($one);
        $this->assertSame([$library, '9101', 2, ManuscriptCollection::BOUND], [$volume->library_id, $volume->key, $volume->copies_count, $volume->kind]);
        $this->assertSame($volume->id, $this->collectionOf($two)->id);
        $this->assertSame('9101-طباطبائی', $this->collectionOf($marked)->key);
        $this->assertNotSame($volume->id, $this->collectionOf($elsewhere)->id);
        $this->assertNull($this->collectionOf($plain));
        // no other column of a copy is written
        $this->assertNull(DB::table('manuscripts')->where('id', $one)->value('updated_at'));

        // again: nothing to do
        $before = ManuscriptCollection::whereIn('library_id', [$library, $other])->pluck('id')->all();
        $this->assertSame(['made' => 0, 'linked' => 0, 'unlinked' => 0, 'removed' => 0, 'kept_empty' => 0], Collections::sync());
        $this->assertSame($before, ManuscriptCollection::whereIn('library_id', [$library, $other])->pluck('id')->all());
    }

    public function test_a_copy_moves_with_its_shelfmark_and_an_emptied_collection_is_removed_unless_it_holds_something()
    {
        $library = $this->library();
        $one = Manuscript::find($this->copy($library, '9101/1'));
        $two = Manuscript::find($this->copy($library, '9101/2'));
        Collections::sync([$one->id, $two->id]);
        $first = $this->collectionOf($one->id);
        $this->assertSame(2, $first->copies_count);

        // saved as a model: the hook moves it and counts both volumes
        $two->update(['shelfmark' => '9102/2']);
        $this->assertSame('9102', $two->collection_key);
        $second = $this->collectionOf($two->id);
        $this->assertSame(['9102', 1], [$second->key, $second->copies_count]);
        $this->assertSame($second->id, $two->collection_id);
        $this->assertSame(1, $first->fresh()->copies_count);

        // the last copy leaves: the record made by the rule goes, one with an editor's value stays
        $second->update(['binding' => 'تیماج مشکی', 'basis' => ['binding' => 'editor']]);
        $two->update(['shelfmark' => '9101/2']);
        $this->assertSame(0, $second->fresh()->copies_count);
        $one->update(['shelfmark' => '4825']);
        $two->update(['shelfmark' => '4826']);
        $this->assertNull($first->fresh());
        $this->assertNull($this->collectionOf($one->id));

        // a deleted copy is counted out
        $three = Manuscript::find($this->copy($library, '9102/3'));
        Collections::sync([$three->id]);
        $this->assertSame(1, $second->fresh()->copies_count);
        $three->delete();
        $this->assertSame(0, $second->fresh()->copies_count);
    }

    public function test_a_pinned_copy_stays_and_the_volumes_of_one_work_are_a_set()
    {
        $library = $this->library();
        $one = $this->copy($library, '9101/1');
        $two = $this->copy($library, '9101/2');
        $pinned = $this->copy($library, '9105/1');
        Collections::sync([$one, $two, $pinned]);
        $volume = $this->collectionOf($one);
        $own = $this->collectionOf($pinned)->id;

        // an editor placed it in volume 9101: the rule leaves it there, and its treatises list it
        DB::table('manuscripts')->where('id', $pinned)->update(['collection_id' => $volume->id, 'collection_pinned' => true]);
        Collections::sync();
        $this->assertSame($volume->id, $this->collectionOf($pinned)->id);
        $this->assertSame(3, $volume->fresh()->copies_count);
        $this->assertNull(ManuscriptCollection::find($own));
        $this->assertEqualsCanonicalizing([$one, $two, $pinned], Manuscript::find($one)->collectionSiblings()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$one, $two, $pinned], Manuscript::find($pinned)->collectionSiblings()->pluck('id')->all());

        // «13/1» and «13/2» of one work are its volumes, not treatises bound together
        $a = $this->copy($library, '13/1');
        $b = $this->copy($library, '13/2', ['work_id' => DB::table('manuscripts')->where('id', $a)->value('work_id')]);
        Collections::sync([$a, $b]);
        $this->assertSame(ManuscriptCollection::SET, $this->collectionOf($a)->kind);
        $this->assertSame(ManuscriptCollection::BOUND, $volume->fresh()->kind);
    }
}
