<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptCollection;
use App\Support\Collections;
use App\Support\CollectionValues;
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
        $this->assertSame(['made' => 0, 'linked' => 0, 'unlinked' => 0, 'removed' => 0, 'kept_empty' => 0, 'values' => 0], Collections::sync());
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

    public function test_a_volumes_values_are_what_its_treatises_agree_on()
    {
        $copy = fn (array $c) => (object) ($c + array_fill_keys(CollectionValues::COPY_COLUMNS, null));
        $derived = CollectionValues::derive([
            $copy(['binding' => 'تیماج', 'dimensions' => '12×21سم', 'script_names' => 'نسخ', 'script_style' => 'خوش', 'lines' => 17, 'lines_raw' => '17 سطر', 'scribe_id' => 5, 'scribe_name' => 'محمد علی', 'copy_date_raw' => '1125ق', 'copy_place' => 'اصفهان']),
            $copy(['binding' => 'تیماج مشکی لایه‌ای', 'dimensions' => '۱۲ x ۲۱', 'script_names' => 'نسخ', 'lines' => 17, 'scribe_id' => 5, 'scribe_name' => 'محمد علی رازی', 'copy_date_raw' => '1125 ق', 'paper' => 'فرنگی']),
            $copy(['binding' => 'تیماج مشکی', 'script_names' => 'نسخ', 'script_style' => 'خوش', 'copy_date_raw' => '1250ق']),
        ]);
        // each binding is a piece of the fullest; the size and the hand are agreed; the paper is said once
        $this->assertSame(['binding' => 'fullest', 'dimensions' => 'agreement', 'paper' => 'single', 'script_names' => 'agreement', 'lines' => 'agreement', 'scribe' => 'agreement'], $derived['basis']);
        $this->assertSame(['تیماج مشکی لایه‌ای', '12×21سم', 'فرنگی', 'نسخ', 17, '17 سطر', 5], [$derived['values']['binding'], $derived['values']['dimensions'], $derived['values']['paper'],
            $derived['values']['script_names'], $derived['values']['lines'], $derived['values']['lines_raw'], $derived['values']['scribe_id']]);
        // the style is not every stating treatise's, the dates differ, the place is not every treatise's
        $this->assertSame([null, null, null], [$derived['values']['script_style'], $derived['values']['copy_date_raw'], $derived['values']['copy_place']]);

        // one treatise alone gives the volume's own fields, never the hand, the lines or the scribe
        $single = CollectionValues::derive([$copy(['binding' => 'میشن', 'script_names' => 'نستعلیق', 'lines' => 15, 'scribe_name' => 'احمد']), $copy([])]);
        $this->assertSame(['binding' => 'single'], $single['basis']);
        // two bindings that are not one inside the other, two hands: nothing
        $this->assertSame([], CollectionValues::derive([$copy(['binding' => 'تیماج مشکی', 'script_names' => 'نسخ']), $copy(['binding' => 'میشن عنابی', 'script_names' => 'نستعلیق'])])['basis']);
        // a date every treatise states is the volume's shared date; the volumes of one work have nothing in common
        $dated = [$copy(['copy_date_raw' => 'شوال 1125ق', 'binding' => 'تیماج']), $copy(['copy_date_raw' => 'شوال1125ق', 'binding' => 'تیماج'])];
        $this->assertSame(['binding' => 'agreement', 'copy_date_raw' => 'agreement'], CollectionValues::derive($dated)['basis']);
        $this->assertSame([], CollectionValues::derive($dated, ManuscriptCollection::SET)['basis']);
    }

    public function test_the_values_follow_the_treatises_and_keep_what_an_editor_gave()
    {
        $library = $this->library();
        $one = Manuscript::find($this->copy($library, '9101/1', ['binding' => 'تیماج', 'script_names' => 'نسخ', 'lines' => 17]));
        $two = Manuscript::find($this->copy($library, '9101/2', ['script_names' => 'نسخ', 'dimensions' => '12×21سم']));
        $three = $this->copy($library, '9101/3');
        $this->artisan('fahares:build-collections', ['--apply' => true, '--summary' => true])->expectsOutputToContain('read by')->assertSuccessful();

        $volume = $this->collectionOf($one->id);
        $this->assertSame(['تیماج', '12×21سم', 'نسخ', null], [$volume->binding, $volume->dimensions, $volume->script_names, $volume->lines]);
        $this->assertSame(['binding' => 'single', 'dimensions' => 'single', 'script_names' => 'agreement'], $volume->basis);
        // nothing of a treatise is written
        $this->assertSame([null, null], [DB::table('manuscripts')->where('id', $three)->value('script_names'), DB::table('manuscripts')->where('id', $three)->value('binding')]);

        // a treatise says otherwise: the agreement is gone; and back
        $two->update(['script_names' => 'نستعلیق']);
        $this->assertNull($volume->fresh()->script_names);
        $this->assertArrayNotHasKey('script_names', $volume->fresh()->basis);
        $two->update(['script_names' => 'نسخ']);
        $this->assertSame('نسخ', $volume->fresh()->script_names);

        // an editor's value is kept whatever the treatises say
        $volume->fresh()->update(['binding' => 'تیماج قهوه‌ای ضربی', 'basis' => ['binding' => 'editor'] + $volume->fresh()->basis]);
        $one->update(['binding' => 'میشن']);
        Collections::sync([$one->id, $two->id]);
        $this->assertSame(['تیماج قهوه‌ای ضربی', 'editor'], [$volume->fresh()->binding, $volume->fresh()->basis['binding']]);
        $this->assertSame(0, Collections::derive([$volume->id])['collections']);
    }

    public function test_the_copys_page_shows_its_collection_and_marks_what_it_reads_from_it()
    {
        $library = $this->library();
        $scribe = DB::table('people')->insertGetId(['name' => 'کاتب آزمایشی مجموعه', 'normalized_name' => 'کاتب ازمایشی مجموعه', 'slug' => 'test-collections-' . uniqid()]);
        try {
            $one = $this->copy($library, '9101/1', ['binding' => 'تیماج مشکی لایه‌ای', 'dimensions' => '12×21سم', 'script_names' => 'نسخ', 'lines' => 17, 'lines_raw' => '17 سطر',
                'scribe_id' => $scribe, 'scribe_name' => 'کاتب آزمایشی مجموعه', 'copy_date_raw' => '1125ق', 'copy_place' => 'اصفهان']);
            $two = $this->copy($library, '9101/2', ['binding' => 'تیماج', 'script_names' => 'نسخ', 'lines' => 17, 'scribe_id' => $scribe, 'scribe_name' => 'کاتب آزمایشی مجموعه', 'paper' => 'فرنگی نخودی']);
            $silent = $this->copy($library, '9101/3');
            $bika = $this->copy($library, '9101/4', ['is_bika' => true, 'script_names' => 'نسخ']);
            DB::table('manuscript_notes')->insert([
                ['manuscript_id' => $one, 'position' => 1, 'type' => 'endowment', 'text' => 'وقف‌نامۀ آزمایشی مجموعه', 'typed_by' => 'field'],
                ['manuscript_id' => $two, 'position' => 1, 'type' => 'endowment', 'text' => 'وقف‌نامۀ آزمایشی مجموعه', 'typed_by' => 'field'],
                ['manuscript_id' => $two, 'position' => 2, 'type' => 'seal', 'text' => 'مهر آزمایشی رسالۀ دوم', 'typed_by' => 'field'],
            ]);
            Collections::sync([$one, $two, $silent, $bika]);

            $copy = Manuscript::find($silent);
            $this->assertSame([true, true, true, false], [$copy->inheritsFromCollection('scribe'), $copy->inheritsFromCollection('script_names'), $copy->inheritsFromCollection('lines'), $copy->inheritsFromCollection('binding')]);
            // «بی‌کا» names no scribe, so the volume's is read; its hand is its own; the author's own hand reads no scribe
            $this->assertSame([true, false, true], [Manuscript::find($bika)->inheritsFromCollection('scribe'), Manuscript::find($bika)->inheritsFromCollection('script_names'), Manuscript::find($bika)->inheritsFromCollection('lines')]);
            $this->assertFalse(Manuscript::find($bika)->forceFill(['is_autograph' => true])->inheritsFromCollection('scribe'));
            // nothing is written in the copy
            $this->assertSame([null, null, null], array_values((array) DB::table('manuscripts')->where('id', $silent)->first(['scribe_id', 'script_names', 'lines'])));

            // the silent treatise: the hand, the lines and the scribe from the collection; the others' dates, shown beside
            $this->get(route('manuscripts.show', $copy))->assertOk()
                ->assertSee('data-from-collection="scribe"', false)->assertSee('data-from-collection="script_names"', false)->assertSee('data-from-collection="lines"', false)
                ->assertSee('data-siblings-stating="copy_date_raw"', false)->assertSee('data-siblings-stating="copy_place"', false)
                ->assertSee('مجموعۀ ۹۱۰۱')
                // the volume's own: the fullest binding, the size and the paper one entry gives
                ->assertSee('تیماج مشکی لایه‌ای')->assertSee('۱۲×۲۱سم')->assertSee('فرنگی نخودی')
                ->assertSee('تنها در مدخل رسالۀ ۹۱۰۱/۲ آمده است.')
                // the other treatises' endowment, once, and the seal; each treatise's scribe and date in the list
                ->assertSee('data-collection-notes', false)->assertSee('وقف‌نامۀ آزمایشی مجموعه')->assertSee('مهر آزمایشی رسالۀ دوم')
                ->assertSee('کاتب: کاتب آزمایشی مجموعه · ۱۱۲۵ق · اصفهان');
            $this->assertSame(1, substr_count($this->get(route('manuscripts.show', $copy))->getContent(), 'وقف‌نامۀ آزمایشی مجموعه'));

            // the first treatise states all: nothing is marked, and its own endowment is not repeated as the volume's
            $page = $this->get(route('manuscripts.show', Manuscript::find($one)))->assertOk()->assertDontSee('data-from-collection', false)->assertDontSee('data-siblings-stating', false);
            $volumeNotes = explode('</ul>', explode('data-collection-notes', $page->getContent(), 2)[1], 2)[0];
            $this->assertStringContainsString('مهر آزمایشی رسالۀ دوم', $volumeNotes);
            $this->assertStringNotContainsString('وقف‌نامۀ آزمایشی مجموعه', $volumeNotes);
            // the second's shorter binding is the same binding said in brief: not set against the volume's
            $this->get(route('manuscripts.show', Manuscript::find($two)))->assertOk()->assertDontSee('data-own-entry', false);

            // a copy in no collection keeps its page: its binding among its own features
            $alone = $this->copy($library, '4825', ['binding' => 'میشن عنابی تنها']);
            $this->get(route('manuscripts.show', Manuscript::find($alone)))->assertOk()->assertSee('میشن عنابی تنها')->assertDontSee('data-collection-values', false);
        } finally {
            DB::table('manuscript_notes')->whereIn('manuscript_id', $this->manuscripts)->delete();
            DB::table('manuscripts')->whereIn('id', $this->manuscripts)->delete();
            DB::table('people')->where('id', $scribe)->delete();
        }
    }
}

