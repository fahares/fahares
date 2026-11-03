<?php

namespace Tests\Feature;

use App\Console\Commands\LinkEarlierEditionCommand as Link;
use App\Console\Commands\MatchCatalogEditionCommand;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use Tests\TestCase;

/**
 * Fankha's citations of Monzavi's earlier «نسخه‌های خطی فارسی» tied to the فهرستواره's entries (item 116): by the
 * page the entry names and its heading, or by the heading alone.
 */
class LinkEarlierEditionTest extends TestCase
{
    private const PATTERN = 'نسخه(?:\x{200C}|\s)?های منزوی';

    protected SourceVolume $volume;

    protected function setUp(): void
    {
        parent::setUp();
        $source = Source::firstOrCreate(['code' => 'monzavi'], ['kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 30, 'title' => 'فهرستواره کتاب‌های فارسی']);
        $this->volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 5, 'facsimile_code' => 'monzavi-earlier-' . uniqid()]);
        $this->item(1, 3347, 'تحفة الملوک', '*تحفة الملوک. ن. در اخلاق. نسخه‌ها 490/1 (1 نسخه)؛ ریو 487.');
        $this->item(2, 3348, 'تحفة الملوک', '*تحفة الملوک. طافر کرمانی، در شراب. نسخه‌ها (2) 1709/2 و 1711/2؛ آستان قدس 117.');
        $this->item(3, 3400, 'شمع و پروانه', '*شمع و پروانه. عاقل خان رازی، میر عسکر (م 1108 ق). به نظم. نسخه‌ها 2971/4.');
        $this->item(4, 3500, 'جام جهان‌نما', '*جام جهان‌نما. غیاث الدّین منصور دشتکی (د 948 ق) فرزند صدر الدّین. نسخه‌ها ۷۶۵/۲؛ ملک.');
    }

    private function item(int $n, int $page, string $title, string $text): SourceItem
    {
        return SourceItem::create(['source_id' => $this->volume->source_id, 'source_volume_id' => $this->volume->id, 'volume_number' => 5,
            'item_key' => (string) $n, 'position' => $n, 'kind' => 'entry', 'page_start' => $page, 'page_end' => $page, 'title' => $title,
            'title_normalized' => MatchCatalogEditionCommand::norm($title), 'text' => $text, 'text_status' => 'auto']);
    }

