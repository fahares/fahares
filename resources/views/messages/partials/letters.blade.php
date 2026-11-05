{{-- The letters of a thread (backlog item 91). $staffView shows the staff member's own name; the user sees only the site's. --}}
@php $staffView = $staffView ?? false; @endphp
<ol class="space-y-3">
    @foreach($thread->messages as $m)
        <li @class(['p-3 sm:p-4 rounded-sm border leading-7', 'bg-paper border-line' => $m->is_staff, 'bg-surface border-cinnabar/30 ms-4 sm:ms-10' => ! $m->is_staff])>
            <div class="flex flex-wrap items-baseline justify-between gap-2 text-[11px] text-muted mb-1">
                <span class="font-bold text-ink">
                    @if($m->is_staff)
                        {{ $staffView ? ($m->author?->name ?? 'کاربر حذف‌شده') . ' (' . $thread->staffLabel() . ')' : $thread->staffLabel() }}
                    @else
                        {{ $staffView ? $thread->user?->name : 'شما' }}
                    @endif
                </span>
                <span>
                    @if($m->via === 'email')<span>از راه ایمیل ·</span>@endif
                    @jdate($m->created_at, 'long_time')
                </span>
            </div>
            @if($m->body !== '')<div class="whitespace-pre-line break-words text-ink">{{ $m->body }}</div>@endif
            @if($m->attachments->isNotEmpty())
                {{-- Pictures attached to an email reply (step 14.36) --}}
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach($m->attachments as $a)
                        <a href="{{ route('messages.attachment', $a) }}" target="_blank" rel="noopener" class="block border border-line rounded-sm bg-paper hover:border-cinnabar" title="{{ $a->filename }}">
                            <img src="{{ route('messages.attachment', $a) }}" alt="{{ $a->filename }}" loading="lazy" class="h-32 w-auto max-w-[14rem] object-contain">
                        </a>
                    @endforeach
                </div>
            @endif
        </li>
    @endforeach
</ol>
