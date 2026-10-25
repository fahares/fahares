<?php

namespace App\Review;

use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\Subject;
use App\Suggestions\SuggestionRegistry;
use App\Support\Fa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The catalogue pages matched by machine (the panel's «نشانی‌های خودکار فهرست», backlog item 51): is this copy the one
 * described on this page of the printed catalogue? An answer is a suggestion on the copy's address field in that
 * catalogue (source_entry_{id}): its present address to confirm, «نیست» to reject it, or another page.
 */
class CatalogMatchQueue extends ReviewQueue
{
    public const ANSWERS = [
        'yes' => 'آری، همین نسخه است',
        'no' => 'نه، این نسخه در این صفحه نیست',
        'page' => 'در صفحه‌ی دیگری از همین فهرست است',
    ];

    public function key(): string
    {
        return 'catalog';
    }

    public function title(): string
    {
        return 'نسخه در فهرست چاپی';
    }

    public function blurb(): string
    {
        return 'برگه‌ی فهرست چاپی کتابخانه را ببینید و بگویید این نسخه همان است که آنجا وصف شده یا نه.';
    }

    public function open(): Builder
    {
        return SourceEntry::query()->where('source_entries.origin', SourceEntry::ORIGIN_MATCH)->where('source_entries.status', SourceEntry::STATUS_UNVERIFIED)
            ->where('source_entries.citable_type', Manuscript::class)->whereNotNull('source_entries.page_start');
    }

    public function filterOptions(): array
    {
        return Cache::remember('collective-review.catalog.filters', 600, function () {
            $open = $this->open()->join('manuscripts as fm', 'fm.id', '=', 'source_entries.citable_id');
            $libraries = (clone $open)->whereNotNull('fm.library_id')->groupBy('fm.library_id')
                ->selectRaw('fm.library_id, COUNT(*) n')->pluck('n', 'library_id');
            $names = Library::whereIn('id', $libraries->keys())->pluck('name', 'id');
            $centuries = (clone $open)->whereNotNull('fm.copy_date_hijri_year')
                ->groupByRaw('CEIL(fm.copy_date_hijri_year / 100)')->orderByRaw('CEIL(fm.copy_date_hijri_year / 100)')
                ->selectRaw('CEIL(fm.copy_date_hijri_year / 100) c, COUNT(*) n')->pluck('n', 'c');
            $subjects = (clone $open)->join('subject_work as sw', 'sw.work_id', '=', 'fm.work_id')
                ->join('subjects as s', 's.id', '=', 'sw.subject_id')
                ->groupByRaw('COALESCE(s.parent_id, s.id)')
                ->selectRaw('COALESCE(s.parent_id, s.id) top, COUNT(DISTINCT source_entries.id) n')->pluck('n', 'top');
            $subjectNames = Subject::whereIn('id', $subjects->keys())->pluck('name', 'id');

            return [
                'library' => ['label' => 'کتابخانه', 'options' => $libraries->sortDesc()
                    ->mapWithKeys(fn ($n, $id) => [$id => ($names[$id] ?? '#' . $id) . ' (' . Fa::num($n) . ')'])->all()],
                'century' => ['label' => 'سده‌ی کتابت', 'options' => $centuries
                    ->mapWithKeys(fn ($n, $c) => [(int) $c => 'سده‌ی ' . Fa::digits((string) (int) $c) . ' (' . Fa::num($n) . ')'])->all()],
                'subject' => ['label' => 'موضوع', 'options' => $subjects->sortDesc()
                    ->mapWithKeys(fn ($n, $id) => [$id => ($subjectNames[$id] ?? '#' . $id) . ' (' . Fa::num($n) . ')'])->all()],
            ];
        });
    }

    public function filter(Builder $query, array $filters): Builder
    {
        if (! $filters) {
            return $query;
        }
        $copies = Manuscript::query()->select('id')
            ->when($filters['library'] ?? null, fn ($q, $id) => $q->where('library_id', (int) $id))
            ->when($filters['century'] ?? null, fn ($q, $c) => $q->whereBetween('copy_date_hijri_year', [((int) $c - 1) * 100 + 1, (int) $c * 100]))
            ->when($filters['subject'] ?? null, fn ($q, $id) => $q->whereIn('work_id', DB::table('subject_work as sw')->join('subjects as s', 's.id', '=', 'sw.subject_id')
                ->where(fn ($w) => $w->where('s.id', (int) $id)->orWhere('s.parent_id', (int) $id))->select('sw.work_id')));

        return $query->whereIn('source_entries.citable_id', $copies);
    }

    /** Surest matches first: the easy ones teach the task; the hard ones wait for those who know the catalogue. */
    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('source_entries.confidence')->orderBy('source_entries.source_volume_id')->orderBy('source_entries.page_start')->orderBy('source_entries.id');
    }

    public function card(Model $item): array
    {
        $item->loadMissing(['source', 'sourceVolume', 'citable.work', 'citable.libraryRecord']);
        $copy = $item->citable;

        return [
            'entry' => $item,
            'copy' => $copy,
            'work' => $copy?->work,
            'catalog' => implode('، ', array_filter([$item->sourceName(), $item->editionLabel() ? 'چاپ ' . Fa::digits($item->editionLabel()) : null])),
            'place' => $item->placeLabel(),
            'volume' => $item->volumeNumber(),
            'unit' => $item->source?->unit === 'ستون' ? 'ستون' : 'ص',
            'viewer' => $item->viewer(),
            'image' => $item->thumbUrl(),
            'text' => $item->cleanEntryText(),
            'facts' => array_filter([
                'کتابخانه' => $copy?->libraryRecord?->name ?? $copy?->library,
                'شماره‌ی نسخه' => $copy?->shelfmark ? Fa::digits($copy->shelfmark) : null,
                'کاتب' => $copy?->scribe_name,
                'تاریخ کتابت' => $copy?->copy_date_raw ? Fa::digits($copy->copy_date_raw) : null,
                'نوع خط' => $copy?->script_names,
                'شمار برگ' => $copy?->folios ? Fa::digits((string) $copy->folios) : null,
                'شمار سطر' => $copy?->lines ? Fa::digits((string) $copy->lines) : null,
                'اندازه' => $copy?->dimensions ? Fa::digits($copy->dimensions) : null,
            ]),
            // the opening and closing words: often the surest match with the catalogue's entry
            'quotes' => array_filter([
                'آغاز' => $this->quote($copy?->incipits_list ?? []),
                'انجام' => $this->quote($copy?->explicits_list ?? []),
            ]),
        ];
    }

    /** The copy's own first opening or closing words; not the stand-in text of a «برابر» with no work text. */
    private function quote(array $list): ?string
    {
        $item = collect($list)->first(fn ($q) => empty($q['is_placeholder']) && trim((string) ($q['text'] ?? '')) !== '');

        return $item ? trim(preg_replace('/<!--\s*page:\s*\d+\s*-->/u', '', $item['text'])) : null;
    }

    public function detailed(): array
    {
        return ['page'];
    }

    public function answers(): array
    {
        return self::ANSWERS;
    }

    public function record(Model $item): Model
    {
        return $item->citable;
    }

    public function suggestion(Model $item, string $answer, array $input): array
    {
        if (! array_key_exists($answer, self::ANSWERS)) {
            throw ValidationException::withMessages(['answer' => 'یکی از پاسخ‌ها را برگزینید.']);
        }
        $name = SuggestionRegistry::SOURCE_ENTRY_PREFIX . $item->id;
        $field = SuggestionRegistry::field(Manuscript::class, $name);
        if (! $field) {
            throw ValidationException::withMessages(['answer' => 'این نشانی دیگر پرسیدنی نیست.']);
        }
        $current = SuggestionRegistry::currentValue($item->citable, $field);

        $value = match ($answer) {
            'yes' => $current,
            'no' => SuggestionRegistry::ABSENT,
            'page' => $this->otherPage($item, (string) ($input['page'] ?? ''), $current),
        };

        return [
            'kind' => 'field',
            'field_name' => $name,
            'current_value' => $current,
            'suggested_value' => $value,
            'payload' => null,
            'review_answer' => $answer === 'page' ? 'page:' . $value : $answer,
        ];
    }

    /** «۴۵» or «ص ۴۵» is a page of the same volume; «ج ۳، ص ۴۵» names another volume of the catalogue. */
    private function otherPage(SourceEntry $entry, string $input, ?string $current): string
    {
        $latin = strtr(trim($input), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $page = SuggestionRegistry::parseCatalogPage($latin)
            ?? (preg_match('/^(?:ص|ستون)?\s*\.?\s*(\d+)$/u', $latin, $m) ? ['volume' => $entry->volumeNumber() ?? 1, 'page' => (int) $m[1], 'row' => null] : null);
        if (! $page) {
            throw ValidationException::withMessages(['page' => 'شماره‌ی صفحه را بنویسید، مثلاً ۴۵ یا «ج ۲، ص ۴۵».']);
        }
        $value = SuggestionRegistry::formatCatalogPage($page['volume'], $page['page'], null, $entry->source?->unit === 'ستون' ? 'ستون' : 'ص');
        if ($value === $current) {
            throw ValidationException::withMessages(['page' => 'این همان صفحه‌ای است که نشان داده شده؛ اگر نسخه همین است «آری» را بزنید.']);
        }

        return $value;
    }

    public function describe(FieldSuggestion $opinion): string
    {
        return match (true) {
            $opinion->review_answer === 'yes' => 'همین نسخه است (' . Fa::digits((string) $opinion->current_value) . ')',
            $opinion->review_answer === 'no' => 'این نسخه در آن صفحه نیست',
            default => 'در صفحه‌ی دیگری است: ' . Fa::digits((string) $opinion->suggested_value),
        };
    }

    /** The decision of the panel's queue in the answers' form. */
    public static function decision(SourceEntry $entry): ?string
    {
        return match ($entry->status) {
            SourceEntry::STATUS_VERIFIED => 'yes',
            SourceEntry::STATUS_REJECTED => 'no',
            default => null,
        };
    }

    public function reviewUrl(int $itemId): ?string
    {
        $entry = SourceEntry::find($itemId);

        return $entry ? route('admin.catalog-references.index', ['volume' => $entry->source_volume_id]) : null;
    }
}
