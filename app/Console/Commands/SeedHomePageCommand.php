<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\HomeItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * First content of the admin-managed home page: four banner slides, three «از گنجینه‌ها» images
 * (Walters Art Museum, public domain) and six catalogers. Images are copied from
 * resources/home-defaults to the public disk. A section that already has rows is left alone,
 * so running the command again never overwrites what the admin has changed.
 */
class SeedHomePageCommand extends Command
{
    protected $signature = 'home:seed-defaults {--dry-run : Show what would be added without writing}';

    protected $description = 'Add the first banner slides, featured images and home catalogers if none exist';

    private const SLIDES = [
        ['file' => 'slide-1.jpg', 'title' => 'نگاره‌ی معراج پیامبر (ص)، خمسه‌ی نظامی، کتابخانه‌ی مدرسه‌ی سپهسالار'],
        ['file' => 'slide-2.jpg', 'title' => 'نمایشگاه «خطوط از مشرق زمین» در موزه‌ی چاپ و کتاب مادرید'],
        ['file' => 'slide-3.jpg', 'title' => 'پنجاه سال با دکتر مهدی بیانی'],
        ['file' => 'slide-4.jpg', 'title' => 'کتابخانه‌ی ملی ایران'],
    ];

    private const TREASURES = [
        ['file' => 'shamseh.jpg', 'title' => 'شمسه', 'subtitle' => 'کلیات سعدی، برگ آغاز', 'credit' => 'موزه‌ی والترز W.620، مالکیت عمومی',
            'link_url' => 'https://commons.wikimedia.org/wiki/File:Sa%27di_-_Illuminated_Frontispiece_with_Medallion_-_Walters_W6201A_-_Full_Page.jpg'],
        ['file' => 'colophon.jpg', 'title' => 'ترقیمه', 'subtitle' => 'دیوان حافظ، انجامه و ترقیمه', 'credit' => 'موزه‌ی والترز W.634، مالکیت عمومی',
            'link_url' => 'https://commons.wikimedia.org/wiki/File:Hafiz_-_Illuminated_Tailpiece_and_Colophon_-_Walters_W634199A_-_Full_Page.jpg'],
        ['file' => 'binding.jpg', 'title' => 'جلد لاکی', 'subtitle' => 'بوستان سعدی، جلد', 'credit' => 'موزه‌ی والترز W.621، مالکیت عمومی',
            'link_url' => 'https://commons.wikimedia.org/wiki/File:Sa%27di_-_The_Orchard_(Bustan)_-_Walters_W621_-_Top_Exterior.jpg'],
    ];

    private const CATALOGERS = ['مصطفی درایتی', 'محمدتقی دانش‌پژوه', 'احمد گلچین معانی', 'سعید نفیسی', 'نجیب مایل هروی', 'یوسف اعتصامی'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->seedSection('slides', self::SLIDES, $dry);
        $this->seedSection('treasures', self::TREASURES, $dry);

        if (Cataloger::whereNotNull('home_order')->exists()) {
            $this->line('فهرست‌نگاران: از پیش انتخاب شده‌اند؛ تغییری داده نشد.');
        } else {
            $order = 0;
            foreach (self::CATALOGERS as $name) {
                $cataloger = Cataloger::where('name', $name)->first();
                if (! $cataloger) {
                    $this->warn("فهرست‌نگار «{$name}» پیدا نشد.");
                    continue;
                }
                $order++;
                if (! $dry) {
                    $cataloger->update(['home_order' => $order]);
                }
                $this->line("فهرست‌نگار {$order}: {$name}");
            }
        }

        $this->info($dry ? 'اجرای آزمایشی؛ چیزی نوشته نشد.' : 'انجام شد.');

        return self::SUCCESS;
    }

    private function seedSection(string $section, array $rows, bool $dry): void
    {
        if (HomeItem::where('section', $section)->exists()) {
            $this->line("{$section}: از پیش تصویر دارد؛ تغییری داده نشد.");

            return;
        }

        foreach ($rows as $i => $row) {
            $source = resource_path("home-defaults/{$section}/{$row['file']}");
            $target = "home/{$section}/{$row['file']}";
            if (! $dry) {
                Storage::disk('public')->put($target, file_get_contents($source));
                HomeItem::create([
                    'section' => $section,
                    'title' => $row['title'],
                    'subtitle' => $row['subtitle'] ?? null,
                    'credit' => $row['credit'] ?? null,
                    'link_url' => $row['link_url'] ?? null,
                    'image_path' => $target,
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ]);
            }
            $this->line("{$section} " . ($i + 1) . ": {$row['title']}");
        }
    }
}
