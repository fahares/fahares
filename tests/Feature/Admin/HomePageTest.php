<?php

namespace Tests\Feature\Admin;

use App\Models\Cataloger;
use App\Models\HomeItem;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Fa;
use App\Support\SiteStats;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::forget('site_settings');
        SiteStats::forget();

        $this->admin = User::firstOrCreate(
            ['email' => 'test-admin@fahares.net'],
            ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']
        );
        $this->admin->role = 'admin';
        $this->admin->save();
    }

    protected function tearDown(): void
    {
        Cache::forget('site_settings');
        SiteStats::forget();
        parent::tearDown();
    }

    /** A real 1×1 JPEG, so the image rule passes without the GD extension. */
    private function jpeg(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='
        ));
    }

    private function cataloger(string $name): Cataloger
    {
        return Cataloger::create(['name' => $name, 'slug' => 'test-' . uniqid()]);
    }

    private function slide(array $attrs = []): HomeItem
    {
        Storage::disk('public')->put('home/slides/t.jpg', 'x');

        return HomeItem::create($attrs + [
            'section' => 'slides', 'title' => 'اسلاید آزمایشی', 'image_path' => 'home/slides/t.jpg',
            'sort_order' => 900, 'is_active' => true,
        ]);
    }

    public function test_home_shows_admin_content_and_live_counts_in_persian_digits()
    {
        HomeItem::query()->update(['is_active' => false]);
        $this->slide(['title' => 'نگاره‌ی آزمایشی بنر']);
        $this->slide(['title' => 'اسلاید پنهان', 'is_active' => false]);
        Cataloger::query()->update(['home_order' => null]);
        $cataloger = $this->cataloger('فهرست‌نگار آزمایشی خانه');
        $cataloger->update(['home_order' => 1]);
        SiteSetting::put('home.headline', 'عنوان آزمایشی خانه');

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('نگاره‌ی آزمایشی بنر')
            ->assertDontSee('اسلاید پنهان')
            ->assertSee($cataloger->name)
            ->assertSee('عنوان آزمایشی خانه')
            ->assertSee(Fa::num(SiteStats::all()['works']))
            ->assertDontSee('۷۶ هزار');
    }

    public function test_empty_setting_falls_back_to_default()
    {
        SiteSetting::put('home.eyebrow', null);
        $this->assertSame(SiteSetting::DEFAULTS['home.eyebrow'], SiteSetting::get('home.eyebrow'));

        $this->actingAs($this->admin)
            ->put(route('admin.home.settings'), ['home__eyebrow' => 'سرعنوان تازه', 'home__slide_interval' => 5])
            ->assertRedirect();
        $this->assertSame('سرعنوان تازه', SiteSetting::get('home.eyebrow'));
        $this->assertSame('5', SiteSetting::get('home.slide_interval'));
    }

    public function test_admin_adds_edits_reorders_and_removes_a_slide()
    {
        $this->slide(['title' => 'اسلاید پیشین']);
        $this->actingAs($this->admin)->get(route('admin.home.index'))->assertOk()->assertSee('اسلایدهای بنر');

        $this->post(route('admin.home.items.store'), [
            'section' => 'slides',
            'title' => 'اسلاید تازه',
            'link_url' => '/works/1',
            'is_active' => 1,
            'image' => $this->jpeg('banner.jpg'),
        ])->assertRedirect();

        $item = HomeItem::where('title', 'اسلاید تازه')->firstOrFail();
        Storage::disk('public')->assertExists($item->image_path);

        $this->put(route('admin.home.items.update', $item), ['title' => 'اسلاید ویرایش‌شده', 'is_active' => 0])->assertRedirect();
        $this->assertFalse($item->fresh()->is_active);
        $this->assertSame('اسلاید ویرایش‌شده', $item->fresh()->title);

        $last = HomeItem::section('slides')->get()->last();
        $this->assertSame($item->id, $last->id);
        $this->post(route('admin.home.items.move', $item), ['direction' => 'up'])->assertRedirect();
        $this->assertNotSame($item->id, HomeItem::section('slides')->get()->last()->id);

        $path = $item->image_path;
        $this->delete(route('admin.home.items.destroy', $item))->assertRedirect();
        $this->assertNull(HomeItem::find($item->id));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_link_must_be_a_web_or_site_path()
    {
        $this->actingAs($this->admin)->post(route('admin.home.items.store'), [
            'section' => 'treasures',
            'link_url' => 'javascript:alert(1)',
            'image' => $this->jpeg('t.jpg'),
        ])->assertSessionHasErrors('link_url');
    }

    public function test_admin_chooses_and_orders_home_catalogers()
    {
        Cataloger::query()->update(['home_order' => null]);
        $a = $this->cataloger('فهرست‌نگار آزمایشی الف');
        $b = $this->cataloger('فهرست‌نگار آزمایشی ب');

        $this->actingAs($this->admin);
        $this->post(route('admin.home.catalogers.add'), ['cataloger_id' => $a->id])->assertRedirect();
        $this->post(route('admin.home.catalogers.add'), ['cataloger_id' => $b->id])->assertRedirect();
        $this->assertSame([1, 2], [$a->fresh()->home_order, $b->fresh()->home_order]);

        $this->post(route('admin.home.catalogers.move', $b), ['direction' => 'up'])->assertRedirect();
        $this->assertSame([2, 1], [$a->fresh()->home_order, $b->fresh()->home_order]);

        $this->delete(route('admin.home.catalogers.remove', $b))->assertRedirect();
        $this->assertNull($b->fresh()->home_order);
        $this->assertSame(1, $a->fresh()->home_order);
    }

    public function test_non_admin_cannot_manage_the_home_page()
    {
        $user = User::firstOrCreate(
            ['email' => 'test-user@fahares.net'],
            ['name' => 'Normal User', 'password' => 'password123', 'role' => 'user']
        );
        $user->role = 'user';
        $user->save();

        $this->actingAs($user)->get(route('admin.home.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.home.items.store'), ['section' => 'slides'])->assertForbidden();
    }
}
