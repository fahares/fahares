{{-- "Report a problem or suggest" for the whole site; opened by dispatching open-feedback (footer, user menu). See FeedbackController --}}
@php
    $config = ['url' => route('feedback.store'), 'csrf' => csrf_token()];
    $inputClass = 'w-full p-2.5 rounded-xl border border-line-strong bg-paper text-ink focus:border-gold';
@endphp
<div x-data="feedbackForm(@js($config))" @open-feedback.window="start($event.detail ?? {})" @keydown.escape.window="open = false">
    {{-- Closed by a click on the backdrop only: the click that opens the form would count as "outside" --}}
    <div x-show="open" x-cloak x-transition.opacity @click.self="open = false" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
        <div class="bg-surface w-full max-w-lg rounded-3xl p-6 sm:p-8 border border-line-strong shadow-2xl space-y-5 text-right max-h-[90vh] overflow-y-auto text-xs">
            <div class="flex items-center justify-between pb-3 border-b border-line">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-5 bg-cinnabar rounded-sm"></span>
                    <h3 class="font-bold text-base text-ink">گزارش اشکال یا پیشنهاد</h3>
                </div>
                <button type="button" @click="open = false" class="text-stone-400 hover:text-stone-600 text-lg" aria-label="بستن">✕</button>
            </div>

            <template x-if="done">
                <div class="space-y-4">
                    <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold leading-relaxed" x-text="done"></div>
                    <div class="flex justify-end"><button type="button" @click="open = false" class="px-5 py-2.5 bg-cinnabar hover:opacity-90 text-on-cinnabar rounded-xl font-bold">بستن</button></div>
                </div>
            </template>

            <template x-if="!done">
                <form @submit.prevent="submit()" class="space-y-4">
                    <p class="text-stone-500 leading-relaxed">نشانی همین صفحه همراه گزارش فرستاده می‌شود. برای اصلاح اطلاعات یک نسخه، اثر، شخص یا کتابخانه، دکمه‌ی «پیشنهاد اصلاح» در صفحه‌ی همان رکورد دقیق‌تر است.</p>
                    <div class="space-y-1.5">
                        <span class="block font-semibold text-ink">موضوع:</span>
                        @foreach(\App\Suggestions\SuggestionRegistry::FEEDBACK_CATEGORIES as $key => $label)
                            <label class="flex items-center gap-2"><input type="radio" value="{{ $key }}" x-model="category" class="text-cinnabar"> {{ $label }}</label>
                        @endforeach
                    </div>
                    <div class="space-y-1">
                        <label class="block font-semibold text-ink">شرح:</label>
                        <textarea x-model="message" rows="5" required class="{{ $inputClass }}" placeholder="چه دیدید و انتظار چه چیزی را داشتید؟"></textarea>
                        <p x-show="errors.message" x-text="errors.message" class="text-red-600 dark:text-red-400 font-bold"></p>
                    </div>
                    @auth
                        <div class="space-y-1">
                            <label class="block font-semibold text-ink">تصویر صفحه (اختیاری؛ حداکثر ۲ تصویر، هر کدام ۲ مگابایت):</label>
                            <input type="file" accept="image/jpeg,image/png,image/webp" multiple @change="files = [...$event.target.files].slice(0, 2)" class="block w-full text-ink/80">
                            <p x-show="errors.files || errors['files.0'] || errors['files.1']" x-text="errors.files || errors['files.0'] || errors['files.1']" class="text-red-600 dark:text-red-400 font-bold"></p>
                        </div>
                    @else
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="space-y-1">
                                <label class="block font-semibold text-ink">نام شما (اختیاری):</label>
                                <input type="text" x-model="guestName" class="{{ $inputClass }}">
                            </div>
                            <div class="space-y-1">
                                <label class="block font-semibold text-ink">ایمیل (اختیاری):</label>
                                <input type="email" x-model="guestEmail" dir="ltr" class="{{ $inputClass }}">
                                <p x-show="errors.guest_email" x-text="errors.guest_email" class="text-red-600 dark:text-red-400 font-bold"></p>
                            </div>
                        </div>
                        <label x-show="guestEmail.trim()" x-cloak class="flex items-start gap-2 cursor-pointer text-ink">
                            <input type="checkbox" x-model="notifyOn" class="mt-0.5 rounded border-stone-300 text-cinnabar">
                            <span>پاسخ را به این ایمیل بفرستید.</span>
                        </label>
                    @endauth
                    <div class="hidden" aria-hidden="true"><label>وب‌سایت <input type="text" x-model="website" tabindex="-1" autocomplete="off"></label></div>
                    <p x-show="errors.form || errors.category || errors.website" x-text="errors.form || errors.category || errors.website" class="p-2.5 rounded-xl bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 font-bold"></p>
                    <div class="pt-3 flex items-center justify-end gap-3 border-t border-line">
                        <button type="button" @click="open = false" class="px-4 py-2 rounded-xl text-stone-500 hover:bg-paper font-semibold">انصراف</button>
                        <button type="submit" :disabled="submitting" class="px-5 py-2.5 bg-cinnabar hover:opacity-90 disabled:opacity-60 text-on-cinnabar rounded-xl font-bold shadow-md transition">
                            <span x-text="submitting ? 'در حال ارسال…' : 'ارسال'"></span>
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>
