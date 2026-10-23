{{-- The printed text at the corrected place (step 13.6): for each entry an address or a shelfmark moves, the text it has
     beside the one the source's pages show there, and a box to take that one with the approval. --}}
@php
    use App\Support\Fa;
    $candidates = \App\Sources\TextRefresh::candidates($suggestion, $suggestion->applied_value ?? $suggestion->suggested_value);
    $chosen = old('retext');
    $show = fn (?array $t) => trim(implode("\n\n", array_filter([$t['entry_text'] ?? null, $t['copy_text'] ?? null])));
@endphp
@if($candidates)
    <div class="space-y-3 p-4 rounded-xl bg-stone-50 dark:bg-stone-900 border border-line" data-retext>
        <div class="font-bold text-ink">متن مأخذ در جای تازه</div>
        <p class="text-stone-400">پیش‌نمایش بر پایه‌ی مقدار پیشنهادی است. اگر مقدار نهایی را تغییر دهید، متن هنگام تأیید از همان جای تازه برداشته می‌شود.</p>
        @foreach($candidates as $c)
            @php
                $entry = $c['entry'];
                $current = $show(['entry_text' => $entry->entry_text, 'copy_text' => $entry->copy_text]);
            @endphp
            <div class="space-y-2 pt-3 border-t border-line first:border-t-0 first:pt-0">
                <div class="font-bold text-ink">{{ $entry->cardLabel($entry->citable) }}</div>
                <div class="grid gap-3 lg:grid-cols-2">
                    <div>
                        <div class="text-stone-500 mb-1">متن کنونی{{ $entry->text_page ? Fa::digits(' (از ص ' . $entry->text_page . ')') : '' }}</div>
                        <div class="p-3 rounded-lg border border-line bg-paper text-ink whitespace-pre-line leading-7 max-h-72 overflow-y-auto">{{ Fa::digits($current) ?: '—' }}</div>
                    </div>
                    <div>
                        @if($c['text'])
                            <div class="text-stone-500 mb-1">متن در جای تازه{{ Fa::digits(' (ص ' . $c['text']['page_start'] . ($c['text']['entry_number'] ? '، مدخل ' . $c['text']['entry_number'] : '') . ')') }}</div>
                            <div class="p-3 rounded-lg border border-emerald-600/40 bg-emerald-50/50 dark:bg-emerald-950/20 text-ink whitespace-pre-line leading-7 max-h-72 overflow-y-auto">{{ Fa::digits($show($c['text'])) }}</div>
                        @else
                            <div class="p-3 rounded-lg border border-amber-600/40 bg-amber-50/50 dark:bg-amber-950/20 text-amber-800 dark:text-amber-300 leading-7">
                                این نسخه در صفحه‌های مأخذ در جای تازه پیدا نشد. متن کنونی با نشان «از نشانی پیشین» می‌ماند و پس از تأیید می‌توانید آن را
                                از <a href="{{ route('admin.source-entries.text', $entry) }}" target="_blank" class="font-bold text-cinnabar hover:underline">صفحه‌ی متن این مدخل</a> اصلاح کنید.
                            </div>
                        @endif
                    </div>
                </div>
                @if($c['text'])
                    <label class="flex items-center gap-2 font-bold text-ink">
                        <input type="checkbox" name="retext[]" value="{{ $entry->id }}" @checked($chosen === null || in_array((string) $entry->id, (array) $chosen, true)) class="text-cinnabar">
                        متن را هم به متن جای تازه تغییر بده
                    </label>
                @endif
            </div>
        @endforeach
    </div>
@endif
