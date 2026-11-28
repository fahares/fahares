<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FixPersonNamesCommandTest extends TestCase
{
    private array $works = [];
    private array $people = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'names');
    }

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Work::class)->whereIn('auditable_id', $this->works)->delete();
        AuditLog::where('auditable_type', Person::class)->whereIn('auditable_id', $this->people)->delete();
        DB::table('work_people')->whereIn('work_id', $this->works)->delete();
        DB::table('source_entries')->where('citable_type', Work::class)->whereIn('citable_id', $this->works)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        DB::table('people')->whereIn('id', $this->people)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function person(string $name, array $columns = []): Person
    {
        $person = Person::forceCreate($columns + ['name' => $name, 'normalized_name' => $name, 'slug' => 'test-fix-names-' . uniqid(), 'is_author' => true]);
        $this->people[] = $person->id;

        return $person;
    }

    private function work(Person $person, string $authorName): int
    {
        return $this->works[] = \Tests\Support\InsertRecords::work([
            'primary_title' => 'اثر آزمایشی نام', 'clean_title' => 'اثر آزمایشی نام', 'slug' => 'test-fix-names-' . uniqid(),
            'volume_number' => 99, 'page_start' => 1, 'author_id' => $person->id, 'author_name' => $authorName,
        ]);
    }

    private function fix(array $actions, bool $apply = true): \Illuminate\Testing\PendingCommand
    {
        file_put_contents($this->file, json_encode(['step' => '0.0', 'actions' => $actions], JSON_UNESCAPED_UNICODE));

        return $this->artisan('fahares:fix-person-names', ['--file' => $this->file] + ($apply ? ['--apply' => true] : []));
    }

    public function test_a_misread_person_is_renamed_keeps_the_misreading_as_a_wrong_name_and_its_works_follow()
    {
        $person = $this->person('نفتویه، ابراهیم بن محمد');
        $work = $this->work($person, 'نفتویه، ابراهیم بن محمد');

        $this->fix([['action' => 'rename', 'person_id' => $person->id, 'person_name' => 'نفتویه، ابراهیم بن محمد',
            'new_name' => 'نفطویه، ابراهیم بن محمد', 'table' => 'work', 'id' => $work, 'old' => 'نفتویه', 'new' => 'نفطویه']])->assertSuccessful();

        $person->refresh();
        $this->assertSame('نفطویه، ابراهیم بن محمد', $person->name);
        $this->assertSame(['نفتویه، ابراهیم بن محمد'], $person->wrong_names);
        $this->assertSame('نفطویه، ابراهیم بن محمد', DB::table('works')->where('id', $work)->value('author_name'));
        $this->assertTrue(AuditLog::where('auditable_type', Person::class)->where('auditable_id', $person->id)->where('action', 'update')->exists());
    }

    public function test_a_variant_is_added_as_another_name_and_only_the_record_takes_the_printed_form()
    {
        $person = $this->person('ابوالقاسم حسینی تفرشی');
        $work = $this->work($person, 'ابوالقاسم حسینی تفرشی');

        $this->fix([['action' => 'variant', 'person_id' => $person->id, 'alias' => 'ابوالقاسم حسینی تفریشی', 'table' => 'work', 'id' => $work, 'old' => 'تفرشی', 'new' => 'تفریشی']]);

        $person->refresh();
        $this->assertSame('ابوالقاسم حسینی تفرشی', $person->name);
        $this->assertSame(['ابوالقاسم حسینی تفریشی'], $person->aliases);
        $this->assertSame('ابوالقاسم حسینی تفریشی', DB::table('works')->where('id', $work)->value('author_name'));
    }

    public function test_a_person_renamed_since_the_review_and_a_protected_record_name_are_left()
    {
        $person = $this->person('ابن جزی، محمد بن احمد');
        $work = $this->work($person, 'شفتی بیدآبادی، محمد باقر بن محمد تقی');
        AuditLog::create(['auditable_type' => Work::class, 'auditable_id' => $work, 'action' => 'verify', 'new_values' => ['author_name' => 'x']]);

        $this->fix([
            ['action' => 'rename', 'person_id' => $person->id, 'person_name' => 'ابن جزری، محمد بن احمد', 'new_name' => 'ابن جزیی، محمد بن احمد', 'table' => 'work', 'id' => $work, 'old' => 'جزری', 'new' => 'جزی'],
            ['action' => 'record', 'table' => 'work', 'id' => $work, 'old' => 'تقی', 'new' => 'نقی'],
        ])->expectsTable(['outcome', 'count'], [['record absent', 1], ['record protected', 1], ['rename conflict', 1]]);

        $this->assertSame('ابن جزی، محمد بن احمد', $person->fresh()->name);
        $this->assertSame('شفتی بیدآبادی، محمد باقر بن محمد تقی', DB::table('works')->where('id', $work)->value('author_name'));
    }
}
