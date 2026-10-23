@props(['entry'])

{{-- A text taken at another address than the entry now has (step 13.2): it stays, marked, until it is taken again at
     the new place or written by hand (step 13.6); a reviewer gets the way there. --}}
@if($entry?->textIsOutOfPlace())
    <p class="mb-2 text-xs text-amber-700 dark:text-amber-300" data-stale-text>
        این متن از نشانی پیشین ({{ \App\Support\Fa::digits("ج {$entry->text_volume_number}، ص {$entry->text_page}") }}) برداشته شده و هنوز با نشانی کنونی سنجیده نشده است.
        @if(auth()->user()?->canAccessAdmin() && ! request()->routeIs('admin.source-entries.*'))
            <a href="{{ route('admin.source-entries.text', $entry) }}" class="font-bold text-cinnabar hover:underline">برداشتن دوباره‌ی متن</a>
        @endif
    </p>
@endif
