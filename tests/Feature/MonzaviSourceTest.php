<?php

namespace Tests\Feature;

use App\Console\Commands\LinkSourceItemsCommand;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Support\BibliographyLinks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Monzavi's «فهرستواره کتاب‌های فارسی» (item 86): a source the site has as text only, from Noor, with no page images.
 * The fixtures are volume 6 pp. 360-362 as scripts/sources/noor_monzavi.py writes them.
 */
class MonzaviSourceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/monzavi/';

    public function test_citations_find_their_volume_and_are_not_links()
    {
        $cite = fn (string $s) => array_map(fn ($c) => [$c['volume'], $c['page']], array_filter(BibliographyLinks::citations($s), fn ($c) => $c['source'] === 'monzavi'));

        $this->assertSame([[5, 3366]], array_values($cite('فهرستواره منزوی 3366/5')));
        $this->assertSame([[10, 85], [7, 15]], array_values($cite('فهرستواره منزوی 85/10 و 15/7')));
        $this->assertSame([[4, 2761]], array_values($cite('فهرستواره منزوی 4: 2761')));
        $this->assertSame([[6, 56]], array_values($cite('فهرستواره کتابهای فارسی: 56/6')));
        $this->assertSame([[1, 128], [10, 780]], array_values($cite('فهرستواره 128/1 و 780/10 به نام حجازیه')));
        // pages 1-5 run on: «3257/4» is past volume 4's last page and in volume 5; a bare page in one volume only
        $this->assertSame([[5, 3257]], array_values($cite('فهرستواره منزوی 3257/4')));
        $this->assertSame([[5, 3259]], array_values($cite('فهرستواره منزوی 3259')));
        // a bare page five volumes have, a page no volume has, other books, and «منزوی» alone
        $this->assertSame([], $cite('فهرستواره منزوی 125'));
        $this->assertSame([[3, 1529]], array_values($cite('فهرستواره منزوی 1529/3 و 16676/3')));
        $this->assertSame([], $cite('فهرستواره مینوی ص 77'));
        $this->assertSame([], $cite('منزوی 977/2'));

        $html = (string) BibliographyLinks::html('الذریعة 2/1؛ فهرستواره منزوی 361/6');
        $this->assertStringContainsString('فهرستواره منزوی ۳۶۱/۶', $html);
        $this->assertSame(1, substr_count($html, '<a '));
    }

    public function test_the_registry_makes_the_source_with_its_volumes_and_no_images()
    {
        $this->artisan('sources:backfill', ['--only' => ['registry']])->assertSuccessful();

        $source = Source::where('code', 'monzavi')->sole();
        $this->assertSame([Source::KIND_BIBLIOGRAPHY, 'فهرستواره منزوی', 'احمد منزوی', false],
            [$source->kind, $source->short_name, $source->compiler, $source->hasPageImages()]);
        $volumes = $source->volumes()->get()->keyBy('volume_number');
        $this->assertCount(10, $volumes);
        $this->assertSame(['monzavi', 3255, 4173], [$volumes[5]->facsimile_code, $volumes[5]->page_first, $volumes[5]->page_last]);
        $this->assertTrue(Source::where('code', 'dharia')->sole()->hasPageImages());
    }

    public function test_entries_from_the_file_are_linked_to_the_works_fankha_cites_for_them()
    {
        $volume = $this->volume();
        $this->artisan('sources:import-pages', ['volume' => 'monzavi', 'pages' => self::FIXTURES . 'v06-p360-362.jsonl', '--volume' => 6])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'monzavi', '--volume' => 6, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'v06-p360-362.entries.jsonl'])->assertSuccessful();

        $items = SourceItem::where('source_volume_id', $volume->id)->orderBy('position')->get();
        $this->assertSame(['360#7', '361#1', '361#2', '361#3'], $items->pluck('item_key')->all());
        $this->assertSame(['تحفة الامیر', 360, 361], [$items[0]->title, $items[0]->page_start, $items[0]->page_end]);
        $this->assertSame(361, $items[0]->pageSegments()[1]['page']);
        $this->assertNull($items[2]->viewer());
        $this->assertSame(3, DB::table('source_pages')->where('source_volume_id', $volume->id)->count());

        $ayadi = $this->work('تحفة الایادی', 'رشتی اصفهانی، محسن بن محمد رفیع', 'فهرستواره منزوی 361/6');
        // Fankha's page is the one before the entry's; only --near finds it
        $aimma = $this->work('تحفة الائمة العلیة فی الحکمة العملیة', 'سبزواری، فتح الله بن احمد', 'فهرستواره منزوی 360/6');
        $this->artisan('sources:link-items', ['volume' => 'monzavi', '--volume' => 6])->assertSuccessful();
        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $ayadi->id)->sole();
        $this->assertSame(['361#2', SourceEntry::ROLE_ENTRY, SourceEntry::STATUS_UNVERIFIED], [$tie->sourceItem->item_key, $tie->role, $tie->status]);
        $this->assertNotSame('361#3', SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $aimma->id)->first()?->sourceItem?->item_key);

        $this->artisan('sources:link-items', ['volume' => 'monzavi', '--volume' => 6, '--near' => true])->assertSuccessful();
        $near = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $aimma->id)->sole();
        $this->assertSame(['361#3', 60], [$near->sourceItem->item_key, $near->confidence]);
        $this->assertStringStartsWith('near_', $near->match_method);

        // the work page: the entry's text and its page, no page image or viewer
        $this->get(route('works.show', $ayadi))->assertOk()
            ->assertSee('فهرستواره منزوی، ج ۶، ص ۳۶۱', false)
            ->assertSee('همان سراینده و نویسندۀ پرکاری', false)
            ->assertSee($tie->sourceItem->url(), false)
            ->assertDontSee('دیدن برگه در فهرستواره', false)
            ->assertDontSee('catalogs/monzavi', false);
        $this->get('/sources/monzavi/6/361-2')->assertOk()->assertSee('تحفة الایادی', false);
    }

    public function test_title_forms_add_the_translation_heading_and_the_quoted_title()
    {
        $this->assertSame(['منهاج البیان (ترجمه)', 'ترجمه منهاج البیان', 'مقداریه'],
            LinkSourceItemsCommand::titleForms(['منهاج البیان (ترجمه)', null], 'فهرستواره منزوی 2745/4 «مقداریه»'));
    }

    private function volume(): SourceVolume
    {
        $source = Source::create(['code' => 'monzavi', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'فهرستواره کتاب‌های فارسی',
            'short_name' => 'فهرستواره منزوی', 'metadata' => ['config' => 'monzavi', 'images' => false]]);

        return SourceVolume::create(['source_id' => $source->id, 'volume_number' => 6, 'facsimile_code' => 'monzavi', 'page_first' => 1, 'page_last' => 910]);
    }

    private function work(string $title, string $author, string $citation): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'w-' . uniqid(), 'volume_number' => 1,
            'page_start' => 1, 'author_name' => $author]);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => Source::where('code', 'monzavi')->value('id'),
            'volume_number' => 6, 'page_start' => (int) explode('/', preg_replace('/^\D+/u', '', $citation))[0], 'role' => SourceEntry::ROLE_CITATION, 'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY,
            'status' => SourceEntry::STATUS_VERIFIED, 'citation_text' => $citation]);

        return $work;
    }
}
