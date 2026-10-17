<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Short texts the admin edits without a deploy, such as the home page headline.
 * DEFAULTS is the full list of keys; a key with no row falls back to its default.
 */
class SiteSetting extends Model
{
    public const DEFAULTS = [
        'home.eyebrow' => 'پایگاه جامع نسخه‌های خطی',
        'home.headline' => 'جستجو در آثار و نسخه‌های خطی',
        'home.lede' => 'شناسنامه‌ی آثار، نسخه‌ها، پدیدآوران، کاتبان و کتابخانه‌ها، گردآمده از فهرست‌های معتبر. هر داده به مأخذ چاپی خودش ارجاع دارد.',
        'home.treasures_title' => 'از گنجینه‌ها',
        'home.catalogers_title' => 'فهرست‌نگاران',
        'home.slide_interval' => '7',
    ];

    public const LABELS = [
        'home.eyebrow' => 'سرعنوان کوچک بالای عنوان',
        'home.headline' => 'عنوان اصلی صفحه‌ی خانه',
        'home.lede' => 'توضیح زیر عنوان',
        'home.treasures_title' => 'عنوان بخش تصویرهای برگزیده',
        'home.catalogers_title' => 'عنوان بخش فهرست‌نگاران',
        'home.slide_interval' => 'فاصله‌ی چرخش اسلایدها (ثانیه)',
    ];

    private const CACHE_KEY = 'site_settings';

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public static function get(string $key): ?string
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());

        return $all[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }
}
