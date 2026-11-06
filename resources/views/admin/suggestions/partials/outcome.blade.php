{{-- What the review decided, and taking the decision back --}}
            @if($suggestion->standing)
                <p>
                    <b class="text-ink">وضعیت قول:</b> {{ $suggestion->standing_label }}
                    @if($suggestion->supersededBy)
                        — جای خود را به <a href="{{ route('admin.suggestions.show', $suggestion->supersededBy) }}" class="text-cinnabar hover:underline">#{{ $fa($suggestion->superseded_by) }}</a> داد.
                    @endif
                    @if($suggestion->applied_value !== null)
                        <span class="block mt-1">مقدار ثبت‌شده: <b class="text-ink">{{ $suggestion->display_value }}</b></span>
                    @endif
                </p>
            @endif
            <x-ui.meta-list>
                <x-ui.meta label="یادداشت عمومی"><span class="whitespace-pre-line">{{ $suggestion->public_note ?: '—' }}</span></x-ui.meta>
                <x-ui.meta label="یادداشت داخلی"><span class="whitespace-pre-line">{{ $suggestion->admin_notes ?: 'هیچ یادداشتی ثبت نشده است.' }}</span></x-ui.meta>
            </x-ui.meta-list>
            @php $res = $suggestion->resolution ?? []; @endphp
            @if($suggestion->status === 'approved' && $res)
                <p>
                    @if(isset($res['kept_id']))
                        ادغام شد: رکورد <span class="tabular-nums">#{{ $fa($res['removed_id']) }}</span> در <span class="tabular-nums">#{{ $fa($res['kept_id']) }}</span>.
                    @elseif($suggestion->kind === 'relation' && isset($res['work_id']))
                        نسخه به اثر <a href="{{ route('works.show', $res['work_id']) }}" class="text-cinnabar hover:underline tabular-nums">#{{ $fa($res['work_id']) }}</a> منتقل شد.
                    @elseif(empty($res['applied']))
                        به‌صورت دستی رسیدگی شد (اعمال خودکار نداشت).
                    @else
                        در شناسنامه اعمال شد.
                        @if(($res['person_action'] ?? null) === 'link') پیوند به شخص <a href="{{ route('people.show', $res['person_id']) }}" class="text-cinnabar hover:underline tabular-nums">#{{ $fa($res['person_id']) }}</a>.
                        @elseif(($res['person_action'] ?? null) === 'create') شخص تازه ساخته شد: <a href="{{ route('people.show', $res['person_id']) }}" class="text-cinnabar hover:underline tabular-nums">#{{ $fa($res['person_id']) }}</a>.
                        @elseif(($res['person_action'] ?? null) === 'text') فقط متن نام ذخیره شد.
                        @endif
                        @if(array_key_exists('hijri_year', $res)) سال هجری: {{ $fa($res['hijri_year'] ?? '—') }}. @endif
                    @endif
                </p>
            @endif
            <p class="text-xs text-muted">داوری به دست {{ $suggestion->reviewer?->name ?: 'سامانه' }}، @jdate($suggestion->reviewed_at, 'datetime')</p>
            @php $reopenPermission = $suggestion->status === 'approved' ? (\App\Auth\Permissions::FOR_APPLY[$suggestion->relationDefinition()['apply'] ?? ''] ?? null) : null; @endphp
            @if(in_array($suggestion->status, ['approved', 'rejected'], true) && (! $reopenPermission || auth()->user()->can($reopenPermission)))
                <form action="{{ route('admin.suggestions.reopen', $suggestion) }}" method="POST" class="pt-3 border-t border-line flex flex-wrap items-center gap-3"
                      onsubmit="return confirm(@js($suggestion->status === 'rejected' ? 'این پیشنهاد به صف داوری بازگردد؟' : 'تغییری که این تأیید در رکورد داده بازگردانده شود و پیشنهاد دوباره داوری شود؟'))">
                    @csrf
                    <x-ui.btn type="submit">{{ $wasPromoted ? 'بازگرداندن به «قول دیگر»' : ($suggestion->status === 'rejected' ? 'لغو رد و بازگشت به صف داوری' : 'لغو تأیید و بازگشت به صف داوری') }}</x-ui.btn>
                    <span class="text-xs text-muted">
                        @if($suggestion->status === 'approved')
                            اگر داوری به اشتباه بوده، تغییر رکورد هم برمی‌گردد. پیام قبلی به پیشنهاددهنده پس گرفته نمی‌شود.
                        @else
                            اگر رد به اشتباه بوده، پیشنهاد دوباره در انتظار داوری قرار می‌گیرد.@if(in_array($suggestion->kind, ['note', 'feedback'], true)) پرونده‌های پیوست هنگام رد حذف شده‌اند.@endif
                        @endif
                    </span>
                </form>
            @endif
