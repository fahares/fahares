{{-- The printed-page viewer (backlog item 94); its logic is resources/js/catalog-viewer.js --}}
@php
    $kbd = 'px-1.5 py-0.5 bg-stone-100 dark:bg-stone-900 border border-line-strong rounded text-[10px] font-mono text-ink';
    $tool = 'inline-flex items-center justify-center gap-1 rounded-xl text-xs font-bold text-ink transition cursor-pointer hover:bg-stone-200/70 dark:hover:bg-stone-700 active:scale-95 disabled:opacity-30 disabled:cursor-not-allowed disabled:active:scale-100';
    $bar = 'absolute inset-x-0 z-10 bg-surface/95 backdrop-blur-sm transition duration-200';
@endphp
<div
    x-data="catalogViewer({ baseUrl: @js(asset('storage/catalogs')), rangeUrl: @js(url('/api/page-images')) })"
    x-show="open"
    x-cloak
    class="fixed inset-x-0 top-0 h-dvh z-50 overflow-hidden select-none bg-stone-200 dark:bg-paper"
    style="display: none;"
    role="dialog"
    aria-modal="true"
    :aria-label="'برگه‌ی ' + catalogName + '، ' + pageLabel">

    <!-- The stage: the image moves inside it; the bars float over it -->
    <main
        x-ref="stage"
        @pointerdown="onPointerDown($event)"
        @pointermove="onPointerMove($event)"
        @pointerup="onPointerUp($event)"
        @pointercancel="onPointerUp($event)"
        @wheel.prevent="onWheel($event)"
        :class="gesturing ? 'cursor-grabbing' : 'cursor-grab'"
        class="absolute inset-0 overflow-hidden touch-none">

        {{-- Class bindings rather than x-show inside the dialog: a nested x-show missed its update when the flag flipped back within one tick --}}
        <img
            :src="shownUrl"
            :style="`width: ${natW}px; height: ${natH}px; transform: ${transform}`"
            :class="[shownUrl && !hasError ? '' : 'hidden', animate && !gesturing ? 'transition-transform duration-200 ease-out' : '', loading ? 'opacity-60' : '']"
            class="absolute top-0 left-0 max-w-none origin-top-left will-change-transform bg-white shadow-2xl pointer-events-none select-none"
            alt="برگه‌ی مأخذ چاپی"
            draggable="false" />

        <!-- Loading: over the previous page, which stays on screen -->
        <div :class="loading ? 'flex' : 'hidden'" class="absolute inset-0 flex-col items-center justify-center gap-3 z-[5] pointer-events-none">
            <div class="w-10 h-10 border-3 border-amber-500/20 border-t-gold rounded-full animate-spin"></div>
            <span class="px-2 py-0.5 rounded bg-surface/80 text-xs text-stone-600 dark:text-amber-200/80 font-medium">در حال بارگذاری برگه…</span>
        </div>

        <div :class="hasError && !loading ? 'flex' : 'hidden'" class="absolute inset-0 hidden flex-col items-center justify-center gap-3 z-[5] p-6 text-center">
            <div class="w-14 h-14 rounded-2xl bg-amber-50 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-800/60 flex items-center justify-center text-2xl shadow-lg">📖</div>
            <div class="space-y-1">
                <h3 class="text-sm font-bold text-ink">تصویر این صفحه در دسترس نیست</h3>
                <p class="text-xs text-muted max-w-sm">
                    تصویر صفحه‌ی <span x-text="fa(page)" class="font-bold text-cinnabar"></span> از جلد <span x-text="fa(volume)" class="font-bold text-cinnabar"></span> <span x-text="catalogName" class="font-bold"></span> یافت نشد.
                </p>
            </div>
            <button type="button" @pointerdown.stop @click="retry()" class="px-4 py-1.5 rounded-xl bg-white dark:bg-stone-800 hover:bg-stone-100 dark:hover:bg-stone-700 text-xs font-semibold text-ink border border-stone-300 dark:border-stone-600 transition shadow-xs cursor-pointer">تلاش دوباره</button>
        </div>
    </main>

    <!-- Top bar: close, source, volume and page (opens jump-to-page); on wider screens also the page and zoom tools -->
    <header
        x-ref="header"
        :class="barsHidden ? '-translate-y-full opacity-0 pointer-events-none' : ''"
        class="{{ $bar }} top-0 border-b border-stone-200 dark:border-line shadow-sm pt-[env(safe-area-inset-top)] pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)]">
        <div class="flex items-center gap-2 px-2 sm:px-6 h-12 sm:h-13">
            <button type="button" x-ref="close" @click="closeViewer()" aria-label="بستن نمایشگر" title="بستن (Esc)"
                class="inline-flex items-center justify-center gap-1.5 shrink-0 w-11 h-11 sm:w-auto sm:h-auto sm:px-3.5 sm:py-1.5 rounded-2xl sm:bg-stone-100 sm:dark:bg-stone-800 sm:border border-line-strong hover:bg-red-50 dark:hover:bg-red-950/60 hover:border-red-300 text-xs font-semibold text-stone-700 hover:text-red-700 dark:text-stone-300 dark:hover:text-red-200 transition cursor-pointer">
                <svg class="w-5 h-5 sm:w-4 sm:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                <span class="hidden sm:inline">بستن</span>
                <kbd class="hidden [@media(hover:hover)]:md:inline-block px-1 bg-white dark:bg-stone-900 border border-line-strong rounded text-[9px] font-mono text-stone-400">Esc</kbd>
            </button>

            <div class="flex items-center gap-2 min-w-0">
                <span class="hidden sm:block w-2.5 h-6 bg-cinnabar rounded-xs shrink-0"></span>
                <h2 class="text-sm font-bold text-ink truncate"><span class="hidden sm:inline">برگه‌ی مأخذ چاپی </span><span x-text="catalogName"></span></h2>
            </div>

            <div class="relative shrink-0">
                <button type="button" @click="toggleJump()" :aria-expanded="jumpOpen" aria-label="پرش به صفحه" title="پرش به صفحه"
                    class="inline-flex items-center gap-1 px-3 min-h-9 rounded-full bg-amber-50 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-800/60 text-amber-900 dark:text-amber-300 text-xs font-bold whitespace-nowrap cursor-pointer hover:border-amber-500 transition">
                    <span x-text="pageLabel"></span>
                    <svg class="w-3 h-3 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" /></svg>
                </button>
                <form x-show="jumpOpen" @submit.prevent="submitJump()" @click.outside="jumpOpen = false"
                    class="absolute top-full mt-2 start-0 sm:start-auto sm:end-0 z-20 flex items-center gap-2 p-2 rounded-xl bg-surface border border-line-strong shadow-xl" style="display: none;">
                    <label class="text-xs text-muted whitespace-nowrap" for="catalog-viewer-jump">صفحه‌ی</label>
                    <input id="catalog-viewer-jump" x-ref="jumpInput" x-model="jumpValue" type="text" inputmode="numeric" autocomplete="off" dir="ltr"
                        :placeholder="last ? fa(first) + '–' + fa(last) : ''"
                        class="w-24 px-2 py-1.5 rounded-lg border border-line-strong bg-paper text-base sm:text-sm text-ink text-center focus:outline-none focus:border-cinnabar">
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-cinnabar text-white text-xs font-bold cursor-pointer">برو</button>
                </form>
            </div>

            <div class="flex-1"></div>

            <!-- Wider screens: page, zoom and display tools -->
            <div class="hidden sm:flex items-center gap-2">
                <div class="flex items-center bg-stone-100 dark:bg-stone-800 p-0.5 rounded-2xl border border-line-strong">
                    <button type="button" @click="prevPage()" :disabled="!canPrev" aria-label="صفحه‌ی قبلی" title="صفحه‌ی قبلی (PageUp)" class="{{ $tool }} px-3 py-1.5">
                        <span class="rtl:rotate-180" aria-hidden="true">←</span><span>صفحه‌ی قبلی</span>
                    </button>
                    <button type="button" @click="nextPage()" :disabled="!canNext" aria-label="صفحه‌ی بعدی" title="صفحه‌ی بعدی (PageDown)" class="{{ $tool }} px-3 py-1.5">
                        <span>صفحه‌ی بعدی</span><span class="rtl:rotate-180" aria-hidden="true">→</span>
                    </button>
                </div>

                <div class="flex items-center bg-stone-100 dark:bg-stone-800 p-0.5 rounded-2xl border border-line-strong">
                    <button type="button" @click="zoomOut()" :disabled="atMin" aria-label="کوچک‌نمایی" title="کوچک‌نمایی (-)" class="{{ $tool }} p-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4" /></svg>
                    </button>
                    <button type="button" @click="resetView()" aria-label="بازگشت به اندازه‌ی پایه" title="بازگشت به اندازه‌ی پایه (کلید 0)" class="{{ $tool }} px-2 py-1 min-w-14 text-amber-700 dark:text-amber-400">
                        <span x-text="zoomLabel"></span>
                    </button>
                    <button type="button" @click="zoomIn()" :disabled="atMax" aria-label="بزرگ‌نمایی" title="بزرگ‌نمایی (+)" class="{{ $tool }} p-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" /></svg>
                    </button>
                </div>

                <button type="button" @click="toggleMode()" :aria-label="mode === 'fit' ? 'نمایش هم‌عرض' : 'نمایش کامل صفحه'" :title="mode === 'fit' ? 'هم‌عرض کردن صفحه' : 'جا دادن کامل صفحه'"
                    class="{{ $tool }} px-3 py-1.5 bg-stone-100 dark:bg-stone-800 border border-line-strong rounded-2xl">
                    <span x-text="mode === 'fit' ? 'هم‌عرض' : 'جا شدن'"></span>
                </button>
            </div>

            <a :href="imageUrl" target="_blank" rel="noopener" aria-label="باز کردن تصویر در زبانه‌ی تازه" title="باز کردن تصویر در زبانه‌ی تازه"
                class="inline-flex items-center justify-center shrink-0 w-11 h-11 sm:w-9 sm:h-9 rounded-2xl text-stone-600 dark:text-stone-300 hover:bg-stone-200/70 dark:hover:bg-stone-700 transition">
                <svg class="w-5 h-5 sm:w-4 sm:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 4h6v6m0-6L10 14M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5" /></svg>
            </a>
        </div>
    </header>

    <!-- Bottom bar: on phones the large page buttons; with a mouse the keyboard guide -->
    <footer
        x-ref="footer"
        :class="barsHidden ? 'translate-y-full opacity-0 pointer-events-none' : ''"
        class="{{ $bar }} bottom-0 border-t border-stone-200 dark:border-line pb-[env(safe-area-inset-bottom)] pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)] sm:hidden [@media(hover:hover)]:sm:block">

        <div class="flex sm:hidden items-center gap-2 px-2 h-14">
            <button type="button" @click="prevPage()" :disabled="!canPrev" aria-label="صفحه‌ی قبلی" class="{{ $tool }} min-w-11 h-11 px-3 text-sm bg-stone-100 dark:bg-stone-800 border border-line-strong">
                <span class="rtl:rotate-180 text-base" aria-hidden="true">←</span><span>قبلی</span>
            </button>
            <div class="flex-1 text-center text-xs font-bold text-ink whitespace-nowrap" x-text="countLabel" aria-live="polite"></div>
            <button type="button" @click="toggleMode()" :aria-label="mode === 'fit' ? 'نمایش هم‌عرض' : 'نمایش کامل صفحه'" class="{{ $tool }} h-11 px-3 text-amber-800 dark:text-amber-300">
                <span x-text="mode === 'fit' ? 'هم‌عرض' : 'جا شدن'"></span>
            </button>
            <button type="button" @click="nextPage()" :disabled="!canNext" aria-label="صفحه‌ی بعدی" class="{{ $tool }} min-w-11 h-11 px-3 text-sm bg-stone-100 dark:bg-stone-800 border border-line-strong">
                <span>بعدی</span><span class="rtl:rotate-180 text-base" aria-hidden="true">→</span>
            </button>
        </div>

        <div class="hidden sm:flex items-center justify-between px-6 py-2 text-[11px] text-ink/80">
            <div class="flex items-center gap-4">
                <span class="text-muted font-medium">کلیدها:</span>
                <span class="flex items-center gap-1"><kbd class="{{ $kbd }}">↑</kbd><kbd class="{{ $kbd }}">↓</kbd><kbd class="{{ $kbd }}">←</kbd><kbd class="{{ $kbd }}">→</kbd><span>جابه‌جایی تصویر</span></span>
                <span class="flex items-center gap-1"><kbd class="{{ $kbd }}">PgUp</kbd><kbd class="{{ $kbd }}">PgDn</kbd><span>ورق زدن</span></span>
                <span class="hidden md:flex items-center gap-1"><kbd class="{{ $kbd }}">+</kbd><kbd class="{{ $kbd }}">-</kbd><kbd class="{{ $kbd }}">Ctrl+چرخ</kbd><span>بزرگ‌نمایی</span></span>
                <span class="hidden lg:flex items-center gap-1"><kbd class="{{ $kbd }}">دوبار کلیک</kbd><span>جا شدن / هم‌عرض / بزرگ</span></span>
            </div>
            <div class="flex items-center gap-3 text-[10px] text-muted">
                <span x-text="countLabel"></span>
                <span>فهارس • نسخه‌ی چاپی <span x-text="catalogName"></span></span>
            </div>
        </div>
    </footer>
</div>
