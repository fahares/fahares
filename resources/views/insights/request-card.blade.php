{{-- "Which statistics would help you?": an open form at the end of the insights page, sent as site feedback
     in the "stats" category (see FeedbackController). It also adds a small "suggest about this chart" link to every
     section of the page, which fills in the chart's name and brings the reader here. --}}
@php
    $config = ['url' => route('feedback.store'), 'csrf' => csrf_token()];
    $inputClass = 'w-full p-2.5 rounded-sm border border-line-strong bg-paper text-ink focus:border-gold';
    $examples = ['سهم نسخه‌های مذهّب در هر سده', 'کاتبان پرکار هر شهر', 'شمار نسخه‌های هر کتابخانه به تفکیک سده'];
@endphp
<div x-data="{
        config: @js($config), message: '', question: '', example: '', section: '', guestName: '', guestEmail: '', notifyOn: false,
        website: '', submitting: false, errors: {}, done: null,
        init() {
            // A link beside the heading of every other section on the page, including ones added later
            this.$root.parentElement.querySelectorAll('section[id]').forEach((s) => {
                const head = s.querySelector('h2');
                if (this.$root.contains(s) || ! head) return;
                const name = head.querySelector('span')?.textContent.trim() ?? '';
                const link = document.createElement('button');
                link.type = 'button';
                link.className = 'text-xs text-muted hover:text-cinnabar underline decoration-dotted underline-offset-4 cursor-pointer';
                link.textContent = 'پیشنهاد درباره‌ی این نمودار';
                link.addEventListener('click', () => this.ask(name));
                head.parentElement.appendChild(link);
            });
        },
        ask(name) {
            this.section = name;
            this.done = null;
            this.$root.querySelector('section').scrollIntoView({ behavior: 'smooth', block: 'start' });
            this.$nextTick(() => this.$refs.message.focus({ preventScroll: true }));
        },
        async submit() {
            this.errors = {};
            if (! this.message.trim()) { this.errors = { message: 'درخواست خود را بنویسید.' }; return; }
            this.submitting = true;
            const body = new FormData();
            body.append('category', 'stats');
            body.append('message', this.message);
            body.append('question', this.question);
            body.append('example', this.example);
            body.append('section', this.section);
            body.append('page_url', window.location.pathname + window.location.search);
            body.append('page_title', document.title);
            body.append('guest_name', this.guestName);
            body.append('guest_email', this.guestEmail);
            if (this.notifyOn) body.append('notify_submitter', '1');
            body.append('website', this.website);
            try {
                const res = await fetch(this.config.url, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf }, body });
                const data = await res.json().catch(() => ({}));
                if (res.ok) {
                    this.done = 'درخواست شما ثبت شد و داوران فهارس آن را می‌خوانند. سپاس از همراهی‌تان.';
                    this.message = this.question = this.example = this.section = '';
                    this.$nextTick(() => this.$root.querySelector('section').scrollIntoView({ behavior: 'smooth', block: 'start' }));
                } else if (res.status === 422) {
                    this.errors = Object.fromEntries(Object.entries(data.errors ?? {}).map(([k, v]) => [k, v[0]]));
                } else if (res.status === 429) {
                    this.errors = { form: 'تعداد درخواست‌های ارسالی زیاد است. لطفاً کمی بعد دوباره تلاش کنید.' };
                } else {
                    this.errors = { form: 'ارسال با خطا روبه‌رو شد. لطفاً به info@fahares.net بنویسید.' };
                }
            } catch (e) {
                this.errors = { form: 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.' };
            }
            this.submitting = false;
        },
    }">
