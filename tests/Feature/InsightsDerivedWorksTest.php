<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Person;
use App\Models\Work;
use App\Support\Insights;
use App\Support\InsightsNotes;
use App\Support\InsightsScope;
use App\Support\InsightsTables;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The texts other works are made on (backlog item 162, step 14.350), read in the scope of the author of the texts. */
class InsightsDerivedWorksTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_text_is_ranked_by_the_works_made_on_it()
    {
        $author = Person::create(['name' => 'مؤلف آزمایشی متن پایه', 'normalized_name' => 'مؤلف آزمایشی متن پایه', 'slug' => 'insights-dv-' . uniqid(), 'is_author' => true]);
        $work = function (string $title, ?Person $by = null) {
            $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'insights-dv-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
            $work->forceFill(['author_id' => $by?->id])->save();

            return $work;
        };
        $link = fn (Work $made, Work $on, string $type) => DB::table('work_relations')->insert(['work_id' => $made->id, 'related_work_id' => $on->id, 'type' => $type, 'source' => 'auto']);
        [$law, $logic] = [$work('متن فقهی آزمایشی', $author), $work('متن منطقی آزمایشی', $author)];
        $language = Language::create(['name' => 'زبان آزمایشی ترجمه', 'slug' => 'insights-dv-' . uniqid()]);
        foreach (range(1, 3) as $i) {
            $link($work("شرح {$i} بر متن فقهی"), $law, 'commentary');
        }
        $link($work('حاشیه بر متن فقهی'), $law, 'gloss');
        $translation = $work('ترجمۀ متن فقهی');
        $translation->languages()->attach($language->id);
        $link($translation, $law, 'translation');
        $link($work('شرح متن منطقی'), $logic, 'commentary');
        $link($work('حاشیه 1 بر متن منطقی'), $logic, 'gloss');
        $link($work('حاشیه 2 بر متن منطقی'), $logic, 'gloss');
        // A plain «related» link is not a work made on the text, and a text of another author is out of the scope
        $link($work('اثر مرتبط'), $law, 'related');
        $link($work('شرح متن دیگری'), $work('متن مؤلفی دیگر'), 'commentary');

        $figures = Insights::build(InsightsScope::fromArray(['author' => $author->id]));
        $dv = $figures['derived'];

        $this->assertSame([2, 8, null], [$dv['texts'], $dv['made'], $dv['titled']]);
        $this->assertSame([['commentary', 4, 2], ['gloss', 3, 2], ['translation', 1, 1]], array_map(fn ($t) => [$t['key'], $t['made'], $t['texts']], $dv['types']));
        $this->assertEquals([['متن فقهی آزمایشی', 5, ['commentary' => 3, 'gloss' => 1, 'translation' => 1]], ['متن منطقی آزمایشی', 3, ['commentary' => 1, 'gloss' => 2]]],
            array_map(fn ($r) => [$r['title'], $r['count'], $r['types']], $dv['items']));
        $this->assertSame([['متن منطقی آزمایشی', 2], ['متن فقهی آزمایشی', 1]], array_map(fn ($r) => [$r['title'], $r['count']], $dv['lists']['gloss']));
        $this->assertSame([['زبان آزمایشی ترجمه', 1]], array_map(fn ($l) => [$l['name'], $l['count']], $dv['languages']));

        $note = InsightsNotes::for($figures)['derived'];
        $this->assertStringContainsString('نخستین ردیف «متن فقهی آزمایشی» از مؤلف آزمایشی متن پایه است: ۵ اثر بر پایۀ آن نوشته شده است', $note['examples'][0]);
        $this->assertStringContainsString('۸ اثر بر پایۀ ۲ متن نوشته شده است؛ ۴ اثر شرح است، بر ۲ متن.', $note['examples'][1]);
        $this->assertSame(['derived-types', 'derived-texts', 'derived-commentary', 'derived-gloss', 'derived-translation', 'translation-languages'],
            array_column(InsightsTables::for($figures)['derived'], 'file'));

        $this->get(route('insights.topic', ['topic' => 'works', 'author' => $author->id]))->assertOk()
            ->assertSee('id="derived"', false)
            ->assertSee('متن‌های پرشرح، پرحاشیه و پرترجمه')
            ->assertSee('۳ شرح · ۱ حاشیه · ۱ ترجمه')
            ->assertSee(e(route('works.show', $law->getRouteKey())), false)
            ->assertSee('data-derived-list="gloss"', false)
            ->assertSee('data-insights-table="derived-texts"', false);
    }

    public function test_the_whole_data_says_how_many_commentaries_are_linked_to_their_text()
    {
        $titled = Insights::build()['derived']['titled'];

        $this->assertSame(['works', 'linked', 'share'], array_keys($titled));
    }
}
