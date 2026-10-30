<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kashf al-Zunun, Idah al-Maknun and Hadiyyat al-Arifin (item 86): column texts from Noor tied to the column scans
 * the site has. The fixtures are Kashf column 500 and Hadiyya volume 1 column 13 as scripts/sources/noor_istanbul.py
 * writes them.
 */
class IstanbulSourcesTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/istanbul/';

    public function test_kashf_entries_are_linked_to_the_works_cited_for_their_column()
    {
        $volume = $this->volume('kashf', 'کشف الظنون', 1, 1, 940);
        $this->artisan('sources:import-pages', ['volume' => 'kashf', 'pages' => self::FIXTURES . 'kashf-v01-c500.jsonl', '--volume' => 1])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'kashf', '--volume' => 1, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'kashf-v01-c500.entries.jsonl'])->assertSuccessful();

        $items = SourceItem::where('source_volume_id', $volume->id)->orderBy('position')->get();
        $this->assertCount(10, $items);
        $this->assertSame(['500#3', 'تنقيح المكنون من مباحث القانون'], [$items[2]->item_key, $items[2]->title]);
        // the parenthesis Noor kept from the print, which Tarajim 3 drops
        $this->assertStringContainsString('الحافظ (الشافعى) المتوفى', $items[0]->text);
        // the last entry turns to column 501, which the fixture lacks
        $this->assertSame([500, 501], [$items[9]->page_start, $items[9]->page_end]);
        $this->assertSame(['catalog' => 'kashf', 'catalogName' => 'کشف الظنون', 'volume' => 1, 'page' => 500], $items[0]->viewer());
        $this->assertSame(1, DB::table('source_pages')->where('source_volume_id', $volume->id)->count());

        $fuhum = $this->work('kashf', 'تنقیح الفهوم فی صیغ العموم', 'علائی، خلیل بن کیکلدی', 761, 1, 500, 'کشف الظنون 500/1');
        $this->artisan('sources:link-items', ['volume' => 'kashf', '--volume' => 1])->assertSuccessful();
        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $fuhum->id)->sole();
        $this->assertSame(['500#1', SourceEntry::ROLE_ENTRY, SourceEntry::STATUS_UNVERIFIED], [$tie->sourceItem->item_key, $tie->role, $tie->status]);

        // the work page: the entry's text, its column and the column scan
        $this->get(route('works.show', $fuhum))->assertOk()
            ->assertSee('کشف الظنون، ج ۱، ستون ۵۰۰', false)
            ->assertSee('صلاح الدين خليل بن كيكلدى', false)
            ->assertSee($tie->sourceItem->url(), false);
        $this->get('/sources/kashf/1/500-1')->assertOk()->assertSee('تنقيح الفهوم', false)->assertSee('ج ۱، ستون ۵۰۰', false);
    }

    public function test_a_model_decision_stands_only_with_its_evidence_in_the_entry()
    {
        $this->volume('kashf', 'کشف الظنون', 1, 1, 940);
        $this->artisan('sources:import-pages', ['volume' => 'kashf', 'pages' => self::FIXTURES . 'kashf-v01-c500.jsonl', '--volume' => 1])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'kashf', '--volume' => 1, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'kashf-v01-c500.entries.jsonl'])->assertSuccessful();
        $maknun = $this->work('kashf', 'مختصر القانون', 'مجهول', 900, 1, 500, 'کشف الظنون 500/1');
        $made = $this->work('kashf', 'شرح التنقیح', 'مجهول', 900, 1, 500, 'کشف الظنون 500/1');
        $citation = fn (Work $w) => SourceEntry::where('citable_id', $w->id)->where('origin', SourceEntry::ORIGIN_BIBLIOGRAPHY)->value('id');
        $file = tempnam(sys_get_temp_dir(), 'llm');
        file_put_contents($file, json_encode([
            ['citation_id' => $citation($maknun), 'index' => 0, 'role' => 'mention', 'key' => '500#3', 'evidence' => 'اختصر القانون في الطب و سماه المكنون', 'confidence' => 85],
            // evidence the entry does not have
            ['citation_id' => $citation($made), 'index' => 0, 'role' => 'mention', 'key' => '500#3', 'evidence' => 'شرح التنقيح للخجندى', 'confidence' => 90],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('sources:link-items', ['volume' => 'kashf', '--volume' => 1, '--decisions' => $file])->assertSuccessful();
        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $maknun->id)->sole();
        $this->assertSame(['500#3', SourceEntry::ROLE_MENTION, 'llm', 70, SourceEntry::STATUS_UNVERIFIED],
            [$tie->sourceItem->item_key, $tie->role, $tie->match_method, $tie->confidence, $tie->status]);
        $this->assertFalse(SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $made->id)->exists());
        $this->get(route('works.show', $maknun))->assertOk()->assertSee('یاد شده در کشف الظنون، ج ۱، ستون ۵۰۰', false)
            ->assertSee('یک مدل زبانی این پیوند را', false);
        unlink($file);
    }

    public function test_a_hadiyya_entry_is_an_author_and_his_works_are_named_in_it()
    {
        $volume = $this->volume('hadiyya', 'هدیة العارفین', 1, 1, 842);
        $this->artisan('sources:import-pages', ['volume' => 'hadiyya', 'pages' => self::FIXTURES . 'hadiyya-v01-c13.jsonl', '--volume' => 1])->assertSuccessful();
        $this->artisan('sources:segment', ['volume' => 'hadiyya', '--volume' => 1, '--origin' => 'noor',
            '--entries' => self::FIXTURES . 'hadiyya-v01-c13.entries.jsonl'])->assertSuccessful();
        $this->assertSame('الطبرى', SourceItem::where('source_volume_id', $volume->id)->where('item_key', '13#8')->value('title'));

        $tasaiyat = $this->work('hadiyya', 'التساعیات', 'طبری، ابراهیم بن محمد', 722, 1, 13, 'هدیة العارفین 13/1');
        $this->artisan('sources:link-items', ['volume' => 'hadiyya', '--volume' => 1])->assertSuccessful();
        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->where('citable_id', $tasaiyat->id)->sole();
        $this->assertSame(['13#8', SourceEntry::ROLE_MENTION], [$tie->sourceItem->item_key, $tie->role]);
    }

    public function test_a_commentary_told_inside_the_entry_of_its_book_is_a_mention()
    {
        $items = [
            ['id' => 1, 'entry_number' => null, 'title' => 'الجامع الصحيح', 'text' => "الجامع الصحيح\n- للامام البخارى. " . str_repeat('و هو اصح الكتب بعد كتاب اللّه تعالى. ', 10) . "و من شروحه فتح البارى للحافظ شهاب الدين احمد بن على بن حجر العسقلانى المتوفى سنة 852 اثنتين و خمسين و ثمانمائة. و شرحه الكرمانى."],
            ['id' => 2, 'entry_number' => null, 'title' => 'الجامع الصغير', 'text' => "الجامع الصغير\n- للسيوطى المتوفى سنة 911."],
        ];
        $match = (new \App\Sources\Bibliography\ItemMatcher)->match(['فتح الباری فی شرح صحیح البخاری'], 'ابن حجر عسقلانی، احمد بن علی', 852, $items);
        $this->assertSame([1, SourceEntry::ROLE_MENTION, 'author_in_entry'], [$match['id'], $match['role'], $match['method']]);
        // the name without the year, or the year in another sentence, is not enough
        $this->assertNull((new \App\Sources\Bibliography\ItemMatcher)->match(['شرح البخاری'], 'ابن حجر عسقلانی، احمد بن علی', 900, $items));
    }

    private function volume(string $code, string $title, int $number, int $first, int $last): SourceVolume
    {
        $source = Source::create(['code' => $code, 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => $title,
            'short_name' => $title, 'unit' => 'ستون', 'metadata' => ['config' => $code]]);

        return SourceVolume::create(['source_id' => $source->id, 'volume_number' => $number, 'facsimile_code' => $code, 'page_first' => $first, 'page_last' => $last]);
    }

    private function work(string $code, string $title, string $author, int $death, int $volume, int $page, string $citation): Work
    {
        $person = \App\Models\Person::create(['name' => $author, 'normalized_name' => $author, 'slug' => 'p-' . uniqid(), 'death_year_hijri' => $death]);
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'w-' . uniqid(), 'volume_number' => 1,
            'page_start' => 1, 'author_name' => $author, 'author_id' => $person->id]);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => Source::where('code', $code)->value('id'),
            'volume_number' => $volume, 'page_start' => $page, 'role' => SourceEntry::ROLE_CITATION, 'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY,
            'status' => SourceEntry::STATUS_VERIFIED, 'citation_text' => $citation]);

        return $work;
    }
}
