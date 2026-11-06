<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Cataloger;
use App\Models\Person;
use App\Models\User;
use Tests\TestCase;

/** A cataloger made and edited in the panel (backlog item 40, step 14.69) */
class AdminCatalogersTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'مدیر آزمایشی', 'email' => 'catalogers-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
    }

    public function test_create_form_and_store_with_profile_fields(): void
    {
        $person = Person::create(['name' => 'آقابزرگ طهرانی', 'normalized_name' => 'آقابزرگ طهرانی', 'slug' => 'cat-person-' . uniqid(), 'is_author' => true]);
        Cataloger::create(['name' => 'محمدتقی دانش‌پژوه', 'slug' => \Illuminate\Support\Str::slug('محمدتقی دانش‌پژوه', '-', null)]);

        $this->actingAs($this->admin)->get('/admin/catalogers')->assertOk()->assertSee('افزودن فهرست‌نگار');
        $this->actingAs($this->admin)->get('/admin/catalogers/create')->assertOk()->assertSee('ساخت فهرست‌نگار')->assertSee('فهرست‌های شاخص');

        $this->actingAs($this->admin)->post('/admin/catalogers', [
            'name' => 'محمدتقی دانش‌پژوه',
            'title_prefix' => 'استاد',
            'nickname' => ' دانش‌پژوه ',
            'death_year_solar' => 1375,
            'person_id' => $person->id,
            'meta' => [
                'birth_place' => 'آمل',
                'resting_place' => '',
                'institutions' => "کتابخانه‌ی مرکزی دانشگاه تهران\n\n  کتابخانه‌ی مجلس  ",
                'major_catalogs' => '',
                'sources' => "ویکی‌نور | https://fa.wikinoor.ir/wiki/x\nرکورد MTIF\nhttps://example.org/a",
            ],
        ])->assertRedirect();

        $made = Cataloger::where('name', 'محمدتقی دانش‌پژوه')->latest('id')->first();
        $this->assertSame(\Illuminate\Support\Str::slug('محمدتقی دانش‌پژوه', '-', null) . '-2', $made->slug);
        $this->assertSame('استاد', $made->title_prefix);
        $this->assertSame('دانش‌پژوه', $made->nickname);
        $this->assertSame(1375, $made->death_year_solar);
        $this->assertSame($person->id, $made->person_id);
        $this->assertSame([
            'birth_place' => 'آمل',
            'institutions' => ['کتابخانه‌ی مرکزی دانشگاه تهران', 'کتابخانه‌ی مجلس'],
            'sources' => [['title' => 'ویکی‌نور', 'url' => 'https://fa.wikinoor.ir/wiki/x'], 'رکورد MTIF', ['title' => 'https://example.org/a', 'url' => 'https://example.org/a']],
        ], $made->metadata);
        $this->assertTrue(AuditLog::where('auditable_type', Cataloger::class)->where('auditable_id', $made->id)->where('action', 'create')->exists());

        $this->get(route('catalogers.show', $made))->assertOk()->assertSee('آمل')->assertSee('کتابخانه‌ی مجلس');
    }

    public function test_edit_shows_profile_and_keeps_other_metadata(): void
    {
        $cataloger = Cataloger::create(['name' => 'عبدالحسین حائری', 'slug' => 'haeri-' . uniqid(), 'metadata' => [
            'institutions' => ['کتابخانه‌ی مجلس'],
            'sources' => [['title' => 'ویکی‌نور', 'url' => 'https://fa.wikinoor.ir/wiki/h']],
            'external_links' => ['wikinoor' => 'https://wikinoor.ir/h'],
        ]]);

        $this->actingAs($this->admin)->get("/admin/catalogers/{$cataloger->id}/edit")->assertOk()
            ->assertSee('کتابخانه‌ی مجلس')->assertSee('ویکی‌نور | https://fa.wikinoor.ir/wiki/h', false);

        $this->actingAs($this->admin)->put("/admin/catalogers/{$cataloger->id}", [
            'name' => 'عبدالحسین حائری',
            'meta' => ['institutions' => '', 'sources' => 'ویکی‌نور | https://fa.wikinoor.ir/wiki/h', 'specialty' => 'فهرست‌نگاری'],
        ])->assertRedirect('/admin/catalogers');

        $this->assertSame([
            'sources' => [['title' => 'ویکی‌نور', 'url' => 'https://fa.wikinoor.ir/wiki/h']],
            'external_links' => ['wikinoor' => 'https://wikinoor.ir/h'],
            'specialty' => 'فهرست‌نگاری',
        ], $cataloger->fresh()->metadata);
    }

    public function test_unknown_person_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/admin/catalogers', ['name' => 'فهرست‌نگار تازه', 'person_id' => 99999999])
            ->assertSessionHasErrors('person_id');
        $this->assertFalse(Cataloger::where('name', 'فهرست‌نگار تازه')->exists());
    }
}
