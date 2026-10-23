<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillSourcesCommand as Backfill;
use App\Models\Catalog;
use App\Models\CatalogVolume;
use App\Models\FieldEvidence;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptCatalogReference as Ref;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillSourcesTest extends TestCase
{
    protected Library $library;

    protected CatalogVolume $cited;

    protected CatalogVolume $edition;

    protected Work $work;

    protected Manuscript $manuscript;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'masjid-azam-' . uniqid()]);
        $fankha = Catalog::where('code', 'fankha')->first();
        $this->cited = CatalogVolume::create(['title' => 'فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id, 'catalog_id' => $fankha->id]);
        CatalogVolume::create(['title' => 'فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد اول', 'volume_number' => 1, 'library_id' => $this->library->id, 'catalog_id' => $fankha->id]);
        $this->edition = CatalogVolume::create([
            'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id,
            'edition_of_id' => $this->cited->id, 'edition_label' => '۱۳۸۸، به کوشش رضا استادی',
            'facsimile_code' => 'masjid-azam-ostadi', 'page_first' => 1, 'page_last' => 827, 'source_url' => 'https://scan.example/1',
        ]);
        $this->work = Work::create([
            'catalog_id' => $fankha->id, 'primary_title' => 'دیوان قدسی', 'clean_title' => 'دیوان قدسی', 'slug' => 'work-src-' . uniqid(),
            'volume_number' => 15, 'page_start' => 120, 'page_end' => 121, 'raw_text' => "● دیوان قدسی\nشعر فارسی",
            'bibliography' => ['الذریعة 101/25 و 107/8', 'دنا 2/207'],
        ]);
        $this->manuscript = Manuscript::create([
            'work_id' => $this->work->id, 'catalog_id' => $fankha->id, 'library_id' => $this->library->id, 'shelfmark' => '1939',
            'catalog_volume_id' => $this->cited->id, 'volume_number' => 15, 'page_start' => 121, 'sequence_number' => 1,
            'raw_text' => 'قم؛ مسجد اعظم؛ شماره نسخه: 1939 [ف: 2-414]',
        ]);
    }

    protected function backfill(array $options = []): void
    {
        $this->artisan('sources:backfill', $options)->assertSuccessful();
    }

    public function test_catalogs_and_their_volumes_keep_their_ids()
    {
        $this->backfill(['--only' => ['registry']]);

        $fankha = Source::find(Catalog::where('code', 'fankha')->value('id'));
        $this->assertSame('fankha', $fankha->code);
        $this->assertSame(Source::KIND_UNION_CATALOGUE, $fankha->kind);
        $this->assertSame(34, $fankha->volumes()->count());
        $this->assertSame('fankha', $fankha->volumes()->where('volume_number', 15)->value('facsimile_code'));

        $cited = SourceVolume::find($this->cited->id);
        $this->assertSame($this->cited->title, $cited->title);
        $this->assertSame('فهرست نسخ خطی کتابخانه مسجد اعظم قم', $cited->source->title);
        $this->assertSame(Source::KIND_LIBRARY_CATALOGUE, $cited->source->kind);
        $this->assertSame(2, $cited->source->volumes()->count(), 'both volumes of the cited catalogue are one source');

        $edition = SourceVolume::find($this->edition->id);
        $this->assertSame('masjid-azam-ostadi', $edition->source->code);
        $this->assertTrue($edition->editionOf->is($cited));
        $this->assertTrue($edition->source->editionOf->is($cited->source));
        $this->assertSame('https://scan.example/1', $edition->source_url);

        $dharia = Source::where('code', 'dharia')->first();
        $this->assertSame(Source::KIND_BIBLIOGRAPHY, $dharia->kind);
        $this->assertSame(26, $dharia->volumes()->count());
        $this->assertSame(Source::KIND_BIOGRAPHICAL, Source::where('code', 'zirikli')->value('kind'));
        $this->assertSame('ستون', Source::where('code', 'kashf')->value('unit'));
        $this->assertLessThan($fankha->rank, $cited->source->rank, 'Fankha comes after the catalogues it was compiled from');
        $this->assertLessThan($fankha->rank, $dharia->rank);
    }

    public function test_fankha_entries_carry_their_text_and_the_place_it_was_taken_from()
    {
        $this->backfill();

        $entry = SourceEntry::where('citable_type', Work::class)->where('citable_id', $this->work->id)->where('origin', SourceEntry::ORIGIN_FANKHA)->sole();
        $this->assertTrue($entry->is_primary);
        $this->assertSame(1, $entry->source_id);
        $this->assertSame(15, $entry->sourceVolume->volume_number);
        $this->assertSame([15, 120, 121], [$entry->volume_number, $entry->page_start, $entry->page_end]);
        $this->assertSame($this->work->raw_text, $entry->entry_text);
        $this->assertSame([15, 120, SourceEntry::TEXT_AUTO], [$entry->text_volume_number, $entry->text_page, $entry->text_status]);
        $this->assertFalse($entry->textIsOutOfPlace());

        $copy = SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $this->manuscript->id)->where('origin', SourceEntry::ORIGIN_FANKHA)->sole();
        $this->assertSame($this->manuscript->raw_text, $copy->copy_text, "a manuscript's Fankha text is its own line");
        $this->assertNull($copy->entry_text);

        $copy->update(['page_start' => 122]);
        $this->assertTrue($copy->fresh()->textIsOutOfPlace());
    }

    public function test_catalogue_references_keep_their_review_and_text()
    {
        Ref::create(['manuscript_id' => $this->manuscript->id, 'catalog_volume_id' => $this->cited->id, 'volume_number' => 2, 'page_start' => 414, 'raw_text' => '[ف: 2-414]', 'source' => Ref::SOURCE_FANKHA]);
        $match = Ref::create([
            'manuscript_id' => $this->manuscript->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414,
            'shelfmark_page' => 415, 'entry_number' => 606, 'source' => Ref::SOURCE_AI_MATCH, 'match_method' => 'shelfmark_title', 'confidence' => 90,
            'entry_text' => 'دیوان قدسی، شعر', 'copy_text' => 'ش 1939، 162 برگ',
        ]);
        Ref::create(['manuscript_id' => $this->manuscript->id, 'catalog_volume_id' => null, 'raw_text' => '[رایانه]', 'source' => Ref::SOURCE_FANKHA]);

        $this->backfill();

        $cited = SourceEntry::where('legacy_type', 'catalog_reference')->where('origin', SourceEntry::ORIGIN_FANKHA_CITATION)->whereNotNull('source_volume_id')->sole();
        $this->assertSame('[ف: 2-414]', $cited->citation_text);
        $this->assertSame(SourceVolume::find($this->cited->id)->source_id, $cited->source_id);
        $this->assertSame(SourceEntry::TEXT_NONE, $cited->text_status);

        $entry = SourceEntry::where('legacy_type', 'catalog_reference')->where('legacy_id', $match->id)->sole();
        $this->assertSame([SourceEntry::ORIGIN_MATCH, 'shelfmark_title', 90, SourceEntry::STATUS_UNVERIFIED], [$entry->origin, $entry->match_method, $entry->confidence, $entry->status]);
        $this->assertSame([415, 606], [$entry->shelfmark_page, $entry->entry_number]);
        $this->assertSame(['دیوان قدسی، شعر', 'ش 1939، 162 برگ'], [$entry->entry_text, $entry->copy_text]);
        $this->assertSame([2, 414, SourceEntry::TEXT_AUTO], [$entry->text_volume_number, $entry->text_page, $entry->text_status]);

        $loose = SourceEntry::where('citation_text', '[رایانه]')->sole();
        $this->assertNull($loose->source_id, 'a citation of no registered source stays, by its text');
    }

    public function test_bibliography_strings_keep_their_order_and_the_places_they_cite()
    {
        $this->backfill();

        $rows = SourceEntry::where('legacy_type', 'work_bibliography')->where('legacy_id', $this->work->id)->orderBy('sort_order')->get();
        $this->assertSame(['الذریعة 101/25 و 107/8', 'دنا 2/207'], $rows->pluck('citation_text')->all());

        $dharia = Source::where('code', 'dharia')->value('id');
        $this->assertSame([$dharia, 25, 101], [$rows[0]->source_id, $rows[0]->volume_number, $rows[0]->page_start]);
        $this->assertSame(25, $rows[0]->sourceVolume->volume_number);
        $this->assertSame([
            ['source_id' => $dharia, 'volume' => 25, 'page' => 101],
            ['source_id' => $dharia, 'volume' => 8, 'page' => 107],
        ], $rows[0]->locations);
        $this->assertSame(SourceEntry::ROLE_CITATION, $rows[0]->role);
        $this->assertNull($rows[1]->source_id, 'دنا is not a source of the site yet');
    }

    public function test_a_second_run_changes_nothing_and_follows_the_old_tables()
    {
        $this->backfill();
        $count = SourceEntry::count();
        $sources = Source::count();
        $volumes = SourceVolume::count();

        $this->backfill();
        $this->assertSame([$count, $sources, $volumes], [SourceEntry::count(), Source::count(), SourceVolume::count()]);

        DB::table('works')->where('id', $this->work->id)->update(['raw_text' => 'متن تازه', 'bibliography' => json_encode(['الذریعة 101/25'])]);
        DB::table('manuscripts')->where('id', $this->manuscript->id)->delete();
        $this->backfill();

        $this->assertSame('متن تازه', SourceEntry::where('legacy_type', 'work')->where('legacy_id', $this->work->id)->value('entry_text'));
        $this->assertSame(1, SourceEntry::where('legacy_type', 'work_bibliography')->where('legacy_id', $this->work->id)->count(), 'the dropped string is gone');
        $this->assertSame(0, SourceEntry::where('legacy_type', 'manuscript')->where('legacy_id', $this->manuscript->id)->count(), 'the deleted record is gone');
    }

    public function test_a_dry_run_writes_nothing()
    {
        $this->backfill(['--dry-run' => true]);

        $this->assertSame(0, SourceEntry::count());
        $this->assertSame(0, Source::count());
    }

    public function test_rationales_become_the_researchers_evidence()
    {
        $suggestion = FieldSuggestion::create([
            'suggestable_type' => Manuscript::class, 'suggestable_id' => $this->manuscript->id, 'field_name' => 'copy_date_raw',
            'suggested_value' => '897', 'rationale_citation' => 'ترقیمه چنین خوانده می‌شود …',
        ]);
        DB::table('scholarly_annotations')->insert([
            'annotatable_type' => Manuscript::class, 'annotatable_id' => $this->manuscript->id, 'field_name' => 'copy_date_raw',
            'corrected_value' => '897', 'revision_category' => 'correction', 'citation_source' => 'ترقیمه چنین خوانده می‌شود …',
            'suggestion_id' => $suggestion->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->backfill(['--only' => ['evidence']]);

        $evidence = FieldEvidence::sole();
        $this->assertSame([FieldEvidence::KIND_RESEARCH, 'ترقیمه چنین خوانده می‌شود …', 'copy_date_raw'], [$evidence->kind, $evidence->note, $evidence->field_name]);
        $this->assertTrue($evidence->suggestion->is($suggestion));
        $this->assertTrue($evidence->subject->is($this->manuscript));
    }

    public function test_a_volume_title_loses_its_volume_part_only()
    {
        $this->assertSame('فهرست کتب خطی کتابخانه مرکزی آستان قدس رضوی', Backfill::stem('فهرست کتب خطی کتابخانه مرکزی آستان قدس رضوی - جلد دوم: کتب خطی'));
        $this->assertSame('فهرست نسخه‌های خطی کتابخانه‌های رشت و همدان', Backfill::stem('فهرست نسخه‌های خطی کتابخانه‌های رشت و همدان'));
    }
}
