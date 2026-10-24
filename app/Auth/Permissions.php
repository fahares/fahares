<?php

namespace App\Auth;

use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The admin panel's access matrix (backlog item 65). The permissions are defined here, because each one guards
 * routes and code; which role holds which is stored in role_permissions and edited by the admin at admin/roles.
 * The admin role always holds every permission and its column cannot be edited, so nobody can lock the panel.
 * Each key is also a Gate ability (see AppServiceProvider), used as `can:<key>` on routes and @can in views.
 */
final class Permissions
{
    public const SUPER_ROLE = 'admin';

    private const CACHE_KEY = 'role_permissions';

    public const GROUPS = [
        'review' => 'داوری',
        'data' => 'داده‌ها و مراجع',
        'content' => 'محتوای سایت',
        'system' => 'سامانه',
    ];

    /** key => [group, label, description, roles granted by default besides admin] */
    public const ALL = [
        'panel.access' => ['review', 'ورود به پنل و پیشخوان', 'پیش‌نیاز همه‌ی بخش‌های پنل.', ['editor']],
        'suggestions.review' => ['review', 'داوری پیشنهادها', 'فهرست و داوری پیشنهادهای اصلاح، یادداشت‌ها و گزارش‌ها: تأیید فیلد، رد، بازگشایی، تبدیل و اعتبار.', ['editor']],
        'suggestions.direct_edit' => ['review', 'ویرایش مستقیم فیلد', 'اصلاح بی‌درنگ غلط تایپی در صفحه‌ی رکورد، بی‌آنکه پیشنهادی ساخته شود.', ['editor']],
        'structure.link' => ['review', 'پیوند پدیدآور، کاتب، نام دیگر و روابط', 'تأیید پیشنهادهای پیوند شخص با اثر یا نسخه، نام دیگر و رابطه با رکورد دیگر.', ['editor']],
        'structure.move' => ['review', 'انتقال نسخه و تغییر جای نسخه', 'انتقال نسخه به اثر دیگر و تغییر کتابخانه یا شماره‌ی نسخه.', ['editor']],
        'structure.merge_records' => ['review', 'ادغام اثر یا نسخه‌ی تکراری', 'ادغام دو اثر یا دو نسخه؛ از لاگ ممیزی بازگرداندنی است.', ['editor']],
        'structure.library' => ['review', 'ادغام و تغییر نام کتابخانه', 'همه‌ی نسخه‌ها و فهرست‌های یک کتابخانه به کتابخانه‌ی دیگر منتقل می‌شوند.', []],
        'structure.split' => ['review', 'تفکیک رکورد خلط‌شده', 'تفکیک اثر یا شخصی که دو رکورد را در هم آمیخته است.', []],
        'structure.remove' => ['review', 'حذف رکورد زائد', 'حذف اثر، نسخه یا شخص زائد.', []],
        'catalog_refs.review' => ['review', 'نشانی‌های خودکار فهرست', 'تأیید و رد نشانی‌ها و داده‌های هوشواره، در پنل و در صفحه‌های سایت.', ['editor']],
        'source_entries.edit' => ['review', 'اصلاح متن مأخذ', 'ویرایش متن مدخل‌های مأخذ و فهرست چاپی.', ['editor']],
        'people.edit' => ['data', 'ویرایش اشخاص', 'فهرست، جستجو و ویرایش مشخصات اشخاص.', ['editor']],
        'people.merge' => ['data', 'ادغام اشخاص', 'ادغام دو شخص، از پنل یا از راه پیشنهاد.', ['editor']],
        'subjects.edit' => ['data', 'موضوعات', 'فهرست، ساخت و ویرایش موضوعات، درخت موضوعات و بازشماری.', []],
        'subjects.merge' => ['data', 'ادغام موضوعات', 'ادغام دو موضوع.', []],
        'catalogers.edit' => ['data', 'فهرست‌نگاران', 'فهرست و ویرایش مشخصات فهرست‌نگاران.', ['editor']],
        'libraries.edit' => ['data', 'کتابخانه‌ها', 'فهرست و ویرایش مشخصات کتابخانه‌ها.', ['editor']],
        'home.manage' => ['content', 'صفحه‌ی خانه', 'تنظیمات و بخش‌های صفحه‌ی خانه.', []],
        'pages.manage' => ['content', 'صفحه‌ها', 'متن صفحه‌های ثابت مانند «درباره ما».', []],
        'posts.manage' => ['content', 'نوشته‌ها', 'نوشتن، انتشار و حذف نوشته‌ها و دیدن نوشته‌ی منتشرنشده.', []],
        'audit.view' => ['system', 'دیدن لاگ ممیزی', 'فهرست رخدادهای ثبت‌شده، بدون بازگردانی.', ['editor']],
        'audit.rollback' => ['system', 'بازگردانی از لاگ ممیزی', 'برگرداندن تغییرهای ثبت‌شده در لاگ ممیزی.', []],
        'system.manage' => ['system', 'عملیات سامانه', 'پاک‌کردن کش و نمایه‌سازی دوباره‌ی نسخه‌ها.', []],
        'users.manage' => ['system', 'کاربران و نقش‌ها', 'ویرایش، حذف و تعیین نقش کاربران.', []],
        'roles.manage' => ['system', 'ماتریس دسترسی', 'تعیین اینکه هر نقش به کدام بخش پنل دسترسی دارد.', []],
    ];

