<?php

namespace App\Suggestions;

use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Support\Fa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What a structural tool would change, read before it runs (backlog item 45): the admin sees it on the
 * confirmation page of a direct action. Nothing here writes; the tools themselves stay in RecordMerger,
 * LibraryMerger, RecordSplitter, RecordRemover, ContributorRelations, RecordRelations and ManuscriptLocation.
 */
class StructurePreview
{
    /**
     * Each record the tool touches with what hangs on it, and sentences on the change.
     *
     * @return array{records: array<int, array{record: array, counts: array<string, int>}>, lines: string[], warnings: string[]}
     */
    public static function of(array $relation, Model $record, ?Model $other): array
    {
        $records = [['record' => SuggestionRegistry::describe($record), 'counts' => self::counts($record)]];
        if ($other) {
            $records[] = ['record' => SuggestionRegistry::describe($other), 'counts' => self::counts($other)];
        }
        $lines = [];
        $warnings = [];
        $n = fn ($v) => Fa::num((int) $v);

        switch ($relation['apply'] ?? null) {
            case 'move':
                $lines[] = "نسخه از اثر «{$record->work?->primary_title}» ({$n($record->work?->manuscripts_count)} نسخه) به اثر «{$other->primary_title}» ({$n($other->manuscripts_count)} نسخه) منتقل می‌شود.";
                break;
            case 'merge':
                // Either record may be kept (the form's «کدام بماند»); by default the other one
                $merger = app(RecordMerger::class);
                foreach ([[$record, $other], [$other, $record]] as $i => [$source, $kept]) {
                    $p = $merger->preview($source, $kept);
                    // Columns by the field names the public reads; link and year columns go with their field
                    $labels = SuggestionRegistry::fields($source::class);
                    foreach (['filled', 'dropped'] as $side) {
                        $p[$side] = array_values(array_unique(array_filter(array_map(fn ($c) => $labels[$c]['label'] ?? null, $p[$side]))));
                    }
                    $lead = $i === 0 ? 'اگر #' . Fa::digits($kept->id) . ' بماند (پیش‌فرض)' : 'اگر #' . Fa::digits($kept->id) . ' بماند';
                    $parts = [];
                    if ($source instanceof Work) {
                        $parts[] = "{$n($p['moved'])} نسخه منتقل و {$n($p['folded'])} نسخه‌ی هم‌نشانی ادغام می‌شود";
                    }
                    $parts[] = $p['filled'] ? $n(count($p['filled'])) . ' فیلد خالی پر می‌شود' : 'فیلد خالی‌ای پر نمی‌شود';
                    if ($p['dropped']) {
                        $parts[] = $n(count($p['dropped'])) . ' مقدار متفاوت رکورد حذف‌شونده فقط در لاگ ممیزی می‌ماند (' . implode('، ', $p['dropped']) . ')';
                    }
                    $lines[] = $lead . ': ' . implode('؛ ', $parts) . '.';
                }
                break;
            case 'merge_library':
                $lines[] = "اگر «{$other->name}» بماند (پیش‌فرض): {$n($record->manuscripts()->count())} نسخه و {$n($record->catalogVolumes()->count())} جلد فهرست از «{$record->name}» منتقل می‌شوند.";
                $lines[] = "اگر «{$record->name}» بماند: {$n($other->manuscripts()->count())} نسخه و {$n($other->catalogVolumes()->count())} جلد فهرست از «{$other->name}» منتقل می‌شوند.";
                break;
            case 'rename_library':
                $lines[] = "نام کتابخانه در {$n($record->manuscripts()->count())} نسخه‌اش هم به‌روز می‌شود.";
                break;
            case 'remove':
                $blockers = app(RecordRemover::class)->blockers($record);
                if ($blockers) {
                    $warnings[] = 'این رکورد هنوز به ' . implode('، ', array_map(fn ($label, $count) => Fa::num($count) . ' ' . $label, array_keys($blockers), $blockers)) . ' پیوند دارد و تا آن‌ها منتقل نشوند حذف نمی‌شود.';
                } else {
                    $lines[] = 'رکورد #' . Fa::digits($record->id) . ' حذف می‌شود و نشانی‌اش صفحه‌ی «این رکورد حذف شده است» را نشان می‌دهد.';
                }
                break;
            case 'split':
                $lines[] = 'رکورد تازه‌ای ساخته می‌شود و فقط مواردی که در فرم زیر تیک بزنید به آن منتقل می‌شوند.';
                break;
            case 'contributor_set':
                $current = $record instanceof Manuscript ? $record->scribe_name : $record->author_name;
                $lines[] = "«{$other->name}» " . ($record instanceof Manuscript ? 'کاتب اصلی' : 'پدیدآور اصلی') . ' می‌شود' . ($current ? " به جای «{$current}»" : '') . '.';
                break;
            case 'contributor_add':
                $lines[] = "«{$other->name}» به " . ($record instanceof Manuscript ? 'کاتبان' : 'پدیدآوران') . ' این رکورد افزوده می‌شود.';
                break;
            case 'relocate':
                $lines[] = 'نشانی کنونی نسخه «' . ($record->libraryRecord?->name ?? $record->library ?? '—') . '، شماره‌ی ' . ($record->shelfmark ?? 'بی‌شماره') . '» در «نشانی پیشین» می‌ماند.';
                break;
            case 'merge_people':
                $lines[] = 'ادغام اشخاص در ابزار ادغام اشخاص انجام می‌شود، با مقایسه‌ی کامل دو شخص.';
                break;
        }

        return ['records' => $records, 'lines' => $lines, 'warnings' => $warnings];
    }

    /** What hangs on a record, as labelled counts; zero counts are left out. */
    public static function counts(Model $record): array
    {
        $open = FieldSuggestion::where('suggestable_type', $record::class)->where('suggestable_id', $record->id)->where('status', 'pending')->count();
        $entries = fn () => SourceEntry::where('citable_type', $record::class)->where('citable_id', $record->id)->count();
        $counts = match (true) {
            $record instanceof Work => [
                'نسخه' => $record->manuscripts()->count(),
                'مدخل مأخذ' => $entries(),
                'رابطه با اثر دیگر' => DB::table('work_relations')->where('work_id', $record->id)->orWhere('related_work_id', $record->id)->count(),
            ],
            $record instanceof Manuscript => [
                'کاتب' => DB::table('manuscript_scribe')->where('manuscript_id', $record->id)->count(),
                'شخص مرتبط' => ManuscriptPerson::where('manuscript_id', $record->id)->count(),
                'مدخل مأخذ' => $entries(),
                'رابطه با نسخه‌ی دیگر' => DB::table('manuscript_relations')->where('manuscript_id', $record->id)->orWhere('related_manuscript_id', $record->id)->count(),
            ],
            $record instanceof Person => app(RecordRemover::class)->blockers($record) + [
                'نقش در نسخه' => ManuscriptPerson::where('person_id', $record->id)->count(),
            ],
            $record instanceof Library => [
                'نسخه' => $record->manuscripts()->count(),
                'جلد فهرست' => $record->catalogVolumes()->count(),
            ],
            default => [],
        };

        return array_filter($counts + ['پیشنهاد باز' => $open]);
    }
}
