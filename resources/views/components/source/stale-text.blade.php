@props(['entry'])

{{-- A text taken at another address than the entry now has (step 13.2): it stays, marked, until it is taken again --}}
@if($entry?->textIsOutOfPlace())
    <p class="mb-2 text-xs text-amber-700 dark:text-amber-300" data-stale-text>
        این متن از نشانی پیشین ({{ \App\Support\Fa::digits("ج {$entry->text_volume_number}، ص {$entry->text_page}") }}) برداشته شده و هنوز با نشانی کنونی سنجیده نشده است.
    </p>
@endif