    /** The relational suggestion's `apply` => the permission its approval or reopening needs (see SuggestionRegistry::RELATIONS) */
    public const FOR_APPLY = [
        'contributor_set' => 'structure.link',
        'contributor_add' => 'structure.link',
        'alias' => 'structure.link',
        'person_role' => 'structure.link',
        'record_relation' => 'structure.link',
        'move' => 'structure.move',
        'relocate' => 'structure.move',
        'merge' => 'structure.merge_records',
        'merge_library' => 'structure.library',
        'rename_library' => 'structure.library',
        'split' => 'structure.split',
        'remove' => 'structure.remove',
        'merge_people' => 'people.merge',
    ];

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::ALL);
    }

    public static function label(string $key): string
    {
        return self::ALL[$key][1] ?? $key;
    }

    /** The permissions grouped for the matrix page: group label => [key => definition] */
    public static function grouped(): array
    {
        $grouped = [];
        foreach (self::ALL as $key => [$group, $label, $description]) {
            $grouped[self::GROUPS[$group]][$key] = ['label' => $label, 'description' => $description];
        }

        return $grouped;
    }

    public static function allows(?string $role, string $key): bool
    {
        if ($role === self::SUPER_ROLE) {
            return self::exists($key);
        }

        return in_array($key, self::matrix()[$role] ?? [], true);
    }

    /** role => granted keys, as stored; the admin role is not stored */
    public static function matrix(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('role_permissions')
            ->whereIn('permission', array_keys(self::ALL))
            ->get()->groupBy('role')
            ->map(fn ($rows) => $rows->pluck('permission')->sort()->values()->all())
            ->all());
    }

    /** role => keys granted when the matrix is first created or reset */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::ALL as $key => [, , , $roles]) {
            foreach ($roles as $role) {
                $defaults[$role][] = $key;
            }
        }

        return $defaults;
    }

    /**
     * Replaces the stored grants of the editable roles. The admin role and unknown keys are ignored.
     * Returns [role => ['added' => keys, 'removed' => keys]] for the roles that changed.
     */
    public static function save(array $grants): array
    {
        $before = self::matrix();
        $changes = [];

        DB::transaction(function () use ($grants, $before, &$changes) {
            foreach (Role::editable()->pluck('key') as $role) {
                $keys = array_values(array_intersect(array_keys(self::ALL), $grants[$role] ?? []));
                $old = $before[$role] ?? [];
                $added = array_values(array_diff($keys, $old));
                $removed = array_values(array_diff($old, $keys));
                if (! $added && ! $removed) {
                    continue;
                }
                DB::table('role_permissions')->where('role', $role)->delete();
                DB::table('role_permissions')->insert(array_map(fn ($key) => ['role' => $role, 'permission' => $key], $keys));
                $changes[$role] = ['added' => $added, 'removed' => $removed];
            }
        });
        self::flush();

        return $changes;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
