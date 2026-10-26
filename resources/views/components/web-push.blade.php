@props(['context' => 'settings'])
{{-- Browser notifications (backlog item 25): on the settings page a switch for this browser; elsewhere a soft invite
     that never asks the browser by itself and does not return once closed. Nothing renders without VAPID keys. --}}
@if(config('webpush.vapid.public_key'))
<div x-data="webPush(@js($context))" x-cloak>
    @if($context === 'settings')
        <div class="bg-surface rounded-sm border border-line p-4 text-sm space-y-2">
            <template x-if="needsHomeScreen">
                <p class="text-muted">در آیفون و آیپد، اعلان مرورگر فقط وقتی کار می‌کند که فهارس را با «Share ← Add to Home Screen» به صفحه‌ی اصلی بیفزایید و از همان‌جا باز کنید.</p>
            </template>
            <template x-if="!needsHomeScreen && !supported">
                <p class="text-muted">این مرورگر اعلان مرورگر را پشتیبانی نمی‌کند.</p>
            </template>
            <template x-if="supported && !needsHomeScreen">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-ink" x-text="subscribed ? 'اعلان مرورگر در این مرورگر فعال است. ستون «مرورگر» در جدول زیر تعیین می‌کند کدام اعلان‌ها بیاید.' : (permission === 'denied' ? 'این مرورگر اجازه‌ی اعلان را برای فهارس بسته است؛ از تنظیمات سایت در مرورگر بازش کنید.' : 'اعلان مرورگر هنوز در این مرورگر فعال نیست. تیک ستون «مرورگر» فقط پس از زدن این دکمه اثر دارد.')"></p>
                    <button type="button" x-show="subscribed" @click="test()" :disabled="busy"
                        class="px-4 py-2 rounded-sm text-sm font-bold border border-line hover:bg-paper transition">اعلان آزمایشی</button>
                    <button type="button" x-show="permission !== 'denied' || subscribed" @click="subscribed ? disable() : enable()" :disabled="busy"
                        class="px-4 py-2 rounded-sm text-sm font-bold border border-line hover:bg-paper transition" x-text="subscribed ? 'خاموش‌کردن در این مرورگر' : 'فعال‌سازی در این مرورگر'"></button>
                </div>
            </template>
            <p x-show="message" class="text-xs text-muted" x-text="message"></p>
        </div>
    @else
        <div x-show="invite || message" class="flex items-start gap-3 p-3 rounded-sm border border-line bg-paper text-sm">
            <p class="flex-1 text-ink" x-text="message || @js($context === 'suggest' ? 'می‌خواهید وقتی پیشنهادتان داوری شد، در همین مرورگر خبردار شوید؟' : 'اعلان مرورگر: پیشنهادهای تازه را بی‌آنکه پنل باز باشد، در همین مرورگر ببینید.')"></p>
            <button type="button" x-show="invite" @click="enable()" :disabled="busy" class="px-3 py-1.5 bg-cinnabar text-on-cinnabar rounded-sm text-xs font-bold hover:opacity-90">بله، خبرم کنید</button>
            <button type="button" x-show="invite" @click="dismiss()" class="text-muted hover:text-ink text-lg leading-none" aria-label="بستن">&times;</button>
        </div>
    @endif
</div>
@endif
