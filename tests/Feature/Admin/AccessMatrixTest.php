<?php

namespace Tests\Feature\Admin;

use App\Auth\Permissions;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccessMatrixChanged;
use App\Notifications\NotificationPreferences;
use App\Notifications\SuggestionSubmitted;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The admin panel's access matrix (backlog item 65): route protection, the matrix page and the admin's locked access. */
class AccessMatrixTest extends TestCase
{
    protected User $admin;
    protected User $editor;
    protected User $researcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'مدیر آزمایشی', 'email' => 'matrix-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $this->editor = User::create(['name' => 'داور آزمایشی', 'email' => 'matrix-editor@fahares.net', 'password' => 'password123', 'role' => 'editor']);
        $this->researcher = User::create(['name' => 'پژوهشگر آزمایشی', 'email' => 'matrix-researcher@fahares.net', 'password' => 'password123', 'role' => 'researcher']);
    }

    /** [method, uri] of every admin-only section under the default matrix; the editor must get 403 on each */
    private function adminOnlyRoutes(): array
    {
        $log = AuditLog::create(['auditable_type' => Library::class, 'auditable_id' => 1, 'action' => 'update', 'old_values' => ['name' => 'الف'], 'new_values' => ['name' => 'ب']]);
        $post = Post::create(['title' => 'نوشته‌ی آزمایشی', 'slug' => 'matrix-post-' . uniqid(), 'body' => 'متن']);

        return [
            ['get', '/admin/users'],
            ['get', "/admin/users/{$this->researcher->id}/edit"],
            ['put', "/admin/users/{$this->researcher->id}"],
            ['delete', "/admin/users/{$this->researcher->id}"],
            ['get', '/admin/roles'],
            ['put', '/admin/roles'],
            ['post', '/admin/roles/reset'],
            ['get', '/admin/system'],
            ['post', '/admin/system/clear-cache'],
            ['post', '/admin/system/reindex'],
            ['post', "/admin/audit-logs/{$log->id}/rollback"],
            ['get', '/admin/home-page'],
            ['put', '/admin/home-page/settings'],
            ['post', '/admin/home-page/items'],
            ['get', '/admin/pages/about/edit'],
            ['put', '/admin/pages/about'],
            ['get', '/admin/posts'],
            ['get', '/admin/posts/create'],
            ['post', '/admin/posts'],
            ['get', "/admin/posts/{$post->id}/edit"],
            ['put', "/admin/posts/{$post->id}"],
            ['delete', "/admin/posts/{$post->id}"],
            ['post', "/admin/posts/{$post->id}/images"],
            ['get', '/admin/subjects'],
            ['get', '/admin/subjects/create'],
            ['post', '/admin/subjects'],
            ['get', '/admin/subjects/tree'],
            ['post', '/admin/subjects/recount'],
            ['get', '/admin/subjects/merge'],
            ['post', '/admin/subjects/merge'],
        ];
    }

    public function test_editor_gets_403_on_every_admin_only_route()
    {
        $this->actingAs($this->editor);
        foreach ($this->adminOnlyRoutes() as [$method, $uri]) {
            $this->{$method}($uri)->assertForbidden();
        }
        $this->assertSame('researcher', $this->researcher->fresh()->role);
    }

    public function test_editor_reaches_the_sections_granted_by_default()
    {
        $this->actingAs($this->editor);
        foreach (['/admin', '/admin/suggestions', '/admin/catalog-references', '/admin/people', '/admin/people/merge', '/admin/catalogers', '/admin/libraries', '/admin/audit-logs'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_roles_without_panel_access_are_kept_out_of_the_panel()
    {
        foreach ([$this->researcher, User::create(['name' => 'کاربر', 'email' => 'matrix-user@fahares.net', 'password' => 'password123', 'role' => 'user'])] as $user) {
            $this->actingAs($user);
            foreach (['/admin', '/admin/suggestions', '/admin/people', '/admin/users'] as $uri) {
                $this->get($uri)->assertForbidden();
            }
            $this->post('/admin/suggestions/direct-edit', [])->assertForbidden();
        }
    }

    public function test_menu_shows_only_the_granted_sections()
    {
        $this->actingAs($this->editor)->get('/admin')->assertOk()
            ->assertSee(route('admin.suggestions.index'), false)
            ->assertSee(route('admin.people.index'), false)
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.roles.index'), false)
            ->assertDontSee(route('admin.system.index'), false)
            ->assertDontSee(route('admin.home.index'), false);

        $this->actingAs($this->admin)->get('/admin')->assertOk()
            ->assertSee(route('admin.users.index'), false)
            ->assertSee(route('admin.roles.index'), false);
    }

    public function test_admin_changes_the_matrix_and_access_follows()
    {
        Notification::fake();
        $other = User::create(['name' => 'مدیر دوم', 'email' => 'matrix-admin2@fahares.net', 'password' => 'password123', 'role' => 'admin']);

        $grants = Permissions::matrix();
        $grants['editor'][] = 'home.manage';
        $grants['editor'] = array_values(array_diff($grants['editor'], ['people.edit']));
        $this->actingAs($this->admin)->get('/admin/roles')->assertOk()->assertSee('ماتریس دسترسی');
        $this->put('/admin/roles', ['grants' => $grants])->assertRedirect('/admin/roles');

        $log = AuditLog::where('action', 'permissions')->sole();
        $this->assertSame(Role::class, $log->auditable_type);
        $this->assertSame(['home.manage'], $log->new_values['added']);
        $this->assertSame(['people.edit'], $log->new_values['removed']);
        Notification::assertSentTo($other, AccessMatrixChanged::class);
        Notification::assertNotSentTo($this->admin, AccessMatrixChanged::class);

        $this->actingAs($this->editor);
        $this->get('/admin/home-page')->assertOk();
        $this->get('/admin/people')->assertForbidden();

        // Back to the defaults
        $this->actingAs($this->admin)->post('/admin/roles/reset')->assertRedirect('/admin/roles');
        $this->assertSame(collect(Permissions::defaults())->map(fn ($k) => collect($k)->sort()->values()->all())->all(), Permissions::matrix());
        $this->actingAs($this->editor)->get('/admin/home-page')->assertForbidden();
    }

    public function test_the_admin_column_cannot_be_taken_away()
    {
        // Trying to strip the admin (and every role) of everything leaves the admin with all of it
        $this->actingAs($this->admin)->put('/admin/roles', ['grants' => ['admin' => []]]);
        $this->assertFalse(\DB::table('role_permissions')->where('role', 'admin')->exists());
        foreach (array_keys(Permissions::ALL) as $key) {
            $this->assertTrue($this->admin->hasPermission($key), $key);
        }
        foreach (['/admin', '/admin/users', '/admin/roles', '/admin/system'] as $uri) {
            $this->get($uri)->assertOk();
        }
        // Even rows written straight into the table for the admin role change nothing
        Permissions::save(['editor' => []]);
        $this->assertTrue($this->admin->hasPermission('roles.manage'));
        $this->assertFalse($this->editor->hasPermission('panel.access'));
        $this->actingAs($this->editor)->get('/admin')->assertForbidden();
    }

    public function test_only_an_admin_makes_or_unmakes_an_admin_and_the_last_admin_stays()
    {
        // A role granted users.manage still cannot hand out or take away the admin role
        $grants = Permissions::matrix();
        $grants['editor'][] = 'users.manage';
        Permissions::save($grants);
        $this->actingAs($this->editor);
        $this->put("/admin/users/{$this->editor->id}", ['name' => 'داور', 'role' => 'admin'])->assertForbidden();
        $this->put("/admin/users/{$this->admin->id}", ['name' => 'مدیر', 'role' => 'user'])->assertForbidden();
        $this->delete("/admin/users/{$this->admin->id}")->assertForbidden();
        $this->assertSame('editor', $this->editor->fresh()->role);
        $this->assertSame('admin', $this->admin->fresh()->role);

        // An admin cannot demote themselves, nor the last admin, and unknown roles are refused
        $this->actingAs($this->admin);
        $this->put("/admin/users/{$this->admin->id}", ['name' => 'مدیر', 'role' => 'editor'])->assertSessionHas('error');
        $this->assertSame('admin', $this->admin->fresh()->role);
        $this->put("/admin/users/{$this->researcher->id}", ['name' => 'پژوهشگر', 'role' => 'owner'])->assertSessionHasErrors('role');

        $second = User::create(['name' => 'مدیر دوم', 'email' => 'matrix-admin2@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $this->put("/admin/users/{$second->id}", ['name' => 'مدیر دوم', 'role' => 'editor'])->assertRedirect('/admin/users');
        $this->assertSame('editor', $second->fresh()->role);
    }

    public function test_structural_tools_in_suggestions_need_their_own_permission()
    {
        $a = Library::create(['name' => 'کتابخانه‌ی الف', 'city' => 'قم', 'slug' => 'matrix-lib-a-' . uniqid()]);
        $b = Library::create(['name' => 'کتابخانه‌ی ب', 'city' => 'قم', 'slug' => 'matrix-lib-b-' . uniqid()]);
        $suggestion = FieldSuggestion::create([
            'suggestable_type' => Library::class, 'suggestable_id' => $a->id, 'kind' => 'relation', 'field_name' => 'duplicate',
            'payload' => ['target_id' => $b->id], 'suggested_value' => '', 'rationale_citation' => 'مقایسه', 'guest_name' => 'پژوهشگر', 'status' => 'pending',
        ]);

        $this->actingAs($this->editor);
        $this->get("/admin/suggestions/{$suggestion->id}")->assertOk()->assertSee('برای نقش شما مجاز نیست');
        $this->post("/admin/suggestions/{$suggestion->id}/approve", ['keep' => 'target'])->assertForbidden();
        $this->assertSame('pending', $suggestion->fresh()->status);
        $this->assertNotNull(Library::find($a->id));

        // Granted from the matrix, the editor may run it
        $grants = Permissions::matrix();
        $grants['editor'][] = 'structure.library';
        Permissions::save($grants);
        $this->get("/admin/suggestions/{$suggestion->id}")->assertOk()->assertDontSee('برای نقش شما مجاز نیست');
    }

    public function test_public_page_actions_follow_the_matrix()
    {
        $draft = Post::create(['title' => 'پیش‌نویس', 'slug' => 'matrix-draft-' . uniqid(), 'body' => 'متن']);
        $this->actingAs($this->admin)->get("/posts/{$draft->getRouteKey()}")->assertOk();
        $this->actingAs($this->editor)->get("/posts/{$draft->getRouteKey()}")->assertNotFound();
        $this->actingAs($this->editor)->get('/about')->assertOk()->assertDontSee(route('admin.pages.edit', 'about'), false);
        $this->actingAs($this->admin)->get('/about')->assertOk()->assertSee(route('admin.pages.edit', 'about'), false);
    }

    public function test_reviewer_notifications_follow_the_matrix()
    {
        $ids = fn () => NotificationPreferences::recipients(SuggestionSubmitted::TYPE)->pluck('id')->all();
        $this->assertContains($this->editor->id, $ids());
        $this->assertNotContains($this->researcher->id, $ids());

        $grants = Permissions::matrix();
        $grants['editor'] = array_values(array_diff($grants['editor'], ['suggestions.review']));
        $grants['researcher'] = ['suggestions.review'];
        Permissions::save($grants);
        $this->assertNotContains($this->editor->id, $ids());
        $this->assertContains($this->researcher->id, $ids());
        $this->assertContains($this->admin->id, $ids());
    }
}
