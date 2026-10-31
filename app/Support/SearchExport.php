<?php

namespace App\Support;

use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A list of search results as an Excel or CSV file (backlog item 83, phase 4): Persian headers, a right-to-left
 * sheet, and each record's address on the site, so a researcher can return to it and cite it.
 */
class SearchExport
{
    /**
     * Rows a guest and a signed-in user may take in one file. Built in the request: 5000 rows took 144 MB and
     * 3.3 s locally, beyond php-fpm's 128 MB; a larger file needs a queued job (backlog item 83, phase 4).
     */
    public const GUEST_LIMIT = 500;
    public const MEMBER_LIMIT = 2000;

    public static function columns(string $type): array
    {
        return match ($type) {
            'manuscripts' => [
                'شناسه' => fn (Manuscript $m) => $m->id,
                'عنوان اثر' => fn (Manuscript $m) => $m->work?->primary_title,
                'پدیدآور' => fn (Manuscript $m) => $m->work?->author?->name ?? $m->work?->author_name,
                'کتابخانه' => fn (Manuscript $m) => $m->libraryRecord?->display_name ?? $m->library,
                'شهر' => fn (Manuscript $m) => $m->city,
                'شماره‌ی نسخه' => fn (Manuscript $m) => $m->shelfmark,
                'کاتب' => fn (Manuscript $m) => $m->scribe_name ?: ($m->is_autograph ? 'مؤلف' : null),
                'تاریخ کتابت' => fn (Manuscript $m) => $m->copy_date_raw,
                'سال کتابت (ق)' => fn (Manuscript $m) => $m->copy_date_hijri_year,
                'محل کتابت' => fn (Manuscript $m) => $m->copy_place,
                'خط' => fn (Manuscript $m) => $m->script_names,
                'برگ' => fn (Manuscript $m) => $m->folios,
                'صفحه' => fn (Manuscript $m) => $m->pages,
                'برگ‌ها در مجموعه' => fn (Manuscript $m) => $m->range_from ? ($m->range_from === $m->range_to ? $m->range_from : $m->range_from . '-' . $m->range_to) : null,
                'سطر' => fn (Manuscript $m) => $m->lines,
                'اندازه' => fn (Manuscript $m) => $m->dimensions,
                'دستخط مؤلف' => fn (Manuscript $m) => $m->is_autograph ? 'بله' : null,
                'ارجاع فهرست' => fn (Manuscript $m) => $m->catalog_citation,
                'نشانی در فهارس' => fn (Manuscript $m) => url("/m/{$m->id}"),
            ],
            'sources' => [
                'شناسه' => fn (\App\Models\SourceItem $i) => $i->id,
                'منبع' => fn (\App\Models\SourceItem $i) => $i->source?->short_name ?: $i->source?->title,
                'جلد' => fn (\App\Models\SourceItem $i) => $i->volume_number,
                'صفحه' => fn (\App\Models\SourceItem $i) => $i->page_start,
                'شماره‌ی مدخل' => fn (\App\Models\SourceItem $i) => $i->entry_number,
                'عنوان' => fn (\App\Models\SourceItem $i) => $i->title,
                'متن' => fn (\App\Models\SourceItem $i) => $i->text,
                'نشانی در فهارس' => fn (\App\Models\SourceItem $i) => $i->url(),
            ],
            'people' => [
                'شناسه' => fn (Person $p) => $p->id,
                'نام' => fn (Person $p) => $p->name,
                'تولد (ق)' => fn (Person $p) => $p->birth_year_hijri,
                'وفات (ق)' => fn (Person $p) => $p->death_year_hijri,
                'سده' => fn (Person $p) => $p->century_hijri,
                'شمار آثار' => fn (Person $p) => $p->works_count,
                'نسخه‌های کتابت‌شده' => fn (Person $p) => $p->scribed_manuscripts_count ?? $p->manuscripts_count,
                'نشانی در فهارس' => fn (Person $p) => url("/p/{$p->id}"),
            ],
            default => [
                'شناسه' => fn (Work $w) => $w->id,
                'عنوان' => fn (Work $w) => $w->primary_title,
                'پدیدآور' => fn (Work $w) => $w->author?->name ?? $w->author_name,
                'وفات پدیدآور (ق)' => fn (Work $w) => $w->author?->death_year_hijri,
                'تاریخ تألیف (ق)' => fn (Work $w) => $w->composition_year_hijri,
                'موضوع' => fn (Work $w) => $w->subjects->pluck('name')->implode('، '),
                'زبان' => fn (Work $w) => $w->languages->pluck('name')->implode('، '),
                'شمار نسخه' => fn (Work $w) => $w->manuscripts_count,
                'نشانی در فهارس' => fn (Work $w) => url("/w/{$w->id}"),
            ],
        };
    }

    /**
     * The largest file, built in the queue and sent by a notification (App\Jobs\ExportSearchResults); kept on the
     * uploads volume for KEEP_DAYS days
     */
    public const QUEUED_LIMIT = 50000;
    public const KEEP_DAYS = 7;
    public const DIRECTORY = 'exports';

    public static function download(iterable $records, string $type, string $format, string $filename): StreamedResponse
    {
        return response()->streamDownload(fn () => self::write($records, $type, $format, 'php://output'),
            "{$filename}." . ($format === 'csv' ? 'csv' : 'xlsx'), ['Content-Type' => self::contentType($format)]);
    }

    public static function contentType(string $format): string
    {
        return $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    /** Writes the records, one row at a time, to a file or a stream */
    public static function write(iterable $records, string $type, string $format, string $target): void
    {
        $columns = self::columns($type);
        $csv = $format === 'csv';
        $writer = $csv ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($target);
        if (! $csv) {
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setRightToLeft(true));
        }
        $writer->addRow(Row::fromValues(array_keys($columns), (new Style)->setFontBold()));
        foreach ($records as $record) {
            $writer->addRow(Row::fromValues(array_map(fn ($value) => $value($record), array_values($columns))));
        }
        $writer->close();
    }
}
