@props(['type'])
{{-- The record's empty sections, gathered in one box after its content so that an invitation does not read like a heading of
     the record (step 14.132). The sections push their rows with <x-suggest.contribute-item>; place this after all of them. --}}
@php $items = trim($__env->yieldPushContent('contribute')); @endphp
@if($items !== '')
<section id="contribute" x-data class="scroll-mt-24 border border-dashed border-line-strong bg-surface/60 px-4 sm:px-5 pt-4 pb-1">
    <h2 class="text-sm font-bold text-muted">شما هم می‌توانید بیفزایید</h2>
    <p class="mt-1 text-xs text-muted leading-relaxed max-w-[72ch]">
        این بخش‌ها برای این {{ \App\Suggestions\SuggestionRegistry::typeLabel($type) }} هنوز خالی‌اند. آنچه بیفزایید پس از بررسی، با نام شما (اگر بخواهید) در این صفحه منتشر می‌شود.
    </p>
    <ul class="mt-1 divide-y divide-line">
        {!! $items !!}
    </ul>
</section>
@endif
