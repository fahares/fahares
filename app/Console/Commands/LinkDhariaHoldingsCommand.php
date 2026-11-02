<?php

namespace App\Console\Commands;

use App\Models\Library;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * Links the libraries Noor tags as holding a copy in al-Dhari'a's text (OB) to the site's libraries (step 13.145, plan
 * docs/plans/dharia-entity-links.md, step 3): «الخزانة الرضوية», «مكتبة (المجلس)», «إلهيات: 196 ج».
 *
 * A mark is linked when its words, without «مكتبة», «الخزانة», the city and the shelfmark, are one of the forms of
 * LIBRARIES, a hand-made list of the forms most often printed. A copy «عند» someone (a private holding: «عند (الملك)»,
 * «عندي») is not linked, nor a library the site does not have («الآصفية», «المتحف البريطاني») or a place alone («النجف»).
 */
class LinkDhariaHoldingsCommand extends LinkDhariaTitlesCommand
{
    public const KINDS = ['OB'];

    /** The model the links are to. */
    protected const TARGET = Library::class;

    /**
     * The forms of a library al-Dhari'a prints, and the site's library (city, name). The «فهرس» it cites tells which
     * when a name is of more than one: «إلهيات: 196 ج» and «أدبيات: 21 ب» are the Tehran faculties, «الملية - 290 ف»
     * the National Library in Tehran, «المشكاة» the Meshkat collection of the University of Tehran, «پزشكى: 196» and
     * «الحقوق: 275» its faculties of medicine and law. The Senate's library («سنا: 2253») the site does not have.
     */
    public const LIBRARIES = [
        'الرضوية' => ['مشهد', 'رضوی'], 'الخزانة الرضوية' => ['مشهد', 'رضوی'], 'المكتبة الرضوية' => ['مشهد', 'رضوی'],
        'للخزانة الرضوية' => ['مشهد', 'رضوی'],
        'المجلس' => ['تهران', 'مجلس'],
        'الملك' => ['تهران', 'ملک'],
        'دانشگاه' => ['تهران', 'دانشگاه'], 'جامعة طهران' => ['تهران', 'دانشگاه'], 'بجامعة طهران' => ['تهران', 'دانشگاه'],
        'المشكاة' => ['تهران', 'دانشگاه'],
        'سپهسالار' => ['تهران', 'سپهسالار'], 'مدرسة سپهسالار' => ['تهران', 'سپهسالار'],
        'إلهيات' => ['تهران', 'الهیات'],
        'أدبيات' => ['تهران', 'ادبیات'],
        'الملية' => ['تهران', 'ملی'],
        'پزشكى' => ['تهران', 'علوم پزشکی'],
        'الحقوق' => ['تهران', 'حقوق'],
        'دهخدا' => ['تهران', 'لغت نامه دهخدا'],
    ];

    /** Words around a library's name that do not tell which it is (as key() writes them). */
    private const DROP = ['مکتبه', 'مکتبات', 'کتابخانه', 'فی', 'طهران', 'بطهران', 'بخراسان', 'بمشهد', 'مشهد', 'خراسان'];

    protected $signature = 'sources:link-dharia-holdings
        {--volume= : Only this volume}
        {--sample= : Write a random sample of the links of each library, with their context, to this file}
        {--per-kind=50 : Links of each library in the sample}
        {--dry-run : Match and count without saving}';

    protected $description = "Link the libraries tagged in al-Dhari'a's text as holding a copy to the site's libraries";

