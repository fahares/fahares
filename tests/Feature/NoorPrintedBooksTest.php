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
 * Mushar's catalogues of printed books and Sarkis's Mu'jam al-Matbu'at (item 86, step 14.18): sources the site has as
 * text only, from Noor. The fixtures are Sarkis's columns 615-616 and the Arabic Mushar's column 30 as
 * scripts/sources/noor_printed_books.py writes them.
 */
class NoorPrintedBooksTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/noor-printed/';

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

    private function volume(string $code, string $name, int $number): SourceVolume
    {
        $source = Source::create(['code' => $code, 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => $name, 'short_name' => $name,
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
