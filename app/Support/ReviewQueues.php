<?php

namespace App\Support;

use App\Models\Claim;
use App\Models\DateConflict;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\PersonPeriod;
use App\Models\PersonReview;
use App\Models\SourceEntry;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkEntryLink;
use Illuminate\Support\Carbon;

/**
 * «صف‌های بررسی» (step 14.397): the review queues the machine fills (matches, links, namesakes, conflicts), on one
 * page of the panel and under one item of its menu. The suggestions of the site's users keep their own item.
 *
 * Each queue: its group, label, hint, page, the permission that opens it, and the query of its waiting work. A
 * «soft» queue is work proposed in bulk that may wait: it is listed, but not added to the menu's count.
 */
class ReviewQueues
{
    public const GROUPS = ['people' => 'اشخاص', 'works' => 'آثار و نسخه‌ها', 'catalogues' => 'فهرست‌های چاپی'];

    /** @return array<int, array{group: string, label: string, hint: string, route: string|array, active: string|\Closure, can: string, query: \Closure, soft?: bool, dated?: bool}> */
    public static function all(): array
    {
        $mixed = fn () => request()->routeIs('admin.person-reviews.*') && request()->query('type') === 'mixed';

        return [
            ['group' => 'people', 'label' => 'اشخاص هم‌نام', 'hint' => 'دو رکورد که شاید یک شخص‌اند؛ ادغام یا رد',
                'route' => 'admin.person-reviews.index', 'active' => fn () => request()->routeIs('admin.person-reviews.*') && ! $mixed(), 'can' => 'people.merge',
                'query' => fn () => PersonReview::where('type', 'duplicate')->where('status', 'open')],
            ['group' => 'people', 'label' => 'رکوردهای قاطی‌شده', 'hint' => 'یک رکورد که چند شخص را در هم آمیخته است؛ تفکیک',
                'route' => ['admin.person-reviews.index', ['type' => 'mixed']], 'active' => $mixed, 'can' => 'structure.split',
                'query' => fn () => PersonReview::where('type', 'mixed')->where('status', 'open')],
            ['group' => 'people', 'label' => 'پیوند پدیدآور', 'hint' => 'پدیدآوری که فهرست فقط نامش را آورده و شخصی که شاید همان است',
                'route' => 'admin.author-links.index', 'active' => 'admin.author-links.*', 'can' => 'structure.link',
                'query' => fn () => PersonReview::where('type', 'author_link')->where('status', 'open')],
            ['group' => 'people', 'label' => 'زندگی‌نامه‌های خودکار اشخاص', 'hint' => 'زندگی‌نامه‌هایی که ماشین به شخص پیوند داده است',
                'route' => 'admin.person-lives.index', 'active' => 'admin.person-lives.*', 'can' => 'catalog_refs.review',
                'query' => fn () => SourceEntry::awaitingReview()->where('citable_type', Person::class)->whereNotNull('source_item_id')],
            ['group' => 'people', 'label' => 'پیشنهادهای دورۀ زندگی', 'hint' => 'دوره‌هایی که از زندگی‌نامه‌ها خوانده شده است',
                'route' => 'admin.people.periods.proposals', 'active' => 'admin.people.periods.proposals', 'can' => 'people.edit', 'soft' => true,
                'query' => fn () => PersonPeriod::where('status', PersonPeriod::PROPOSED)],
            ['group' => 'works', 'label' => 'نشانی‌های خودکار فهرست', 'hint' => 'نسخه‌هایی که ماشین به برگۀ فهرست چاپی پیوند داده است',
                'route' => 'admin.catalog-references.index', 'active' => 'admin.catalog-references.*', 'can' => 'catalog_refs.review',
                'query' => fn () => SourceEntry::awaitingReview()->where('citable_type', Manuscript::class)],
            ['group' => 'works', 'label' => 'پیوندهای خودکار آثار', 'hint' => 'آثاری که ماشین به مدخل کتاب‌شناسی پیوند داده است',
                'route' => 'admin.source-links.index', 'active' => 'admin.source-links.*', 'can' => 'catalog_refs.review',
                'query' => fn () => SourceEntry::awaitingReview()->where('citable_type', Work::class)->whereNotNull('source_item_id')],
            ['group' => 'works', 'label' => 'پیوند آثار مرتبط', 'hint' => 'آثار مرتبطی که فهرست نام برده و اثری از سایت که شاید همان است',
                'route' => 'admin.work-entry-links.index', 'active' => 'admin.work-entry-links.*', 'can' => 'structure.link', 'soft' => true, 'dated' => false,
                'query' => fn () => WorkEntryLink::review()->where('link_status', WorkEntryLink::PROPOSED)],
            ['group' => 'works', 'label' => 'تناقض‌های تاریخی', 'hint' => 'تاریخ‌هایی که با هم نمی‌خوانند، مانند کتابت پیش از تألیف',
                'route' => 'admin.date-conflicts.index', 'active' => 'admin.date-conflicts.*', 'can' => 'suggestions.review', 'soft' => true, 'dated' => false,
                'query' => fn () => DateConflict::open()->where('direct', true)],
            ['group' => 'catalogues', 'label' => 'ادعاهای فهرست‌های چاپی', 'hint' => 'آنچه فهرست‌های کتابخانه‌ها دربارۀ رکوردها می‌گویند و با سایت نمی‌خواند',
                'route' => 'admin.claims.index', 'active' => 'admin.claims.*', 'can' => 'suggestions.review', 'soft' => true,
                'query' => fn () => Claim::candidate()],
        ];
    }

    /** The queues this user may open. */
    public static function forUser(User $user): array
    {
        return array_values(array_filter(self::all(), fn ($q) => $user->can($q['can'])));
    }

    /** Whether the page shown is one of the queues, for the menu. */
    public static function isActive(User $user): bool
    {
        foreach (self::forUser($user) as $q) {
            if ($q['active'] instanceof \Closure ? ($q['active'])() : request()->routeIs(...(array) $q['active'])) {
                return true;
            }
        }

        return request()->routeIs('admin.review-queues');
    }

    /** The waiting work of the queues that may not wait, for the menu's count. */
    public static function count(User $user): int
    {
        return array_sum(array_map(fn ($q) => ($q['query'])()->count(), array_filter(self::forUser($user), fn ($q) => empty($q['soft']))));
    }

    /**
     * The queues of the page, by group: count, the age of the oldest item where the table keeps it, and the url.
     *
     * @return array<string, array<int, array>>
     */
    public static function cards(User $user): array
    {
        $groups = [];
        foreach (self::forUser($user) as $q) {
            $count = ($q['query'])()->count();
            $builder = ($q['query'])();
            $oldest = $count && ($q['dated'] ?? true) ? $builder->min($builder->getModel()->qualifyColumn('created_at')) : null;
            [$name, $params] = is_array($q['route']) ? $q['route'] : [$q['route'], []];
            $groups[self::GROUPS[$q['group']]][] = [
                'label' => $q['label'],
                'hint' => $q['hint'],
                'url' => route($name, $params),
                'count' => $count,
                'soft' => $q['soft'] ?? false,
                'oldest' => $oldest ? Jalali::ago(Carbon::parse($oldest)) : null,
            ];
        }

        return $groups;
    }
}