    public function handle(): int
    {
        $source = Source::where('code', 'dharia')->firstOrFail();
        $byKey = $this->targets();

        $pages = DB::table('source_pages')->join('source_volumes', 'source_volumes.id', '=', 'source_pages.source_volume_id')
            ->where('source_volumes.source_id', $source->id)
            ->when($this->option('volume'), fn ($q, $v) => $q->where('source_volumes.volume_number', $v))
            ->orderBy('source_volumes.volume_number')->orderBy('source_pages.page')
            ->select('source_pages.id', 'source_pages.page', 'source_pages.text', 'source_volumes.volume_number');

        $links = [];
        $count = [];
        $pages->chunk(500, function ($chunk) use ($byKey, &$links, &$count) {
            foreach ($chunk as $page) {
                foreach (self::marksOf($page->text) as $mark) {
                    if (! in_array($mark['kind'], static::KINDS, true)) {
                        continue;
                    }
                    [$target, $method] = static::target($mark['text'], $byKey);
                    $label = $target ? $this->label($target) : $method;
                    $count[$label] = ($count[$label] ?? 0) + 1;
                    if ($target) {
                        $links[] = ['page' => $page, 'mark' => $mark, 'item' => $target, 'method' => $method];
                    }
                }
            }
        });

        arsort($count);
        $this->table(['record or why not', 'marks'], array_map(fn ($k, $n) => [$k, $n], array_keys($count), $count));
        $this->info(count($links) . ' links');

        if ($this->option('sample')) {
            $this->sampleHoldings($links, (int) $this->option('per-kind'));
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        $this->save($links, static::KINDS, static::TARGET);

        return self::SUCCESS;
    }

    /** The record of each form of the list, by its key(). */
    protected function targets(): array
    {
        $byKey = [];
        foreach (self::LIBRARIES as $form => [$city, $name]) {
            $library = Library::where('city', $city)->where('name', $name)->first();
            if (! $library) {
                $this->warn("No library {$name}، {$city}");
                continue;
            }
            $byKey[self::key($form)] = $library;
        }

        return $byKey;
    }

    protected function label($record): string
    {
        return $record->name . '، ' . $record->city;
    }

    /** The record a mark names and how, or null and why not. */
    public static function target(string $marked, array $byKey): array
    {
        return self::library($marked, $byKey);
    }

    /**
     * The library a mark names and how, or null and why not.
     *
     * @return array{0: ?Library, 1: string}
     */
    public static function library(string $marked, array $byKey): array
    {
        $plain = LinkDhariaPeopleCommand::plain($marked);
        if (preg_match('/(^|\s)(عند|عندی|عنده|عندنا)(\s|$)/u', $plain)) {
            return [null, 'private'];
        }
        $key = self::key($marked);

        return isset($byKey[$key]) ? [$byKey[$key], 'form'] : [null, $key === '' ? 'empty' : 'other'];
    }

    /** A form's words, the letters made one, without «ال», punctuation, the shelfmark and DROP: «مكتبة (المجلس):» is «مجلس». */
    public static function key(string $form): string
    {
        $words = explode(' ', LinkDhariaPeopleCommand::plain(str_replace('،', ' ', $form)));
        $words = array_map(fn ($w) => preg_replace('/^ال(?=\S{2})/u', '', $w), $words);

        return implode(' ', array_filter($words, fn ($w) => $w !== '' && ! in_array($w, self::DROP, true) && mb_strlen($w) > 1));
    }

    private function sampleHoldings(array $links, int $perKind): void
    {
        $out = [];
        foreach (collect($links)->groupBy(fn ($l) => $this->label($l['item'])) as $group => $items) {
            $out[] = "===== {$group} ({$items->count()} links) =====";
            $picked = $items->count() <= $perKind ? $items : $items->shuffle(13145)->take($perKind);
            foreach ($picked->values() as $n => $l) {
                $m = $l['mark'];
                $out[] = sprintf('%d ج %d ص %d: …%s [[%s]] %s…', $n + 1, $l['page']->volume_number, $l['page']->page,
                    trim(preg_replace('/\s+/u', ' ', mb_substr($m['before'], -70))), $m['text'], trim(preg_replace('/\s+/u', ' ', mb_substr($m['after'], 0, 50))));
            }
        }
        file_put_contents($this->option('sample'), implode("\n", $out) . "\n");
        $this->info('Sample written to ' . $this->option('sample'));
    }
}