    private function work(string $title, string $author, string $citation, ?int $sourceId = null): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'earlier-' . uniqid(), 'volume_number' => 1,
            'page_start' => 1, 'author_name' => $author]);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $sourceId, 'role' => SourceEntry::ROLE_CITATION,
            'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY, 'status' => SourceEntry::STATUS_VERIFIED, 'citation_text' => $citation]);

        return $work;
    }

    private function tie(Work $work): ?SourceEntry
    {
        return SourceEntry::where('legacy_type', Link::LEGACY_TYPE)->where('citable_id', $work->id)->first();
    }

    public function test_the_cited_pages_are_read_in_each_form_fankha_writes()
    {
        $this->assertSame([490], Link::citedPages('نسخه‌های منزوی 490/1', self::PATTERN));
        $this->assertSame([4369], Link::citedPages('نسخه‌های منزوی 4369', self::PATTERN));
        $this->assertSame([2809, 1263], Link::citedPages('نسخه‌های منزوی 2809/4 و 1263/2', self::PATTERN));
        $this->assertSame([1219, 1220], Link::citedPages('نسخه‌های منزوی 1219/2-1220', self::PATTERN));
        $this->assertSame([1191], Link::citedPages('نسخه‌های منزوی 2: 1191', self::PATTERN));
        $this->assertSame([765], Link::citedPages('نسخه‌های منزوی ۷۶۵/۲: نشانی ۱۲ نسخه', self::PATTERN));
        $this->assertSame([171], Link::citedPages('نسخه‌های منزوی ص 171', self::PATTERN));
        $this->assertSame([], Link::citedPages('فهرستواره منزوی 490/1', self::PATTERN));
    }

    public function test_the_index_keys_each_entry_by_the_pages_of_the_earlier_book_it_names()
    {
        $index = Link::addressIndex(SourceItem::where('source_volume_id', $this->volume->id)->get(), 'نسخه(?:\x{200C}|\s)?ها');
        $key = fn (int $position) => SourceItem::where('source_volume_id', $this->volume->id)->where('position', $position)->value('id');
        $this->assertSame([$key(1)], $index[490]);
        $this->assertSame([$key(2)], $index[1711]);
        $this->assertSame([$key(4)], $index[765]);
        $this->assertArrayNotHasKey(1, $index);
    }

    public function test_works_are_tied_by_the_named_page_and_heading_or_by_the_heading_and_author()
    {
        // the page and the heading: of the two «تحفة الملوک», the one naming 1709
        $tohfa = $this->work('تحفة الملوک', 'طافر کرمانی', 'نسخه‌های منزوی 1709/2 (2 نسخه)');
        // the heading and the author, and no page named
        $jam = $this->work('جام جهان نما', 'دشتکی شیرازی، منصور بن صدرالدین', 'نسخه‌های منزوی 9999/6');
        // the heading alone: another poet's «شمع و پروانه»
        $sham = $this->work('شمع و پروانه', 'اهلی شیرازی، محمد بن یوسف', 'نسخه‌های منزوی 2970/4');
        // a named page under another heading is no tie
        $other = $this->work('تبصرة القراء', 'قاری قائنی، محمد حسن', 'نسخه‌های منزوی 490/1');
        // a work the فهرستواره is cited for already is left alone
        $cited = $this->work('تحفة الملوک', '', 'نسخه‌های منزوی 490/1');
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $cited->id, 'source_id' => $this->volume->source_id, 'volume_number' => 5,
            'page_start' => 3347, 'role' => SourceEntry::ROLE_CITATION, 'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY,
            'status' => SourceEntry::STATUS_VERIFIED, 'citation_text' => 'فهرستواره منزوی 3347/5']);

        $this->artisan('sources:link-earlier-edition', ['source' => 'monzavi', 'edition' => 'nuskhe'])->assertSuccessful();

        $tie = $this->tie($tohfa);
        $this->assertSame([3348, Link::ADDRESS_TITLE, 95, SourceEntry::STATUS_UNVERIFIED, SourceEntry::ORIGIN_MATCH],
            [$tie->sourceItem->page_start, $tie->match_method, $tie->confidence, $tie->status, $tie->origin]);
        $this->assertSame('نسخه‌های منزوی 1709/2 (2 نسخه)', $tie->citation_text);
        $this->assertStringContainsString('طافر کرمانی', $tie->entry_text);
        $this->assertSame([3500, Link::TITLE, 70], [$this->tie($jam)->page_start, $this->tie($jam)->match_method, $this->tie($jam)->confidence]);
        $this->assertSame([Link::TITLE, 40], [$this->tie($sham)->match_method, $this->tie($sham)->confidence]);
        $this->assertNull($this->tie($other));
        $this->assertNull($this->tie($cited));

        // the work page shows the entry; the review queue lists the tie by its method
        $this->get(route('works.show', $tohfa))->assertOk()->assertSee('طافر کرمانی، در شراب', false);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.source-links.index', ['source' => 'monzavi', 'method' => Link::ADDRESS_TITLE]))
            ->assertOk()->assertSee('نشانی چاپ پیشین و عنوان', false)->assertSee('تحفة الملوک', false);

        // again: nothing changes, and a reviewed tie stays as the reviewer left it
        $this->tie($sham)->review($admin, SourceEntry::STATUS_REJECTED);
        $this->artisan('sources:link-earlier-edition', ['source' => 'monzavi', 'edition' => 'nuskhe'])
            ->expectsOutputToContain('created 0, updated 0, unchanged 2, kept_reviewed 1, removed 0')->assertSuccessful();
        $this->assertSame(SourceEntry::STATUS_REJECTED, $this->tie($sham)->status);
    }

    public function test_an_unknown_edition_fails()
    {
        $this->artisan('sources:link-earlier-edition', ['source' => 'monzavi', 'edition' => 'nothing'])->assertFailed();
    }
}
