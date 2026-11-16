<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Models\WorkPerson;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Step 14.235 (backlog item 145): people rows that are a line of a work's entry are removed and the line goes back to the work. */
class RemovePseudoPeopleCommandTest extends TestCase
{
    private const FILE = 'storage/framework/testing-pseudo-people.json';

    protected function tearDown(): void
    {
        @unlink(base_path(self::FILE));
        parent::tearDown();
    }

    private function person(string $name, array $more = []): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => \App\Helpers\TextNormalizer::normalize($name), 'slug' => 'pp-' . uniqid()] + $more);
    }

    private function work(string $title, Person $person, string $line, array $more = []): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'pseudo-' . uniqid(), 'volume_number' => 1, 'page_start' => 1,
            'author_name' => $person->name, 'author_id' => $person->id, 'raw_text' => "● {$title} / متفرقه / فارسی\ntranslit\n\n{$line}\nشرح اثر."] + $more);
        $this->assertTrue(DB::table('work_people')->where('work_id', $work->id)->where('person_id', $person->id)->where('role', WorkPerson::AUTHOR)->exists());

        return $work;
    }

    private function run_(array $people, bool $apply = true)
    {
        file_put_contents(base_path(self::FILE), json_encode(['step' => '14.235', 'people' => $people], JSON_UNESCAPED_UNICODE));

        return $this->artisan('fahares:remove-pseudo-people', ['--file' => self::FILE] + ($apply ? ['--apply' => true] : []));
    }

    public function test_a_date_line_goes_to_the_work_and_the_row_leads_to_it(): void
    {
        $u = uniqid();
        $pseudo = $this->person("سال تألیف {$u}", ['life_dates_raw' => '1332ق', 'works_count' => 2]);
        $dated = $this->work("اساسنامه {$u}", $pseudo, 'سال تألیف: 1332ق');
        $hasDate = $this->work("عقد لئالی {$u}", $pseudo, 'تاریخ تألیف 1103ق', ['composition_date_raw' => '1100ق', 'description' => 'پرسش و پاسخ است.']);
        $rows = [['name' => $pseudo->name, 'kind' => 'date', 'works' => [
            ['slug' => $dated->slug, 'line' => 'سال تألیف: 1332ق', 'date' => '1332ق'],
            ['slug' => $hasDate->slug, 'line' => 'تاریخ تألیف 1103ق', 'date' => '1103ق'],
        ]]];

        $this->run_($rows, false)->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertNotNull(Person::find($pseudo->id));
        $this->assertSame($pseudo->id, $dated->fresh()->author_id);

        $this->run_($rows)->assertSuccessful();

        $dated->refresh();
        $this->assertNull(Person::find($pseudo->id));
        $this->assertNull($dated->author_id);
        $this->assertNull($dated->author_name);
        $this->assertSame('1332ق', $dated->composition_date_raw);
        $this->assertSame(1332, $dated->composition_date_from);
        $this->assertSame(1332, $dated->composition_year_hijri);
        $this->assertFalse(DB::table('work_people')->where('work_id', $dated->id)->exists());
        // a column that already holds something is left alone
        $this->assertSame('1100ق', $hasDate->fresh()->composition_date_raw);
        $this->assertSame('پرسش و پاسخ است.', $hasDate->fresh()->description);

        $log = AuditLog::where('auditable_type', Work::class)->where('auditable_id', $dated->id)->where('action', 'corpus_update')->sole();
        $this->assertSame($pseudo->id, $log->old_values['author_id']);
        $this->assertSame('pseudo_person', $log->new_values['corpus_fix']);
        $removal = AuditLog::where('auditable_type', Person::class)->where('auditable_id', $pseudo->id)->where('action', 'remove')->sole();
        $this->assertSame($pseudo->name, $removal->old_values['snapshot']['attributes']['name']);

        // the row's address leads to its first work
        $this->get("/people/{$pseudo->id}")->assertRedirect(route('works.show', $dated))->assertStatus(301);

        // a second run finds nothing left to do
        $this->run_($rows)->expectsOutputToContain('Done: 0 works')->assertSuccessful();
        $this->assertSame(1, AuditLog::where('auditable_type', Person::class)->where('auditable_id', $pseudo->id)->count());
    }

    public function test_a_sentence_becomes_the_description_and_a_scribe_link_goes_with_the_row(): void
    {
        $u = uniqid();
        $pseudo = $this->person("عهدنامه انگلیس و افغانستان مورخ 30 مه {$u}", ['life_dates_raw' => '1879م']);
        $line = "عهدنامه انگلیس و افغانستان مورخ 30 مه {$u} 1879م.";
        $work = $this->work("عهدنامه {$u}", $pseudo, $line);
        $copy = Manuscript::create(['work_id' => $work->id, 'slug' => 'pp-copy-' . uniqid(), 'shelfmark' => '1', 'volume_number' => 1, 'page_start' => 1,
            'is_autograph' => true, 'scribe_id' => $pseudo->id]);

        $this->run_([['name' => $pseudo->name, 'kind' => 'sentence', 'works' => [['slug' => $work->slug, 'line' => $line, 'description' => $line, 'date' => '30 مه 1879م']]]])
            ->assertSuccessful();

        $work->refresh();
        $this->assertNull(Person::find($pseudo->id));
        $this->assertSame($line, $work->description);
        $this->assertSame('30 مه 1879م', $work->composition_date_raw);
        $this->assertNull($copy->fresh()->scribe_id);
        $this->assertTrue($copy->fresh()->is_autograph);
        $this->assertFalse(DB::table('manuscript_people')->where('manuscript_id', $copy->id)->exists());
    }

    public function test_a_patron_line_becomes_the_dedication_and_its_role(): void
    {
        $u = uniqid();
        // the authority record of KnownRulers, which «ناصرالدین شاه» links to
        $king = Person::firstOrCreate(['name' => 'ناصرالدین قاجار، شاه ایران'], ['normalized_name' => \App\Helpers\TextNormalizer::normalize('ناصرالدین قاجار، شاه ایران'), 'slug' => 'pp-' . uniqid(), 'death_year_hijri' => 1313]);
        Person::where('name', 'به دستور: ناصرالدین شاه')->delete();
        $pseudo = $this->person('به دستور: ناصرالدین شاه');
        $work = $this->work("راهها {$u}", $pseudo, 'به دستور: ناصرالدین شاه');
        $own = $this->work("دیوان {$u}", $king, 'شعر');
        $dedicated = $this->work("آداب {$u}", $king, 'اهداء: ناصرالدین شاه قاجار (1264-1313ق)');
        $before = $king->fresh()->works_count;

        $this->run_([
            ['name' => $pseudo->name, 'kind' => 'patron', 'works' => [['slug' => $work->slug, 'line' => 'به دستور: ناصرالدین شاه', 'dedication' => 'به دستور: ناصرالدین شاه']]],
            ['name' => $king->name, 'kind' => 'unlink', 'works' => [['slug' => $dedicated->slug, 'line' => 'اهداء: ناصرالدین شاه قاجار (1264-1313ق)', 'dedication' => 'اهداء: ناصرالدین شاه قاجار (1264-1313ق)']]],
        ])->assertSuccessful();

        $this->assertNull(Person::find($pseudo->id));
        $this->assertSame('به دستور: ناصرالدین شاه', $work->fresh()->dedication);
        $this->assertNull($work->fresh()->author_id);
        $role = DB::table('work_people')->where('work_id', $work->id)->sole();
        $this->assertSame('commissioner', $role->role);
        $this->assertSame($king->id, $role->person_id);
        // the row's address leads to the patron
        $this->assertSame($king->id, EntityRedirect::resolveTargetId('people', $pseudo->id));
        $this->get("/people/{$pseudo->id}")->assertRedirect(route('people.show', $king->fresh()->getRouteKey()));

        // the real person keeps his own work and loses only the link the dedication line made
        $this->assertSame($king->id, $own->fresh()->author_id);
        $this->assertNull($dedicated->fresh()->author_id);
        $this->assertSame($king->name, $dedicated->fresh()->author_name);
        $this->assertSame($before - 1, $king->fresh()->works_count);
        $this->assertSame(['dedicatee'], DB::table('work_people')->where('work_id', $dedicated->id)->pluck('role')->all());

        // a second run: the real person is still there, his link is not
        $this->run_([['name' => $king->name, 'kind' => 'unlink', 'works' => [['slug' => $dedicated->slug, 'line' => 'اهداء: ناصرالدین شاه قاجار (1264-1313ق)', 'dedication' => 'x']]]])
            ->expectsOutputToContain('Done: 0 works')->assertSuccessful();
        $this->assertSame($king->id, $own->fresh()->author_id);
    }

    public function test_a_name_with_the_description_run_on_is_cut_and_a_taken_name_stays_as_text(): void
    {
        $u = uniqid();
        $long = $this->person("صالح{$u}. منظومه‌ای است در دویست و نه بیت");
        $free = $this->work("البهجة {$u}", $long, "از: صالح{$u}. منظومه‌ای است در دویست و نه بیت، در منطق.", ['raw_text' => "● البهجة\nal-bahja\n\nاز: صالح{$u}. منظومه‌ای است در دویست و نه بیت، در منطق."]);
        $namesake = $this->person("آذری{$u}");
        $taken = $this->person("آذری{$u}. قصاید زیبایی است");
        $text = $this->work("دیوان آذری {$u}", $taken, "از: آذری{$u}. قصاید زیبایی است در مدح.");

        $this->run_([
            ['name' => $long->name, 'kind' => 'rename', 'to' => "صالح{$u}", 'works' => [['slug' => $free->slug, 'line' => "از: صالح{$u}. منظومه‌ای است در دویست و نه بیت، در منطق.", 'description' => 'منظومه‌ای است در دویست و نه بیت، در منطق.']]],
            ['name' => $taken->name, 'kind' => 'rename', 'to' => "آذری{$u}", 'works' => [['slug' => $text->slug, 'line' => "از: آذری{$u}. قصاید زیبایی است در مدح.", 'description' => 'قصاید زیبایی است در مدح.']]],
        ])->assertSuccessful();

        // the person keeps the work under the name alone
        $long->refresh();
        $this->assertSame("صالح{$u}", $long->name);
        $this->assertSame(\App\Helpers\TextNormalizer::normalize("صالح{$u}"), $long->normalized_name);
        $this->assertSame($long->id, $free->fresh()->author_id);
        $this->assertSame("صالح{$u}", $free->fresh()->author_name);
        $this->assertSame('منظومه‌ای است در دویست و نه بیت، در منطق.', $free->fresh()->description);

        // another person has the name: it stays on the work as text, and is not joined to the namesake
        $this->assertNull(Person::find($taken->id));
        $this->assertNull($text->fresh()->author_id);
        $this->assertSame("آذری{$u}", $text->fresh()->author_name);
        $this->assertSame(0, DB::table('work_people')->where('person_id', $namesake->id)->count());
        $this->get("/people/{$taken->id}")->assertRedirect(route('works.show', $text));
    }

    public function test_a_row_with_anything_the_file_does_not_name_is_left(): void
    {
        $u = uniqid();
        $busy = $this->person("تاریخ تألیف {$u}");
        $listed = $this->work("الف {$u}", $busy, 'تاریخ تألیف 1103ق');
        $this->work("ب {$u}", $busy, 'تاریخ تألیف 1143ق');
        $moved = $this->person("زمان تألیف {$u}");
        $changed = $this->work("ج {$u}", $moved, 'متن دیگر');
        $verified = $this->person("تاریخ ترجمه {$u}");
        $checked = $this->work("د {$u}", $verified, 'تاریخ ترجمه: 1258ق');
        AuditLog::create(['auditable_type' => Work::class, 'auditable_id' => $checked->id, 'action' => 'verify', 'old_values' => [], 'new_values' => ['author_id' => $verified->id]]);

        $this->run_([
            ['name' => $busy->name, 'kind' => 'date', 'works' => [['slug' => $listed->slug, 'line' => 'تاریخ تألیف 1103ق', 'date' => '1103ق']]],
            ['name' => $moved->name, 'kind' => 'date', 'works' => [['slug' => $changed->slug, 'line' => 'زمان تألیف: 1293ق', 'date' => '1293ق']]],
            ['name' => $verified->name, 'kind' => 'translation_date', 'works' => [['slug' => $checked->slug, 'line' => 'تاریخ ترجمه: 1258ق', 'date' => '1258ق']]],
            ['name' => "هیچ‌کس {$u}", 'kind' => 'date', 'works' => [['slug' => 'no-such-work', 'line' => 'x']]],
        ])->expectsOutputToContain('another work')->assertSuccessful();

        foreach ([[$busy, $listed], [$moved, $changed], [$verified, $checked]] as [$person, $work]) {
            $this->assertNotNull(Person::find($person->id));
            $this->assertSame($person->id, $work->fresh()->author_id);
            $this->assertNull($work->fresh()->composition_date_raw);
        }
    }
}
