<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Support\BibliographyLinks;
use Tests\TestCase;

/**
 * Mushar's catalogues of printed books and Sarkis's Mu'jam al-Matbu'at (step 14.18), and the books of lives A'yan
 * al-Shi'a and Rawdat al-Jannat (step 14.20): sources the site has as text only, from Noor (item 86). The fixtures are
 * Sarkis's columns 615-616, the Arabic Mushar's column 30, Rawdat's volume 4 pp. 399-406, Kalam's volume 1 pp. 125-127 and
 * Fuqaha's volume 14 pp. 861-862 as
 * scripts/sources/noor_reference_books.py writes them.
 */
class NoorReferenceBooksTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/noor-reference/';

    public function test_citations_find_their_source_volume_and_column()
    {
        $cite = fn (string $s) => array_map(fn ($c) => [$c['source'], $c['volume'], $c['page']], BibliographyLinks::citations($s));

        $this->assertSame([['mushar-fa', 2, 2558]], $cite('فهرست فارسی مشار 2558/2'));
        $this->assertSame([['mushar-fa', 1, 299]], $cite('مشار، فارسی 299/1 نشانی 3چ'));
        $this->assertSame([['mushar-fa', 2, 2558], ['mushar-ar', 1, 989]], $cite('مشار فارسی 2558/2، مشار عربی 989'));
        // a Persian column without its volume, or with a volume that does not hold it, is of an earlier printing
        $this->assertSame([], $cite('مشار 591'));
        $this->assertSame([], $cite('مشار فارسی 724/2'));
        $this->assertSame([['mushar-ar', 1, 259]], $cite('مشار، عربی، 259 «جوامع الکلم» در 2 مجلد'));
        $this->assertSame([['mushar-ar', 1, 624]], $cite('مشار عربی 624-625'));
        $this->assertSame([], $cite('مشار، مؤلفین 5/726'));

        $this->assertSame([['sarkis', 1, 615]], $cite('معجم المطبوعات العربية 615/1'));
        $this->assertSame([['sarkis', 1, 1001]], $cite('سرکیس، معجم 1001/1'));
        $this->assertSame([['sarkis', 2, 1611]], $cite('معجم المطبوعات 1611'));
        $this->assertSame([['sarkis', 2, 1536]], $cite('معجم المطبوعات 1536-1537'));
        $this->assertSame([['sarkis', 2, 1897]], $cite('معجم المطبوعات سرکیس ج2ص1897'));
        $this->assertSame([], $cite('معجم المطبوعات العربية في ايران 472'));
        $this->assertSame([], $cite('معجم المطبوعات النجفیة ص 126'));

        // the column books' dashes keep their meaning
        $this->assertSame([['kashf', 1, 93], ['hadiyya', 2, 6]], $cite('کشف الظنون 1-93، هدیة العارفین 2-6'));

        $html = (string) BibliographyLinks::html('سرکیس، معجم 615/1');
        $this->assertStringContainsString('/sources/sarkis/1/page/615', $html);
    }

    public function test_the_registry_makes_the_sources_with_their_volumes_and_no_images()
    {
        $this->artisan('sources:backfill', ['--only' => ['registry']])->assertSuccessful();

        $sources = Source::whereIn('code', ['mushar-fa', 'mushar-ar', 'sarkis'])->get()->keyBy('code');
        $this->assertCount(3, $sources);
        $this->assertSame([Source::KIND_BIBLIOGRAPHY, 'معجم المطبوعات', 'یوسف الیان سرکیس', 'ستون', false],
            [$sources['sarkis']->kind, $sources['sarkis']->short_name, $sources['sarkis']->compiler, $sources['sarkis']->unit, $sources['sarkis']->hasPageImages()]);
        $volumes = $sources['mushar-fa']->volumes()->get()->keyBy('volume_number');
        $this->assertCount(5, $volumes);
        $this->assertSame(['mushar-fa', 2861, 3976], [$volumes[3]->facsimile_code, $volumes[3]->page_first, $volumes[3]->page_last]);
    }

    public function test_sarkis_entries_are_authors_with_their_books_and_a_book_is_linked_to_its_work()
    {
        $volume = $this->volume('sarkis', 'معجم المطبوعات', 1);
        $this->artisan('sources:import-pages', ['volume' => 'sarkis', 'pages' => self::FIXTURES . 'sarkis-v01-c615-616.jsonl', '--volume' => 1])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'sarkis', '--volume' => 1, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'sarkis-v01-c615-616.entries.jsonl'])->assertSuccessful();

        $items = SourceItem::where('source_volume_id', $volume->id)->get()->keyBy('item_key');
        $this->assertSame(['البيروني «أبو الريحان»', SourceItem::KIND_ENTRY], [$items['615#1']->title, $items['615#1']->kind]);
        $this->assertSame(['الآثار الباقية عن القرون الخالية', SourceItem::KIND_SUB_ENTRY, $items['615#1']->id],
            [$items['615#2']->title, $items['615#2']->kind, $items['615#2']->parent_id]);
        $this->assertNull($items['615#2']->viewer());

        $work = $this->work('sarkis', 'الآثار الباقية عن القرون الخالية', 'ابوریحان بیرونی، محمد بن احمد', 1, 615, 'معجم المطبوعات العربية 615/1');
        $this->artisan('sources:link-items', ['volume' => 'sarkis', '--volume' => 1, '--near' => true])->assertSuccessful();
        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $work->id)->sole();
        $this->assertSame(['615#2', SourceEntry::ROLE_ENTRY, SourceEntry::STATUS_UNVERIFIED], [$tie->sourceItem->item_key, $tie->role, $tie->status]);

        $this->get(route('works.show', $work))->assertOk()
            ->assertSee('معجم المطبوعات', false)
            ->assertSee($tie->sourceItem->url(), false)
            ->assertDontSee('catalogs/sarkis', false);
        $this->get('/sources/sarkis/1/page/615')->assertOk()->assertSee('الآثار الباقية', false);
    }

    public function test_a_mushar_entry_is_linked_by_its_title()
    {
        $volume = $this->volume('mushar-ar', 'مشار عربی', 1);
        $this->artisan('sources:import-pages', ['volume' => 'mushar-ar', 'pages' => self::FIXTURES . 'mushar-ar-v01-c30.jsonl', '--volume' => 1])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'mushar-ar', '--volume' => 1, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'mushar-ar-v01-c30.entries.jsonl'])->assertSuccessful();
        $this->assertSame(8, SourceItem::where('source_volume_id', $volume->id)->count());

        $work = $this->work('mushar-ar', 'اخبار القضاة', 'وکیع، محمد بن خلف', 1, 30, 'مشار عربی 30');
        $this->artisan('sources:link-items', ['volume' => 'mushar-ar', '--volume' => 1])->assertSuccessful();
        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $work->id)->sole();
        $this->assertSame(['30#4', SourceEntry::ROLE_ENTRY], [$tie->sourceItem->item_key, $tie->role]);
    }

    public function test_lives_are_cited_with_their_volume_only()
    {
        $cite = fn (string $s) => array_map(fn ($c) => [$c['source'], $c['volume'], $c['page']], BibliographyLinks::citations($s));

        $this->assertSame([['ayan', 9, 408], ['ayan', 10, 48]], $cite('أعیان الشیعة: 408/9 و 48/10'));
        // a part of the old printing in 56 parts, other books, and Rawdat's one-volume lithograph
        $this->assertSame([], $cite('اعیان الشیعه 223/45'));
        $this->assertSame([], $cite('مستدرکات اعیان الشیعه 12/3'));
        $this->assertSame([['rawdat', 4, 88]], $cite('روضات 88/4'));
        $this->assertSame([], $cite('روضات الجنات 409'));
        $this->assertSame([], $cite('روضات الجنان 12/2'));
    }

    public function test_a_work_is_tied_to_its_authors_life_as_a_mention()
    {
        $volume = $this->volume('rawdat', 'روضات الجنات', 4, Source::KIND_BIOGRAPHICAL);
        $this->artisan('sources:import-pages', ['volume' => 'rawdat', 'pages' => self::FIXTURES . 'rawdat-v04-p400.jsonl', '--volume' => 4])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'rawdat', '--volume' => 4, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'rawdat-v04-p400.entries.jsonl'])->assertSuccessful();
        $life = SourceItem::where('source_volume_id', $volume->id)->sole();
        $this->assertSame([422, 399, 406], [$life->entry_number, $life->page_start, $life->page_end]);
        $this->assertStringStartsWith('النور الجلى', $life->title);

        // the life names the work; a work of another author on the page is not tied
        $named = $this->work('rawdat', 'الاستظهار', 'طباطبائی کربلائی، علی بن محمد علی', 4, 400, 'روضات الجنات 400/4');
        $other = $this->work('rawdat', 'المعجم الكبير', 'طبرانی، سلیمان بن احمد', 4, 400, 'روضات الجنات 400/4');
        $this->artisan('sources:link-items', ['volume' => 'rawdat', '--volume' => 4, '--near' => true])->assertSuccessful();
        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $named->id)->sole();
        $this->assertSame([$life->id, SourceEntry::ROLE_MENTION, 'author_life_title', 90],
            [$tie->source_item_id, $tie->role, $tie->match_method, $tie->confidence]);
        $this->assertFalse(SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $other->id)->exists());
    }

    public function test_kahhalas_lives_from_the_old_noor_text_tie_a_work_to_its_author()
    {
        // step 14.31: the old program's text marks no heading; a name line before a line of dates opens a life, and a
        // Latin passage it could not read is «[…]»
        $volume = $this->volume('kahhala', 'معجم المؤلفین', 6, Source::KIND_BIOGRAPHICAL);
        $this->artisan('sources:import-pages', ['volume' => 'kahhala', 'pages' => self::FIXTURES . 'kahhala-v06-p100.jsonl', '--volume' => 6])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'kahhala', '--volume' => 6, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'kahhala-v06-p100.entries.jsonl'])->assertSuccessful();
        $items = SourceItem::where('source_volume_id', $volume->id)->get()->keyBy('item_key');
        $this->assertCount(6, $items);
        $this->assertSame('عبد اللّه التازي', $items['100#3']->title);
        $this->assertStringContainsString('ط […]', $items['100#3']->text);
        $this->assertStringNotContainsString('ءء', $items['100#3']->text);

        $work = $this->work('kahhala', 'المناظرة و المعارضة فی رد الرافضة', 'تازی، عبدالله بن فارس', 6, 100, 'معجم المؤلفین 100/6');
        $this->artisan('sources:link-items', ['volume' => 'kahhala', '--volume' => 6])->assertSuccessful();
        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $work->id)->sole();
        $this->assertSame(['100#3', SourceEntry::ROLE_MENTION, 'author_life_title'], [$tie->sourceItem->item_key, $tie->role, $tie->match_method]);
    }

    public function test_kalam_turaths_numbered_works_are_told_apart_by_their_author()
    {
        // step 14.35: a work is a numbered heading, «303. اثبات الرجعة»; six works of that title are on the page
        $this->assertSame([['kalam-turath', 1, 126]], array_map(fn ($c) => [$c['source'], $c['volume'], $c['page']],
            BibliographyLinks::citations('معجم التراث الکلامی 126/1')));
        $this->assertSame([['kalam-turath', 1, 79]], array_map(fn ($c) => [$c['source'], $c['volume'], $c['page']],
            BibliographyLinks::citations('معجم التراث الکلامی 1/79')));
        $volume = $this->volume('kalam-turath', 'معجم التراث الکلامی', 1);
        $this->artisan('sources:import-pages', ['volume' => 'kalam-turath', 'pages' => self::FIXTURES . 'kalam-turath-v01-p126.jsonl', '--volume' => 1])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'kalam-turath', '--volume' => 1, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'kalam-turath-v01-p126.entries.jsonl'])->assertSuccessful();
        $this->assertSame(303, SourceItem::where('source_volume_id', $volume->id)->where('item_key', '126#1')->value('entry_number'));

        $work = $this->work('kalam-turath', 'اثبات الرجعة', 'ابن شاذان، فضل بن شاذان نیشابوری', 1, 126, 'معجم التراث الکلامی 126/1');
        $this->artisan('sources:link-items', ['volume' => 'kalam-turath', '--volume' => 1])->assertSuccessful();
        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $work->id)->sole();
        $this->assertSame(['126#1', SourceEntry::ROLE_ENTRY, 'title_author'], [$tie->sourceItem->item_key, $tie->role, $tie->match_method]);
    }

    public function test_a_fuqaha_life_headed_by_a_nisba_is_found_by_the_name_at_its_opening()
    {
        // step 14.35: «الكلانتري 1236 - 1304 ه» opens «موسى بن فضل اللّه …»; only a source with text.openings reads it
        $this->assertSame([], BibliographyLinks::citations('طبقات الفقهاء 92'));
        $volume = $this->volume('fuqaha', 'موسوعة طبقات الفقهاء', 14, Source::KIND_BIOGRAPHICAL);
        $this->artisan('sources:import-pages', ['volume' => 'fuqaha', 'pages' => self::FIXTURES . 'fuqaha-v14-p861.jsonl', '--volume' => 14])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'fuqaha', '--volume' => 14, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'fuqaha-v14-p861.entries.jsonl'])->assertSuccessful();
        $life = SourceItem::where('source_volume_id', $volume->id)->sole();
        $this->assertSame(4942, $life->entry_number);

        $work = $this->work('fuqaha', 'تقریرات', 'حسینی، موسی بن فضل الله', 14, 861, 'موسوعة طبقات الفقهاء 861/14');
        $this->artisan('sources:link-items', ['volume' => 'fuqaha', '--volume' => 14])->assertSuccessful();
        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $work->id)->sole();
        $this->assertSame([$life->id, SourceEntry::ROLE_MENTION], [$tie->source_item_id, $tie->role]);
        $this->assertStringStartsWith('author_opening', $tie->match_method);

        // without the option the same life is not found: its heading does not name him
        config(['bibliography.sources.fuqaha.text.openings' => false]);
        SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->delete();
        $this->artisan('sources:link-items', ['volume' => 'fuqaha', '--volume' => 14])->assertSuccessful();
        $this->assertFalse(SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $work->id)->exists());
    }

    private function volume(string $code, string $name, int $number, string $kind = Source::KIND_BIBLIOGRAPHY): SourceVolume
    {
        $source = Source::create(['code' => $code, 'kind' => $kind, 'rank' => Source::KIND_RANKS[$kind], 'title' => $name, 'short_name' => $name,
            'unit' => 'ستون', 'metadata' => ['config' => $code, 'images' => false]]);

        return SourceVolume::create(['source_id' => $source->id, 'volume_number' => $number, 'facsimile_code' => $code, 'page_first' => 1, 'page_last' => 1144]);
    }

    private function work(string $code, string $title, string $author, int $volume, int $page, string $citation): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'w-' . uniqid(), 'volume_number' => 1,
            'page_start' => 1, 'author_name' => $author]);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => Source::where('code', $code)->value('id'),
            'volume_number' => $volume, 'page_start' => $page, 'role' => SourceEntry::ROLE_CITATION, 'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY,
            'status' => SourceEntry::STATUS_VERIFIED, 'citation_text' => $citation]);

        return $work;
    }
}
