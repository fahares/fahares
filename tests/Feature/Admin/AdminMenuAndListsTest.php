<?php

namespace Tests\Feature\Admin;

use App\Auth\Permissions;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\PersonReview;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The panel's menu by subject, «صف‌های بررسی» on one page, and the lists of works and copies with their «تازه»
 * buttons (step 14.397).
 */
class AdminMenuAndListsTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permissions::flush();
        config(['scout.driver' => 'collection']);
        $this->admin = User::create(['name' => 'مدیر کل', 'email' => uniqid('menu-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
    }

    private function editor(array $permissions): User
    {
        $grants = Permissions::matrix();
        $grants['editor'] = $permissions;
        Permissions::save($grants);

        return User::create(['name' => 'ویراستار', 'email' => uniqid('menu-ed-') . '@example.test', 'password' => 'password123', 'role' => 'editor']);
    }

    public function test_the_menu_has_the_lists_and_one_item_for_the_queues()
    {
        $page = $this->actingAs($this->admin)->get('/admin')->assertOk();
        foreach (['admin.people.index', 'admin.works.index', 'admin.manuscripts.index', 'admin.review-queues', 'admin.suggestions.index', 'admin.sources.index', 'admin.subjects.index'] as $route) {
            $page->assertSee('href="' . route($route) . '"', false);
        }
        // making and merging are buttons of the lists, and each queue is a card of the queues' page
        foreach (['admin.people.create', 'admin.people.merge', 'admin.works.create', 'admin.manuscripts.create', 'admin.sources.merge', 'admin.subjects.merge', 'admin.author-links.index', 'admin.claims.index'] as $route) {
            $page->assertDontSee('href="' . route($route) . '"', false);
        }
        $page->assertSee('کارتابل')->assertSee('داده‌ها');

        $this->get(route('admin.people.index'))->assertSee(route('admin.people.create'), false)->assertSee(route('admin.people.merge'), false);
        $this->get(route('admin.works.index'))->assertOk()->assertSee(route('admin.works.create'), false);
        $this->get(route('admin.manuscripts.index'))->assertOk()->assertSee(route('admin.manuscripts.create'), false);
    }

    public function test_the_queues_page_shows_the_queues_a_role_may_open_and_the_menu_counts_them()
    {
        $a = Person::create(['name' => 'هم‌نام یکم', 'normalized_name' => 'هم‌نام یکم', 'slug' => 'menu-p-' . uniqid()]);
        $b = Person::create(['name' => 'هم‌نام دوم', 'normalized_name' => 'هم‌نام دوم', 'slug' => 'menu-p-' . uniqid()]);
        PersonReview::create(['type' => 'duplicate', 'key' => 'menu-' . uniqid(), 'person_ids' => [$a->id, $b->id], 'status' => 'open', 'reason' => 'test']);

        $page = $this->actingAs($this->admin)->get(route('admin.review-queues'))->assertOk();
        $page->assertSee('data-queue="اشخاص هم‌نام"', false)->assertSee('data-queue="ادعاهای فهرست‌های چاپی"', false)
            ->assertSee(route('admin.person-reviews.index'), false)->assertSee('قدیمی‌ترین');
        // the queue's own page marks the menu item
        $html = $this->get(route('admin.person-reviews.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~href="' . preg_quote(route('admin.review-queues'), '~') . '"\s+aria-current="page"~', $html);

        // a role that may merge people sees that queue only
        $editor = $this->editor(['panel.access', 'people.merge']);
        $this->actingAs($editor)->get(route('admin.review-queues'))->assertOk()
            ->assertSee('data-queue="اشخاص هم‌نام"', false)->assertDontSee('data-queue="ادعاهای فهرست‌های چاپی"', false);
        $this->get('/admin')->assertSee(route('admin.review-queues'), false);

        // a role with no queue has no item
        $bare = $this->editor(['panel.access']);
        $this->actingAs($bare)->get('/admin')->assertOk()->assertDontSee(route('admin.review-queues'), false);
    }

    public function test_the_works_list_searches_and_filters()
    {
        $made = Work::create(['primary_title' => 'رسالۀ فهرست آزمون', 'clean_title' => 'رسالۀ فهرست آزمون', 'slug' => 'menu-w-' . uniqid(), 'author_name' => 'مؤلف فهرست آزمون', 'catalog_id' => null]);
        $fankha = Work::create(['primary_title' => 'کتاب دیگر آزمون', 'clean_title' => 'کتاب دیگر آزمون', 'slug' => 'menu-w-' . uniqid(), 'catalog_id' => 1, 'volume_number' => 1, 'page_start' => 1, 'manuscripts_count' => 3]);

        $this->actingAs($this->admin)->get(route('admin.works.index', ['q' => 'فهرست آزمون']))->assertOk()
            ->assertSee('data-work-row="' . $made->id . '"', false)->assertDontSee('data-work-row="' . $fankha->id . '"', false);
        // by the author's name, and by id
        $this->get(route('admin.works.index', ['q' => 'مؤلف فهرست']))->assertSee('data-work-row="' . $made->id . '"', false);
        $this->get(route('admin.works.index', ['q' => '#' . $fankha->id]))->assertSee('data-work-row="' . $fankha->id . '"', false)->assertDontSee('data-work-row="' . $made->id . '"', false);
        $this->get(route('admin.works.index', ['filter' => 'panel', 'q' => 'آزمون']))->assertSee('data-work-row="' . $made->id . '"', false)->assertDontSee('data-work-row="' . $fankha->id . '"', false);
        $this->get(route('admin.works.index', ['filter' => 'no_copies', 'q' => 'آزمون']))->assertSee('data-work-row="' . $made->id . '"', false)->assertDontSee('data-work-row="' . $fankha->id . '"', false);
    }

    public function test_the_copies_list_searches_by_library_shelfmark_and_title()
    {
        $library = Library::create(['name' => 'کتابخانۀ فهرست آزمون', 'city' => 'قم', 'slug' => 'menu-lib-' . uniqid()]);
        $other = Library::create(['name' => 'کتابخانۀ دیگر آزمون', 'city' => 'تهران', 'slug' => 'menu-lib-' . uniqid()]);
        $work = Work::create(['primary_title' => 'جنگ اشعار آزمون', 'clean_title' => 'جنگ اشعار آزمون', 'slug' => 'menu-w-' . uniqid(), 'catalog_id' => 1, 'volume_number' => 1, 'page_start' => 1]);
        $copy = fn (Library $l, string $shelfmark, array $extra = []) => Manuscript::create($extra + ['work_id' => $work->id, 'sequence_number' => 1, 'catalog_id' => 1, 'volume_number' => 1, 'page_start' => 1,
            'library_id' => $l->id, 'library' => $l->name, 'city' => $l->city, 'shelfmark' => $shelfmark]);
        $part = $copy($library, '2897/3');
        $plain = $copy($library, '2897');
        $elsewhere = $copy($other, '2897');
        $panel = $copy($library, '55', ['catalog_id' => null]);
        $row = fn (Manuscript $m) => 'data-manuscript-row="' . $m->id . '"';

        // a bare number is a shelfmark, with its parts, written with either digits
        $this->actingAs($this->admin)->get(route('admin.manuscripts.index', ['q' => '۲۸۹۷']))->assertOk()
            ->assertSee($row($part), false)->assertSee($row($plain), false)->assertSee($row($elsewhere), false)->assertDontSee($row($panel), false);
        $this->get(route('admin.manuscripts.index', ['q' => '2897', 'library_id' => $library->id]))
            ->assertSee($row($plain), false)->assertDontSee($row($elsewhere), false)->assertSee('کتابخانۀ فهرست آزمون');
        // a title, with a library chosen
        $this->get(route('admin.manuscripts.index', ['q' => 'اشعار', 'library_id' => $library->id]))->assertSee($row($panel), false)->assertDontSee($row($elsewhere), false);
        // «#id», and the records made in the panel
        $this->get(route('admin.manuscripts.index', ['q' => '#' . $elsewhere->id]))->assertSee($row($elsewhere), false)->assertDontSee($row($part), false);
        $this->get(route('admin.manuscripts.index', ['filter' => 'panel', 'library_id' => $library->id]))->assertSee($row($panel), false)->assertDontSee($row($part), false);
        // a role without the right to make copies sees the list without the button
        $editor = $this->editor(['panel.access']);
        $this->actingAs($editor)->get(route('admin.manuscripts.index'))->assertOk()->assertDontSee(route('admin.manuscripts.create'), false);
    }
}
