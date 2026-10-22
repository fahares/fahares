<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Page;
use App\Models\User;
use Tests\TestCase;

class PageTest extends TestCase
{
    public function test_the_about_page_shows_the_text_from_the_migration()
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('<h1 class="font-naskh', false)
            ->assertSee('<p class="basmala">بسم الله الرحمن الرحیم</p>', false)
            ->assertSee('<h2>فهارس چیست؟</h2>', false)
            ->assertSee('<strong>افزودن مآخذ تازه:</strong>', false)
            ->assertDontSee('ویرایش این صفحه در پنل');
    }

    public function test_admin_edits_the_about_page_and_the_edit_is_logged()
    {
        $admin = User::create([
            'name' => 'Admin Tester', 'email' => 'test-admin@fahares.net', 'password' => 'password123', 'role' => 'admin',
        ]);
        $page = Page::where('slug', 'about')->firstOrFail();
        $oldBody = $page->body;

        $this->actingAs($admin)->get(route('admin.pages.edit', $page))->assertOk()->assertSee('فهارس چیست؟');
        $this->get(route('about'))->assertSee('ویرایش این صفحه در پنل');

        $this->put(route('admin.pages.update', $page), [
            'title' => 'درباره‌ی فهارس',
            'body' => "## بخش تازه\n\nمتن <script>alert(1)</script> تازه.",
        ])->assertRedirect()->assertSessionHas('success');

        $page->refresh();
        $this->assertSame('درباره‌ی فهارس', $page->title);
        $this->assertSame($admin->id, $page->user_id);

        $log = AuditLog::where('auditable_type', Page::class)->where('auditable_id', $page->id)->latest('id')->firstOrFail();
        $this->assertSame($oldBody, $log->old_values['body']);

        $this->get(route('about'))
            ->assertSee('<h2>بخش تازه</h2>', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_guests_cannot_edit_a_page()
    {
        $page = Page::where('slug', 'about')->firstOrFail();

        $this->get(route('admin.pages.edit', $page))->assertRedirect(route('login'));
        $this->put(route('admin.pages.update', $page), ['title' => 'x', 'body' => 'y'])->assertRedirect(route('login'));
        $this->assertSame('درباره ما', $page->fresh()->title);
    }
}
