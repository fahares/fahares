<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Support\PersonPlaces;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «حضور در شهرها» on a person's page: the places and years their dated copies and works give (item 139, step 14.207)
 */
class PersonPlacesTest extends TestCase
{
    private Library $library;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانۀ مکان آزمایشی', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
    }

    private function person(string $name): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'place-' . md5($name . uniqid())]);
    }

    private function work(string $title, Person $author, array $columns = []): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'place-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        if ($columns) {
            DB::table('works')->where('id', $work->id)->update($columns);
        }
        DB::table('work_people')->insert(['work_id' => $work->id, 'person_id' => $author->id, 'role' => 'author', 'position' => 0]);

        return $work;
    }

    private function copy(Work $work, Person $scribe, array $columns): int
    {
        $n = ++$this->sequence;
        $copy = Manuscript::create(['work_id' => $work->id, 'sequence_number' => $n, 'volume_number' => 1, 'page_start' => 1, 'library_id' => $this->library->id,
            'library' => $this->library->name, 'city' => 'قم', 'shelfmark' => "مکان {$n}", 'raw_text' => "{$n}. قم"]);
        DB::table('manuscripts')->where('id', $copy->id)->update($columns);
        DB::table('manuscript_people')->insert(['manuscript_id' => $copy->id, 'person_id' => $scribe->id, 'role' => 'scribe', 'position' => 0]);

        return $copy->id;
    }

    public function test_places_come_from_dated_copies_and_works_in_the_order_the_person_is_first_seen()
    {
        $other = $this->person('مؤلف آزمایشی مکان');
        $person = $this->person('کاتب و مؤلف آزمایشی مکان');
        $base = $this->work('اثر دیگری آزمایشی مکان', $other);
        // a scribe: one year, a duration, and what says no year or is not sure
        $first = $this->copy($base, $person, ['copy_place' => 'شیراز', 'copy_date_from' => 1060, 'copy_date_to' => 1060]);
        $this->copy($base, $person, ['copy_place' => 'اصفهان', 'copy_date_from' => 1052, 'copy_date_to' => 1057, 'copy_date_kind' => 'span']);
        $this->copy($base, $person, ['copy_place' => 'قزوین', 'copy_date_from' => 1001, 'copy_date_to' => 1100, 'copy_date_kind' => 'between']);
        $this->copy($base, $person, ['copy_place' => 'تبریز', 'copy_date_from' => 1040, 'copy_date_to' => 1045]);
        $this->copy($base, $person, ['copy_place' => 'کاشان', 'copy_date_from' => 1050, 'copy_date_to' => 1050, 'copy_date_certainty' => 'doubtful']);
        $this->copy($base, $person, ['copy_place' => 'یزد', 'copy_date_from' => 1050, 'copy_date_to' => 1050, 'is_facsimile' => 1]);
        $this->copy($base, $person, ['copy_place' => str_repeat('مدرسه ', 12), 'copy_date_from' => 1050, 'copy_date_to' => 1050]);
        $this->copy($base, $person, ['copy_place' => null, 'copy_date_from' => 1050, 'copy_date_to' => 1050]);
        $this->copy($base, $person, ['copy_place' => 'شیراز (احتمالا)', 'copy_date_from' => 1010, 'copy_date_to' => 1010]);
        // two texts of one city are one row, each with the catalogue's own words (step 14.209)
        $this->copy($base, $person, ['copy_place' => 'طهران', 'copy_city' => 'تهران', 'copy_date_from' => 1070, 'copy_date_to' => 1070]);
        $this->copy($base, $person, ['copy_place' => 'دارالخلافه طهران', 'copy_city' => 'تهران', 'copy_date_from' => 1072, 'copy_date_to' => 1072]);
        // a text that names a country and no city is a row of the country (step 14.211)
        $this->copy($base, $person, ['copy_place' => 'هندوستان', 'copy_region' => 'هند', 'copy_date_from' => 1080, 'copy_date_to' => 1080]);
        // an author: a work with a place and a year, in a place the copies name too
        $own = $this->work('اثر خودش آزمایشی مکان', $person, ['composition_place' => 'شیراز', 'composition_date_from' => 1066, 'composition_date_to' => 1066]);

        $places = PersonPlaces::for($person->fresh());
        $this->assertSame([['اصفهان', 1052, 1057, 1], ['شیراز', 1060, 1066, 2], ['تهران', 1070, 1072, 2], ['هند', 1080, 1080, 1]], array_map(fn ($p) => [$p['place'], $p['from'], $p['to'], $p['count']], $places));
        $this->assertSame([true, ['طهران', 'دارالخلافه طهران']], [$places[2]['city'], array_column($places[2]['items'], 'text')]);
        $this->assertSame([false, [null]], [$places[0]['city'], array_column($places[0]['items'], 'text')]);
        $this->assertSame([null, 'کشور', ['هندوستان']], [$places[2]['kind'], $places[3]['kind'], array_column($places[3]['items'], 'text')]);
        $this->assertSame('از 1052 تا 1057', $places[0]['items'][0]['years']);
        $this->assertSame([['1060', 'کتابت', route('manuscripts.show', $first)], ['1066', 'تألیف', route('works.show', $own->id)]],
            array_map(fn ($i) => [$i['years'], $i['what'], $i['url']], $places[1]['items']));

        $this->get(route('people.show', $person))->assertOk()->assertSee('حضور در شهرها')->assertSee('اصفهان')->assertSee('از ۱۰۵۲ تا ۱۰۵۷')
            ->assertSee('۱۰۶۰–۱۰۶۶ ق')->assertSee('«دارالخلافه طهران»')->assertDontSee('قزوین')->assertDontSee('کاشان');
        // the other author has no place: no section
        $this->get(route('people.show', $other))->assertOk()->assertDontSee('حضور در شهرها');
    }

    public function test_the_city_of_a_place_text_is_stored_beside_it_and_kept_in_step()
    {
        $author = $this->person('مؤلف آزمایشی کد شهر');
        $work = $this->work('اثر آزمایشی کد شهر', $author, ['composition_place' => 'دارالسلطنه اصفهان']);
        $a = $this->copy($work, $author, ['copy_place' => 'دارالخلافه طهران']);
        $b = $this->copy($work, $author, ['copy_place' => 'هندوستان', 'copy_city' => 'تهران']);
        $d = $this->copy($work, $author, ['copy_place' => 'آمل مازندران']);
        $c = $this->copy($work, $author, ['copy_place' => null, 'copy_city' => 'قم']);
        $city = fn (int $id) => DB::table('manuscripts')->where('id', $id)->value('copy_city');

        $this->artisan('fahares:fill-place-cities')->assertSuccessful();
        $this->assertNull($city($a));
        $this->artisan('fahares:fill-place-cities', ['--apply' => true])->assertSuccessful();
        // a city of the list, a country that is none, and a place that was emptied; the text itself stays
        $this->assertSame(['تهران', null, null], [$city($a), $city($b), $city($c)]);
        $this->assertSame('دارالخلافه طهران', DB::table('manuscripts')->where('id', $a)->value('copy_place'));
        $this->assertSame('اصفهان', DB::table('works')->where('id', $work->id)->value('composition_city'));
        // the country or region beside the city: a country alone, a city with its region, a city alone
        $region = fn (int $id) => DB::table('manuscripts')->where('id', $id)->value('copy_region');
        $this->assertSame([null, 'هند', ['آمل', 'مازندران']], [$region($a), $region($b), [$city($d), $region($d)]]);

        // saving keeps the city in step
        Manuscript::find($a)->update(['copy_place' => 'مشهد رضوی مدرسه میرزا جعفر']);
        $this->assertSame('مشهد', $city($a));
        // cities added to the list and other forms of a name (step 14.221); a school named like a city is none
        foreach (['کربلای معلی' => 'کربلا', 'بلوک بلده ساوج' => 'ساوه', 'عظیم آباد هند' => 'پتنه', 'تفت یزد' => 'تفت', 'بروسه' => 'بورسا', 'اشرف مازندران' => 'بهشهر',
            'مدرسه سلیمانیه' => null, 'از محلات جوباره' => null] as $text => $expected) {
            $this->assertSame($expected, \App\Support\CopyPlaces::cityOf($text), $text);
        }
        Manuscript::find($a)->update(['copy_place' => 'احمد نگر دکن هند']);
        $this->assertSame(['احمدنگر', 'دکن'], [$city($a), $region($a)]);
        Manuscript::find($a)->update(['copy_place' => null]);
        $this->assertSame([null, null], [$city($a), $region($a)]);
        Work::find($work->id)->update(['composition_place' => 'نجف اشرف']);
        $this->assertSame('نجف', DB::table('works')->where('id', $work->id)->value('composition_city'));
    }

    public function test_the_copies_of_a_scribe_who_is_several_namesakes_say_nothing()
    {
        $author = $this->person('مؤلف آزمایشی قاطی مکان');
        $mixed = $this->person('کاتب آزمایشی قاطی مکان');
        $work = $this->work('اثر آزمایشی قاطی مکان', $author);
        $this->copy($work, $mixed, ['copy_place' => 'هرات', 'copy_date_from' => 1000, 'copy_date_to' => 1000]);
        $this->copy($work, $mixed, ['copy_place' => 'لاهور', 'copy_date_from' => 1150, 'copy_date_to' => 1150]);
        DB::table('people')->where('id', $mixed->id)->update(['scribed_from' => 1000, 'scribed_to' => 1150]);

        $this->assertSame([], PersonPlaces::for($mixed->fresh()));

        // a copy dated after the scribe's death is a namesake's
        $dead = $this->person('کاتب آزمایشی درگذشته مکان');
        DB::table('people')->where('id', $dead->id)->update(['death_year_hijri' => 1250, 'death_from' => 1250, 'death_to' => 1250]);
        $this->copy($work, $dead, ['copy_place' => 'خوی', 'copy_date_from' => 1240, 'copy_date_to' => 1240]);
        $this->copy($work, $dead, ['copy_place' => 'تبریز', 'copy_date_from' => 1272, 'copy_date_to' => 1272]);
        $this->assertSame(['خوی'], array_column(PersonPlaces::for($dead->fresh()), 'place'));
    }
}