<x-ui.section title="چه آماری برایتان سودمند است؟" id="request-stats">
    <x-ui.card framed>
        <div x-show="done" x-cloak class="space-y-3 text-sm">
            <p class="p-4 rounded-sm bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 font-bold leading-relaxed" x-text="done"></p>
            <button type="button" @click="done = null" class="text-cinnabar font-bold hover:underline cursor-pointer">درخواست دیگری دارم</button>
        </div>

        <form x-show="! done" @submit.prevent="submit()" class="space-y-4 text-sm">
            <p class="text-muted leading-relaxed">
                آماری که در این صفحه نیست و به کار پژوهش شما می‌آید را بنویسید. درخواست‌ها را می‌خوانیم و هر کدام را که داده‌های فهارس پاسخ دهد، به این صفحه می‌افزاییم.
            </p>

            <p x-show="section" x-cloak class="flex flex-wrap items-center gap-2 text-xs">
                <span class="text-muted">درباره‌ی نمودار:</span>
                <span class="px-2.5 py-0.5 rounded-full border border-gold text-ink" x-text="section"></span>
                <button type="button" @click="section = ''" class="text-muted hover:text-cinnabar cursor-pointer" aria-label="برداشتن نام نمودار">✕</button>
            </p>

            <div class="space-y-1.5">
                <label for="stats-message" class="block font-semibold text-ink">چه آمار یا نموداری می‌خواهید؟</label>
                <textarea id="stats-message" x-ref="message" x-model="message" rows="3" maxlength="5000" class="{{ $inputClass }}"
                          placeholder="مثلاً: سهم نسخه‌های مذهّب در هر سده"></textarea>
                <p class="flex flex-wrap items-center gap-1.5 text-xs">
                    <span class="text-muted">نمونه‌ها:</span>
                    @foreach($examples as $sample)
                        <button type="button" @click="message = @js($sample)" class="px-2.5 py-0.5 rounded-full border border-line bg-surface text-ink hover:border-cinnabar transition cursor-pointer">{{ $sample }}</button>
                    @endforeach
                </p>
                <p x-show="errors.message" x-text="errors.message" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="space-y-1">
                    <label for="stats-question" class="block font-semibold text-ink">برای پاسخ به چه پرسشی؟ <span class="font-normal text-muted">(اختیاری)</span></label>
                    <textarea id="stats-question" x-model="question" rows="2" maxlength="1000" class="{{ $inputClass }}"
                              placeholder="مثلاً: آیا تذهیب در دوره‌ی صفوی رایج‌تر شد؟"></textarea>
                </div>
                <div class="space-y-1">
                    <label for="stats-example" class="block font-semibold text-ink">نمونه یا توضیح <span class="font-normal text-muted">(اختیاری)</span></label>
                    <textarea id="stats-example" x-model="example" rows="2" maxlength="2000" class="{{ $inputClass }}"
                              placeholder="نموداری که در جای دیگری دیده‌اید، یا شیوه‌ی شمارشی که در نظر دارید"></textarea>
                </div>
            </div>

            @guest
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label for="stats-name" class="block font-semibold text-ink">نام شما <span class="font-normal text-muted">(اختیاری)</span></label>
                        <input id="stats-name" type="text" x-model="guestName" maxlength="100" class="{{ $inputClass }}">
                    </div>
                    <div class="space-y-1">
                        <label for="stats-email" class="block font-semibold text-ink">ایمیل <span class="font-normal text-muted">(اختیاری)</span></label>
                        <input id="stats-email" type="email" x-model="guestEmail" maxlength="100" dir="ltr" class="{{ $inputClass }}">
                        <p x-show="errors.guest_email" x-text="errors.guest_email" class="text-xs text-red-600 dark:text-red-400 font-bold"></p>
                    </div>
                </div>
                <label x-show="guestEmail.trim()" x-cloak class="flex items-start gap-2 cursor-pointer text-ink">
                    <input type="checkbox" x-model="notifyOn" class="mt-1 rounded border-stone-300 text-cinnabar">
                    <span>پاسخ را به این ایمیل بفرستید.</span>
                </label>
            @endguest
            <div class="hidden" aria-hidden="true"><label>وب‌سایت <input type="text" x-model="website" tabindex="-1" autocomplete="off"></label></div>

            <p x-show="errors.form || errors.category || errors.website" x-text="errors.form || errors.category || errors.website" class="p-2.5 rounded-sm bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 font-bold"></p>
            <div class="flex justify-end">
                <button type="submit" :disabled="submitting" class="px-5 py-2.5 bg-cinnabar hover:opacity-90 disabled:opacity-60 text-on-cinnabar rounded-sm font-bold transition cursor-pointer">
                    <span x-text="submitting ? 'در حال ارسال…' : 'فرستادن درخواست'">فرستادن درخواست</span>
                </button>
            </div>
        </form>
    </x-ui.card>
</x-ui.section>
</div>
