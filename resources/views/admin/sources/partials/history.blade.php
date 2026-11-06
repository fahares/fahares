{{-- The changes made to a source or a volume from the panel, newest first; a source merge with its undo --}}
@php
    $fa = fn ($n) => \App\Support\Fa::num($n);
    $fields = ['title' => 'عنوان', 'short_name' => 'نام کوتاه', 'kind' => 'نوع', 'rank' => 'رتبه', 'library_id' => 'کتابخانه',
        'page_first' => 'صفحه‌ی آغاز', 'page_last' => 'صفحه‌ی پایان', 'sort' => 'ترتیب', 'catalogers' => 'فهرست‌نگاران', 'people' => 'فهرست‌نگاران جلد'];
    $effectLabels = ['changed' => 'نسخه‌ی تغییرکرده', 'moved' => 'به بخش دیگر', 'joined' => 'بخش‌دار شد', 'left' => 'بی‌بخش شد', 'cataloger' => 'فهرست‌نگار تازه'];
@endphp
<x-ui.section title="سابقه‌ی تغییرها" :count="$history->count()">
    @if($history->isEmpty())
        <p class="text-xs text-muted">هنوز از پنل تغییری داده نشده است.</p>
    @else
        <ul class="bg-surface border border-line divide-y divide-line text-xs">
            @foreach($history as $log)
                @php
                    $old = (array) ($log->old_values ?? []);
                    $new = (array) ($log->new_values ?? []);
                    $changed = array_keys(array_intersect_key($fields, $old + $new));
                    $isMerge = $log->action === 'merge' && ($old['kind'] ?? null) === 'source_merge';
                    $undone = in_array($log->action, ['merge', 'update'], true) && $log->isRolledBack();
                @endphp
                <li class="px-4 py-2.5 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <x-ui.chip :tone="str_starts_with($log->action, 'rollback') || $log->action === 'remove' ? 'accent' : 'default'">{{ $log->actionLabel() }}</x-ui.chip>
                    <span class="text-ink">{{ $log->typeLabel() }}</span>
                    @if($isMerge)
                        <span>«{{ $old['folded_title'] ?? '' }}» (<span dir="ltr">{{ $old['folded_code'] ?? '' }}</span>) در این منبع ادغام شد:
                            {{ $fa($new['entries'] ?? 0) }} مدخل، {{ $fa($new['copies'] ?? 0) }} نسخه، {{ $fa($new['volumes'] ?? 0) }} جلد</span>
                    @elseif($changed)
                        <span class="text-muted">{{ collect($changed)->map(fn ($k) => $fields[$k])->implode('، ') }}</span>
                    @endif
                    @if(! empty($new['effect']['changed']))
                        <span class="text-muted">· {{ collect($effectLabels)->filter(fn ($l, $k) => ! empty($new['effect'][$k]))->map(fn ($l, $k) => $fa($new['effect'][$k]) . ' ' . $l)->implode('، ') }}</span>
                    @endif
                    @if($undone)<x-ui.chip>برگردانده شد</x-ui.chip>@endif
                    <span class="ms-auto text-muted whitespace-nowrap">{{ $log->user?->name ?: 'سامانه' }} · @jdate($log->created_at, 'datetime')</span>
                    @if($isMerge && ! $undone)
                        @can('sources.merge')
                            <form action="{{ route('admin.sources.merge.undo', $log) }}" method="POST"
                                  onsubmit="return confirm('این ادغام برگردانده شود؟ منبع ادغام‌شده با جلدها و مدخل‌هایش دوباره جدا می‌شود.')">
                                @csrf
                                <x-ui.btn type="submit" size="sm" variant="ghost">برگرداندن ادغام</x-ui.btn>
                            </form>
                        @endcan
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.section>
