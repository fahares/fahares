{{--
    The figures of a chart as folded tables, each with a CSV download (InsightsTables, backlog item 152). $tables;
    $csvSuffix names the scope in the file name. A cell keeps its plain value in data-v, which the CSV reads, so the
    file has Latin digits a spreadsheet can count with.
--}}
@php
    use App\Support\Fa;

    $show = fn ($value, string $type) => match (true) {
        $value === null || $value === '' => '',
        $type === 'num' => Fa::num($value),
        $type === 'year' => Fa::digits($value),
        $type === 'dec' => Fa::digits(str_replace('.', '٫', (string) $value)),
        $type === 'pct' => Fa::digits(str_replace('.', '٫', (string) $value)) . '٪',
        default => $value,
    };
@endphp
@foreach($tables as $table)
    <details class="mt-4 text-xs group" data-insights-table="{{ $table['file'] }}">
        <summary class="cursor-pointer select-none text-cinnabar font-semibold hover:underline">
            جدول داده‌ها{{ count($tables) > 1 ? ': ' . $table['title'] : '' }}
            <span class="text-muted font-normal tabular-nums">({{ Fa::num(count($table['rows'])) }} ردیف)</span>
        </summary>
        <div class="mt-2 max-h-96 overflow-auto border border-line bg-surface">
            <table class="w-full text-xs tabular-nums">
                <caption class="sr-only">{{ $table['title'] }}</caption>
                <thead class="sticky top-0 bg-paper text-muted">
                    <tr>
                        @foreach($table['columns'] as [$label, $type])
                            <th scope="col" class="px-2.5 py-1.5 font-semibold whitespace-nowrap {{ $type === 'text' ? 'text-right' : 'text-left' }}">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($table['rows'] as $row)
                        <tr class="border-t border-line">
                            @foreach($row as $i => $value)
                                @php $type = $table['columns'][$i][1]; @endphp
                                <td data-v="{{ $value }}" class="px-2.5 py-1 {{ $type === 'text' ? 'text-right text-ink' : 'text-left text-ink whitespace-nowrap' }}">{{ $show($value, $type) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <button type="button" onclick="insightsCsv(this)" data-file="fahares-{{ $table['file'] }}{{ $csvSuffix ?? '' }}.csv"
                class="mt-2 px-2.5 py-1 border border-line-strong bg-surface text-ink hover:border-cinnabar hover:text-cinnabar transition cursor-pointer">دریافت CSV</button>
    </details>
@endforeach
@once
    <script>
        // The table of the pressed button as a CSV file: a byte order mark first, so Excel reads the Persian text
        function insightsCsv(button) {
            const quote = (text) => /[",\n]/.test(text) ? '"' + text.replace(/"/g, '""') : text;
            const cell = (el) => quote((el.dataset.v !== undefined ? el.dataset.v : el.textContent).trim());
            const lines = [...button.closest('details').querySelectorAll('tr')].map((row) => [...row.children].map(cell).join(','));
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
            link.download = button.dataset.file;
            link.click();
            setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        }
    </script>
@endonce
