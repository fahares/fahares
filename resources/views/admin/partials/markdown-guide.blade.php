{{-- A short Markdown guide beside the post and page editors; «images» adds the line for a picture --}}
<x-ui.card class="space-y-2">
    <h2 class="rubric text-base">راهنمای کوتاه Markdown</h2>
    <dl class="space-y-1.5 [&_code]:text-xs [&_code]:text-ink [&_code]:bg-paper [&_code]:px-1 [&_code]:rounded-sm [&_dt]:inline [&_dd]:inline">
        <div><dt><code>## عنوان</code></dt> <dd>— تیتر بخش (<code>###</code> برای زیرتیتر)</dd></div>
        <div><dt><code>**پررنگ**</code></dt> <dd>— پررنگ؛ <code>*کج*</code> برای کج</dd></div>
        <div><dt><code>- مورد</code></dt> <dd>— فهرست؛ <code>1. مورد</code> برای فهرست شماره‌دار</dd></div>
        <div><dt><code>&gt; متن</code></dt> <dd>— نقل‌قول</dd></div>
        <div><dt><code>[متن](/works/123)</code></dt> <dd>— پیوند</dd></div>
        @if($images ?? false)
            <div><dt><code>![زیرنویس](نشانی)</code></dt> <dd>— تصویر، با زیرنویس زیر آن</dd></div>
        @endif
        <div><dt><code>---</code></dt> <dd>— خط جداکننده</dd></div>
    </dl>
    <p class="text-xs text-muted leading-6">برای آغاز بند تازه، یک سطر خالی بگذارید.{{ $note ?? '' }}</p>
</x-ui.card>
