<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Support\Insights;
use App\Support\InsightsNotes;
use App\Support\InsightsScope;
use App\Support\InsightsTables;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** What the catalogues wrote of each copy (backlog item 162, step 14.348), read in the scope of one author. */
class InsightsCompletenessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_share_of_the_copies_with_each_datum_is_counted_in_all_and_in_each_library()
    {
        config(['insights.completeness_min_copies' => 2, 'insights.min_sample' => 3]);
        $author = Person::create(['name' => 'مؤلف آزمایشی کامل بودن', 'normalized_name' => 'مؤلف آزمایشی کامل بودن', 'slug' => 'insights-cp-' . uniqid(), 'is_author' => true]);
        $work = Work::create(['primary_title' => 'اثر آزمایشی کامل بودن', 'clean_title' => 'اثر آزمایشی کامل بودن', 'slug' => 'insights-cp-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $work->forceFill(['author_id' => $author->id])->save();
        $library = fn (string $name) => Library::create(['name' => $name, 'city' => 'قم', 'slug' => 'insights-cp-' . uniqid()]);
        [$full, $bare, $small] = [$library('کتابخانۀ پرداده'), $library('کتابخانۀ کم‌داده'), $library('کتابخانۀ تک‌نسخه')];
        $cataloger = fn (string $name) => Cataloger::create(['name' => $name, 'slug' => 'insights-cp-' . uniqid()]);
        [$careful, $brief] = [$cataloger('فهرست‌نگار دقیق آزمایشی'), $cataloger('فهرست‌نگار مختصرنویس آزمایشی')];
        $volume = \App\Models\CatalogVolume::create(['title' => 'فهرست آزمایشی کتابخانۀ پرداده']);
        $copy = function (Library $in, Cataloger $by, array $fields) use ($work) {
            $copy = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
                // the citation gives the copy the entry that holds its cataloger and catalogue volume (step 14.402)
                'metadata' => ['catalog_citation' => '[ف: 1 - 1]']]);
            $copy->forceFill(['library_id' => $in->id, 'cataloger_id' => $by->id] + $fields)->save();
        };
        $all = ['copy_date_from' => 1050, 'copy_date_to' => 1050, 'scribe_name' => 'کاتب آزمایشی', 'copy_place' => 'اصفهان', 'script_names' => 'نسخ',
            'folios' => 120, 'lines' => 17, 'dimensions' => '15×21سم', 'incipit_text' => 'الحمد لله', 'catalog_volume_id' => $volume->id];
        // Two copies with every datum; one with a count of pages for the folios, and no date, scribe or place
        $copy($full, $careful, $all);
        $copy($full, $careful, $all);
        $copy($full, $careful, ['pages' => 80, 'is_bita' => true, 'is_bika' => true] + array_diff_key($all, array_flip(['copy_date_from', 'copy_date_to', 'scribe_name', 'copy_place', 'folios'])));
        // A library whose catalogue gives little: one copy «بی‌کا», one the print says nothing of
        $copy($bare, $brief, ['copy_date_from' => 1100, 'copy_date_to' => 1100, 'is_bika' => true]);
        $copy($bare, $brief, ['script_names' => 'نستعلیق']);
        // A library below the least number of copies is in the whole, not in the table
        $copy($small, $brief, ['scribe_name' => 'کاتب دیگر']);

        $figures = Insights::build(InsightsScope::fromArray(['author' => $author->id]));
        $cp = $figures['completeness'];

        $this->assertSame(6, $cp['copies']);
        $this->assertSame(['date' => 3, 'scribe' => 3, 'place' => 2, 'script' => 4, 'extent' => 3, 'lines' => 3, 'size' => 3, 'incipit' => 3],
            array_column($cp['fields'], 'count', 'key'));
        $this->assertSame(['date' => ['stated' => 1, 'silent' => 2], 'scribe' => ['stated' => 2, 'silent' => 1]], $cp['absent']);
        $this->assertSame([
            ['کتابخانۀ پرداده', 3, ['date' => 66.7, 'scribe' => 66.7, 'place' => 66.7, 'script' => 100.0, 'extent' => 100.0, 'lines' => 100.0, 'size' => 100.0, 'incipit' => 100.0], 87.5],
            ['کتابخانۀ کم‌داده', 2, ['date' => 50.0, 'scribe' => 0.0, 'place' => 0.0, 'script' => 50.0, 'extent' => 0.0, 'lines' => 0.0, 'size' => 0.0, 'incipit' => 0.0], 12.5],
        ], array_map(fn ($r) => [$r['name'], $r['count'], $r['shares'], $r['mean']], $cp['libraries']));
        $this->assertSame([2, 'کتابخانۀ پرداده', 'کتابخانۀ کم‌داده'], [$cp['library_count'], $cp['fullest']['name'], $cp['barest']['name']]);
        $this->assertSame([['فهرست‌نگار دقیق آزمایشی', 3, 87.5], ['فهرست‌نگار مختصرنویس آزمایشی', 3, 12.5]],
            array_map(fn ($r) => [$r['name'], $r['count'], $r['mean']], $cp['catalogers']));
        $this->assertSame([['فهرست آزمایشی کتابخانۀ پرداده', 3, 87.5]], array_map(fn ($r) => [$r['title'], $r['count'], $r['mean']], $cp['volumes']));

        $note = InsightsNotes::for($figures)['completeness'];
        $this->assertStringContainsString('تاریخ کتابت ۳ نسخه (۵۰٪) آمده است؛ فهرست ۱ نسخه را «بی‌تا» خوانده و دربارۀ ۲ نسخه چیزی نگفته است.', $note['examples'][0]);
        $this->assertStringContainsString('در کتابخانۀ پرداده، قم بیشترین است (۸۷٫۵٪) و در کتابخانۀ کم‌داده، قم کمترین (۱۲٫۵٪)', $note['examples'][2]);
        $tables = InsightsTables::for($figures)['completeness'];
        $this->assertSame(['completeness', 'completeness-libraries', 'completeness-catalogers', 'completeness-volumes'], array_column($tables, 'file'));
        $this->assertSame(['کتابخانۀ پرداده', 'قم', 3, 66.7, 66.7, 66.7, 100.0, 100.0, 100.0, 100.0, 100.0, 87.5], $tables[1]['rows'][0]);

        $this->get(route('insights.topic', ['topic' => 'libraries', 'author' => $author->id]))->assertOk()
            ->assertSee('id="completeness"', false)
            ->assertSee('فهرست‌ها از هر نسخه چه نوشته‌اند')
            ->assertSee('«بی‌کا» در فهرست: ۲ نسخه؛ بی هیچ سخنی: ۱ نسخه', false)
            ->assertSee(e(route('libraries.show', $full->getRouteKey())), false)
            ->assertSee(e(route('catalogers.show', $careful->getRouteKey())), false)
            ->assertSee('data-insights-table="completeness-volumes"', false);
    }
}
