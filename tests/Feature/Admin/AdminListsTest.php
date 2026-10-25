<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Person;
use App\Models\User;
use Tests\TestCase;

/** The redesigned list pages of the admin panel (backlog item 65, clause 5): Persian labels and digits, filters */
class AdminListsTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'مدیر آزمایشی', 'email' => 'lists-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
    }

    public function test_list_pages_render_without_english_headings(): void
    {
        $person = Person::create(['name' => 'شخص آزمایشی فهرست', 'normalized_name' => 'شخص آزمایشی فهرست', 'slug' => 'lists-person-' . uniqid(), 'is_author' => true, 'death_year_hijri' => 1091]);
        Library::create(['name' => 'کتابخانه‌ی آزمایشی فهرست', 'city' => 'قم', 'slug' => 'lists-library-' . uniqid()]);

        foreach (['/admin/people', '/admin/libraries', '/admin/catalogers', '/admin/users', '/admin/audit-logs'] as $uri) {
            $this->actingAs($this->admin)->get($uri)->assertOk()
                ->assertDontSee('Authorities')->assertDontSee('Users & Roles')->assertDontSee('Audit Logs')->assertDontSee('rounded-2xl');
        }

        $this->actingAs($this->admin)->get('/admin/people')
            ->assertSee('شخص آزمایشی فهرست')->assertSee('#' . \App\Support\Fa::digits($person->id))->assertSee('۱۰۹۱ ق');
    }

    public function test_users_are_searched_by_name_or_email_and_tabs_count_roles(): void
    {
        User::create(['name' => 'پژوهشگر یافتنی', 'email' => 'findme@example.org', 'password' => 'password123', 'role' => 'researcher']);
        User::create(['name' => 'کاربر دیگر', 'email' => 'other@example.org', 'password' => 'password123', 'role' => 'user']);

        $this->actingAs($this->admin)->get('/admin/users?q=findme')
            ->assertOk()->assertSee('پژوهشگر یافتنی')->assertDontSee('کاربر دیگر');

        $this->actingAs($this->admin)->get('/admin/users?role=researcher')
            ->assertOk()->assertSee('پژوهشگر یافتنی')->assertDontSee('other@example.org');
    }

    public function test_audit_log_shows_persian_labels_the_change_and_gathers_rollbacks(): void
    {
        AuditLog::create(['auditable_type' => Library::class, 'auditable_id' => 7, 'action' => 'update', 'user_id' => $this->admin->id,
            'old_values' => ['name' => 'نام پیشین'], 'new_values' => ['name' => 'نام تازه'], 'created_at' => now()]);
        AuditLog::create(['auditable_type' => Person::class, 'auditable_id' => 9, 'action' => 'rollback_merge', 'user_id' => $this->admin->id,
            'old_values' => ['x' => 1], 'new_values' => ['x' => 2], 'created_at' => now()]);

        $this->actingAs($this->admin)->get('/admin/audit-logs')
            ->assertOk()->assertSee('ویرایش')->assertSee('کتابخانه')->assertSee('#۷')->assertSee('نام پیشین')->assertSee('نام تازه')
            ->assertSee('بازگردانی ادغام');

        $this->actingAs($this->admin)->get('/admin/audit-logs?action=rollback')
            ->assertOk()->assertSee('بازگردانی ادغام')->assertDontSee('نام پیشین');
    }

    public function test_subject_pages_render_in_persian_and_say_a_merge_can_be_undone(): void
    {
        $root = \App\Models\Subject::create(['name' => 'فقه آزمایشی', 'slug' => 'lists-root-' . uniqid()]);
        $child = \App\Models\Subject::create(['name' => 'طهارت آزمایشی', 'slug' => 'lists-child-' . uniqid(), 'parent_id' => $root->id]);

        foreach (['/admin/subjects', '/admin/subjects/tree', '/admin/subjects/merge', '/admin/subjects/create', "/admin/subjects/{$child->id}/edit"] as $uri) {
            $this->actingAs($this->admin)->get($uri)->assertOk()
                ->assertDontSee('Tree)')->assertDontSee('Taxonomy')->assertDontSee('Subject Merger')->assertDontSee('Root Category')->assertDontSee('rounded-2xl');
        }

        $this->actingAs($this->admin)->get('/admin/subjects/tree')->assertOk()->assertSee('فقه آزمایشی')->assertSee('طهارت آزمایشی')->assertDontSee('۱۲۹ موضوع');
        $this->actingAs($this->admin)->get('/admin/subjects/merge')->assertOk()->assertSee('بازگرداندنی')->assertDontSee('قابل بازگشت نیست');
    }

    public function test_system_page_is_in_persian_and_its_audit_rows_have_persian_names(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 500)]);

        $this->actingAs($this->admin)->get('/admin/system')->assertOk()
            ->assertSee('کش و نمایه‌سازی')->assertSee('در دسترس نیست')->assertSee('حالت اشکال‌زدایی')
            ->assertDontSee('Cache Flushing')->assertDontSee('(Works)')->assertDontSee('rounded-2xl');

        AuditLog::create(['auditable_type' => 'System', 'auditable_id' => 1, 'action' => 'update', 'user_id' => $this->admin->id,
            'new_values' => ['operation' => 'clear_cache'], 'created_at' => now()]);
        $this->actingAs($this->admin)->get('/admin/audit-logs')->assertOk()->assertSeeText('سامانه #۱');
    }
}
