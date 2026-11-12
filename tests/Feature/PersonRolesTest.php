<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\Work;
use App\Models\WorkPerson;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The author and scribe flags of a person and the counts beside them follow the person's works and copies as these
 * are edited (step 14.153): the search tabs of authors and of scribes are divided by them.
 */
class PersonRolesTest extends TestCase
{
    private function person(string $name): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'roles-' . uniqid()]);
    }

    private function work(array $attrs = []): Work
    {
        return Work::create($attrs + ['primary_title' => 'اثر نقش', 'clean_title' => 'اثر نقش', 'slug' => 'roles-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function manuscript(Work $work, array $attrs = []): Manuscript
    {
        return Manuscript::create($attrs + ['work_id' => $work->id, 'shelfmark' => 'R-' . uniqid(), 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'raw_text' => 'نسخه']);
    }

    private function roles(Person $person): array
    {
        $person->refresh();

        return [$person->is_author, $person->works_count, $person->is_scribe, $person->manuscripts_count];
    }

    public function test_the_author_of_a_work_follows_its_edits_and_its_removal(): void
    {
        $first = $this->person('مؤلف یکم');
        $second = $this->person('مؤلف دوم');

        $work = $this->work(['author_id' => $first->id]);
        $this->assertSame([true, 1, false, 0], $this->roles($first));

        // The work is given to another author: the first is no author any more
        $work->update(['author_id' => $second->id]);
        $this->assertSame([false, 0, false, 0], $this->roles($first));
        $this->assertSame([true, 1, false, 0], $this->roles($second));

        // A second author is added and taken away as a row of the list
        $row = WorkPerson::create(['work_id' => $work->id, 'person_id' => $first->id, 'role' => WorkPerson::AUTHOR, 'position' => 1]);
        $this->assertSame([true, 1, false, 0], $this->roles($first));
        $row->update(['role' => 'translator']);
        $this->assertSame([false, 0, false, 0], $this->roles($first), 'a translator is not an author');
        $row->delete();

        $work->delete();
        $this->assertSame([false, 0, false, 0], $this->roles($second), 'the rows go with the work, and the flag with them');
    }

    public function test_the_scribe_of_a_copy_follows_its_edits_and_its_removal(): void
    {
        $scribe = $this->person('کاتب یکم');
        $other = $this->person('کاتب دوم');
        $work = $this->work(['author_id' => $scribe->id]);

        $copy = $this->manuscript($work, ['scribe_id' => $scribe->id]);
        $this->assertSame([true, 1, true, 1], $this->roles($scribe), 'an author who copied is in both');

        $added = ManuscriptPerson::create(['manuscript_id' => $copy->id, 'person_id' => $other->id, 'role' => ManuscriptPerson::SCRIBE, 'position' => 1]);
        $this->assertSame([false, 0, true, 1], $this->roles($other));
        $added->delete();
        $this->assertSame([false, 0, false, 0], $this->roles($other));

        $copy->update(['scribe_id' => null]);
        $this->assertSame([true, 1, false, 0], $this->roles($scribe));

        $copy->update(['scribe_id' => $other->id]);
        $copy->delete();
        $this->assertSame([false, 0, false, 0], $this->roles($other));
    }

    public function test_the_nightly_command_mends_what_a_bulk_query_changed(): void
    {
        $author = $this->person('مؤلف بی‌پرچم');
        $stale = $this->person('مؤلف پیشین');
        $work = $this->work();
        // As a corpus command writes: no model, no hook
        DB::table('work_people')->insert(['work_id' => $work->id, 'person_id' => $author->id, 'role' => WorkPerson::AUTHOR, 'position' => 0]);
        DB::table('people')->where('id', $stale->id)->update(['is_author' => true, 'works_count' => 3, 'is_scribe' => true]);

        $this->artisan('fahares:sync-person-roles')->expectsOutputToContain('2 people differ')->assertSuccessful();
        $this->assertSame([false, 0, false, 0], $this->roles($author), 'a dry run writes nothing');

        $this->artisan('fahares:sync-person-roles', ['--apply' => true])->expectsOutputToContain('0 people still differ')->assertSuccessful();
        $this->assertSame([true, 1, false, 0], $this->roles($author));
        $this->assertSame([false, 0, false, 0], $this->roles($stale));
    }

    public function test_the_admin_form_shows_the_roles_and_does_not_set_them(): void
    {
        $admin = \App\Models\User::create(['name' => 'Admin', 'email' => 'roles-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $admin->forceFill(['email_verified_at' => now()])->save();
        $person = $this->person('شخص فرم');
        $this->work(['author_id' => $person->id]);

        $this->actingAs($admin)->get(route('admin.people.edit', $person))->assertOk()->assertSee('از روی آثار و نسخه‌های شخص تعیین می‌شود')
            ->assertDontSee('name="is_author"', false);
        $this->actingAs($admin)->put(route('admin.people.update', $person), ['name' => 'شخص فرم', 'is_scribe' => 1])->assertRedirect();
        $this->assertSame([true, 1, false, 0], $this->roles($person));
    }
}
