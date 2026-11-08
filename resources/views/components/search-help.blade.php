@props(['popover' => true])

{{-- What the search box understands, which no one finds by chance (backlog item 83, phase 8); on the search page a
     popover, on the home page a panel that opens under the examples --}}
<details {{ $attributes->class(['text-ink/80', 'relative' => $popover]) }}>
    <summary class="cursor-pointer list-none text-cinnabar hover:underline">راهنمای جست‌وجو</summary>
    <div @class([
        'bg-surface border border-line text-right leading-6',
        'absolute z-30 mt-2 w-[min(22rem,85vw)] end-0 shadow-xl p-4 space-y-2' => $popover,
        'mt-3 p-4 sm:p-5 grid gap-x-8 gap-y-2 sm:grid-cols-2 text-xs sm:text-sm' => ! $popover,
    ])>
        <p><b>عبارت دقیق</b> در گیومه: <code dir="rtl">"شرح لمعه"</code> یا <code dir="rtl">«شرح لمعه»</code></p>
        <p><b>بی این واژه</b> با خط تیره پیش از آن: <code dir="rtl">شرح -حاشیه</code></p>
        <p><b>این یا آن</b> با خط عمودی: <code dir="rtl">کافی | شافی</code></p>
        <p><b>با هم:</b> <code dir="rtl">"شرح لمعه" -حاشیه</code></p>
        <p><b>شماره‌ی نسخه:</b> <code dir="rtl">مجلس ۱۱۸۹</code></p>
        <p><b>صفحه‌ی فهرست:</b> <code dir="rtl">مرعشی ج ۳۳ ص ۲۹۴</code>، <code dir="rtl">ذریعه ج ۳ ص ۴۰۲</code></p>
        <p><b>فقط یک فیلد</b> (کاتب، پدیدآور، آغاز و انجام…): از «جست‌وجوی عبارت در»@if(! $popover) در صفحه‌ی نتایج@endif.</p>
        <p><b>چند فیلد با هم</b> (کاتب «محمد» و محل کتابت «اصفهان»): از «جست‌وجو در چند فیلد با هم»@if(! $popover) در همان صفحه@endif.</p>
        <p @class(['text-muted', 'sm:col-span-2' => ! $popover])>ارقام فارسی و لاتین، «ي/ی» و «ك/ک»، و «ال» یکسان شمرده می‌شوند.</p>
    </div>
</details>
