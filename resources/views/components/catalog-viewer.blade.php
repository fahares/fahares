<div 
    x-data="{
        open: false,
        catalog: 'fankha',
        catalogName: 'فنخا',
        volume: 1,
        page: 1,
        zoom: 1,
        panX: 0,
        panY: 0,
        isDragging: false,
        dragStartX: 0,
        dragStartY: 0,
        initialPanX: 0,
        initialPanY: 0,
        loading: true,
        hasError: false,
        baseUrl: '{{ asset('storage/catalogs') }}',

        get imageUrl() {
            const cat = this.catalog || 'fankha';
            const v = String(this.volume).padStart(2, '0');
            const p = String(this.page).padStart(4, '0');
            return `${this.baseUrl}/${cat}/v${v}/p${p}.webp`;
        },

        checkImageStatus() {
            this.$nextTick(() => {
                const img = this.$refs.pageImage;
                if (img && img.complete) {
                    if (img.naturalWidth > 0) {
                        this.loading = false;
                        this.hasError = false;
                    }
                }
            });
        },

        openViewer(cat, catName, vol, pg) {
            const nextCat = cat || 'fankha';
            const nextCatName = catName || 'فنخا';
            const nextVol = parseInt(vol) || 1;
            const nextPg = parseInt(pg) || 1;
            const isSamePage = (this.catalog === nextCat && this.volume === nextVol && this.page === nextPg);

            this.catalog = nextCat;
            this.catalogName = nextCatName;
            this.volume = nextVol;
            this.page = nextPg;
            this.resetView();
            this.open = true;
            this.hasError = false;
            document.body.classList.add('overflow-hidden');

            this.$nextTick(() => {
                const img = this.$refs.pageImage;
                if (isSamePage && img && img.complete && img.naturalWidth > 0) {
                    this.loading = false;
                } else {
                    this.loading = true;
                    this.checkImageStatus();
                }
            });
        },

        closeViewer() {
            this.open = false;
            this.resetView();
            document.body.classList.remove('overflow-hidden');
        },

        resetView() {
            this.zoom = 1;
            this.panX = 0;
            this.panY = 0;
            this.isDragging = false;
        },

        zoomIn() {
            if (this.zoom < 3) {
                this.zoom = Math.min(3, Math.round((this.zoom + 0.25) * 100) / 100);
            }
        },

        zoomOut() {
            if (this.zoom > 0.5) {
                this.zoom = Math.max(0.5, Math.round((this.zoom - 0.25) * 100) / 100);
            }
        },

        prevPage() {
            if (this.page > 1) {
                this.page--;
                this.loading = true;
                this.hasError = false;
                this.resetView();
                this.checkImageStatus();
            }
        },

        nextPage() {
            this.page++;
            this.loading = true;
            this.hasError = false;
            this.resetView();
            this.checkImageStatus();
        },

        startDrag(e) {
            if (e.button !== 0) return; // فقط کلیک چپ
            this.isDragging = true;
            this.dragStartX = e.clientX;
            this.dragStartY = e.clientY;
            this.initialPanX = this.panX;
            this.initialPanY = this.panY;
        },

        onDrag(e) {
            if (!this.isDragging) return;
            e.preventDefault();
            this.panX = this.initialPanX + (e.clientX - this.dragStartX);
            this.panY = this.initialPanY + (e.clientY - this.dragStartY);
        },

        endDrag() {
            this.isDragging = false;
        },

        handleWheel(e) {
            e.preventDefault();
            if (e.ctrlKey || e.metaKey) {
                if (e.deltaY < 0) {
                    this.zoomIn();
                } else {
                    this.zoomOut();
                }
            } else {
                // چرخاندن ماوس به نرمی تصویر را بالا و پایین می‌برد
                this.panY -= e.deltaY * 0.9;
            }
        },

        init() {
            window.addEventListener('open-catalog-viewer', (e) => {
                const detail = e.detail || {};
                this.openViewer(detail.catalog, detail.catalogName, detail.volume, detail.page);
            });

            // نام رویداد قبلی جهت سازگاری به عقب
            window.addEventListener('open-fankha-viewer', (e) => {
                const detail = e.detail || {};
                this.openViewer(detail.catalog || 'fankha', detail.catalogName || 'فنخا', detail.volume, detail.page);
            });

            window.addEventListener('mouseup', () => {
                if (this.isDragging) {
                    this.isDragging = false;
                }
            });

            window.addEventListener('keydown', (e) => {
                if (!this.open) return;

                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.closeViewer();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    this.panY += 75;
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.panY -= 75;
                } else if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    this.panX += 75;
                } else if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    this.panX -= 75;
                } else if (e.key === 'PageUp') {
                    e.preventDefault();
                    this.prevPage();
                } else if (e.key === 'PageDown') {
                    e.preventDefault();
                    this.nextPage();
                } else if (e.key === '+' || e.key === '=') {
                    e.preventDefault();
                    this.zoomIn();
                } else if (e.key === '-' || e.key === '_') {
                    e.preventDefault();
                    this.zoomOut();
                } else if (e.key === '0') {
                    e.preventDefault();
                    this.resetView();
                }
            });
        }
    }"
    x-show="open" 
    x-cloak
    class="fixed inset-0 z-50 overflow-hidden select-none"
    style="display: none;"
    role="dialog" 
    aria-modal="true">

    <!-- Backdrop with blur -->
    <div 
        x-show="open"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="closeViewer()"
        class="fixed inset-0 bg-stone-900/60 dark:bg-stone-950/90 backdrop-blur-md transition-opacity"></div>

    <!-- Viewer Window Frame -->
    <div 
        x-show="open"
        x-transition:enter="ease-out duration-250"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="relative w-full h-full flex flex-col justify-between">

        <!-- Top Header & Toolbar -->
        <header class="relative z-10 flex items-center justify-between px-4 sm:px-6 py-2.5 bg-white/95 dark:bg-[#15192C]/95 border-b border-stone-200 dark:border-[#272F4C] shadow-sm">
            
            <!-- Right: Title & Info Badge -->
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-6 bg-[#B38A50] rounded-xs shadow-xs"></span>
                    <h2 class="text-xs sm:text-sm font-bold text-stone-900 dark:text-stone-100 tracking-wide">
                        برگه مأخذ چاپی <span x-text="catalogName"></span>
                    </h2>
                </div>

                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-50 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-800/60 text-amber-900 dark:text-amber-300 text-xs font-bold font-mono">
                    <span>جلد <span x-text="volume"></span></span>
                    <span class="text-amber-500">•</span>
                    <span>صفحه <span x-text="page"></span></span>
                </div>
            </div>

            <!-- Center: Navigation & Zoom Tools -->
            <div class="flex items-center gap-2">
                <!-- Page Navigation Group -->
                <div class="flex items-center bg-stone-100 dark:bg-stone-800 p-0.5 rounded-2xl border border-stone-300 dark:border-stone-700 shadow-2xs">
                    <button 
                        type="button" 
                        @click="prevPage()" 
                        :disabled="page <= 1"
                        :class="page <= 1 ? 'opacity-30 cursor-not-allowed text-stone-400 dark:text-stone-500' : 'text-stone-800 dark:text-stone-100 hover:text-black dark:hover:text-white hover:bg-stone-200/70 dark:hover:bg-stone-700 active:scale-95 cursor-pointer'"
                        title="صفحه قبلی (PageUp)"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-xs font-bold transition">
                        <span class="rtl:rotate-180">←</span>
                        <span class="hidden sm:inline">صفحه قبلی</span>
                    </button>

                    <button 
                        type="button" 
                        @click="nextPage()" 
                        title="صفحه بعدی (PageDown)"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-xs font-bold text-stone-800 dark:text-stone-100 hover:text-black dark:hover:text-white hover:bg-stone-200/70 dark:hover:bg-stone-700 active:scale-95 transition cursor-pointer">
                        <span class="hidden sm:inline">صفحه بعدی</span>
                        <span class="rtl:rotate-180">→</span>
                    </button>
                </div>

                <!-- Zoom Controls Group -->
                <div class="flex items-center bg-stone-100 dark:bg-stone-800 p-0.5 rounded-2xl border border-stone-300 dark:border-stone-700 shadow-2xs">
                    <button 
                        type="button" 
                        @click="zoomOut()" 
                        :disabled="zoom <= 0.5"
                        :class="zoom <= 0.5 ? 'opacity-30 cursor-not-allowed text-stone-400 dark:text-stone-500' : 'text-stone-800 dark:text-stone-100 hover:text-black dark:hover:text-white hover:bg-stone-200/70 dark:hover:bg-stone-700 active:scale-95 cursor-pointer'"
                        title="کوچک‌نمایی (-)"
                        class="p-1.5 rounded-xl transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4" />
                        </svg>
                    </button>

                    <button 
                        type="button" 
                        @click="resetView()" 
                        title="بازنشانی اندازه به ۱۰۰٪ و مرکز (کلید 0)"
                        class="px-2.5 py-1 text-xs font-mono font-black text-amber-700 dark:text-amber-400 hover:bg-stone-200/70 dark:hover:bg-stone-700 rounded-xl transition cursor-pointer min-w-14 text-center">
                        <span x-text="Math.round(zoom * 100) + '%'"></span>
                    </button>

                    <button 
                        type="button" 
                        @click="zoomIn()" 
                        :disabled="zoom >= 3"
                        :class="zoom >= 3 ? 'opacity-30 cursor-not-allowed text-stone-400 dark:text-stone-500' : 'text-stone-800 dark:text-stone-100 hover:text-black dark:hover:text-white hover:bg-stone-200/70 dark:hover:bg-stone-700 active:scale-95 cursor-pointer'"
                        title="بزرگ‌نمایی (+)"
                        class="p-1.5 rounded-xl transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Left: Close Button -->
            <div class="flex items-center gap-2">
                <button 
                    type="button" 
                    @click="closeViewer()" 
                    title="بستن پنجره (Esc)"
                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl bg-stone-100 hover:bg-red-50 dark:bg-stone-800 dark:hover:bg-red-950/60 border border-stone-200 dark:border-stone-700 hover:border-red-300 dark:hover:border-red-800/80 text-xs font-semibold text-stone-700 hover:text-red-700 dark:text-stone-300 dark:hover:text-red-200 transition shadow-2xs cursor-pointer">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    <span class="hidden sm:inline">بستن</span>
                    <kbd class="hidden md:inline-block px-1 py-0.2 bg-white dark:bg-stone-900 border border-stone-200 dark:border-stone-700 rounded text-[9px] font-mono text-stone-400">Esc</kbd>
                </button>
            </div>

        </header>

        <!-- Canvas Area / Image Viewer with Full Smooth Drag & Wheel -->
        <main 
            @mousedown="startDrag($event)"
            @mousemove="onDrag($event)"
            @mouseup="endDrag()"
            @mouseleave="endDrag()"
            @wheel="handleWheel($event)"
            :class="isDragging ? 'cursor-grabbing select-none' : 'cursor-grab select-none'"
            class="relative flex-1 overflow-hidden flex items-center justify-center bg-stone-200/90 dark:bg-[#0c0f1d] transition-colors p-4 touch-none">

            <!-- Loading Spinner -->
            <div 
                x-show="loading" 
                class="absolute inset-0 flex flex-col items-center justify-center gap-3 z-20 pointer-events-none">
                <div class="w-10 h-10 border-3 border-amber-500/20 border-t-[#B38A50] rounded-full animate-spin"></div>
                <span class="text-xs text-stone-600 dark:text-amber-200/80 font-medium">در حال بارگذاری برگه چاپی...</span>
            </div>

            <!-- Error State -->
            <div 
                x-show="hasError && !loading" 
                class="absolute inset-0 flex flex-col items-center justify-center gap-3 z-20 p-6 text-center"
                style="display: none;">
                <div class="w-14 h-14 rounded-2xl bg-amber-50 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-800/60 flex items-center justify-center text-amber-600 dark:text-amber-400 text-2xl shadow-lg">
                    📖
                </div>
                <div class="space-y-1">
                    <h3 class="text-sm font-bold text-stone-900 dark:text-stone-100">تصویر این صفحه در دسترس نیست</h3>
                    <p class="text-xs text-stone-500 dark:text-stone-400 max-w-sm">
                        تصویر صفحه <span x-text="page" class="font-mono text-[#B38A50] font-bold"></span> از جلد <span x-text="volume" class="font-mono text-[#B38A50] font-bold"></span> <span x-text="catalogName" class="font-bold"></span> یافت نشد.
                    </p>
                </div>
                <button 
                    type="button" 
                    @click="loading = true; hasError = false; const img = $refs.pageImage; img.src = imageUrl + '?t=' + Date.now()" 
                    class="px-4 py-1.5 rounded-xl bg-white dark:bg-stone-800 hover:bg-stone-100 dark:hover:bg-stone-700 text-xs font-semibold text-stone-800 dark:text-stone-200 border border-stone-300 dark:border-stone-600 transition shadow-xs cursor-pointer">
                    تلاش مجدد
                </button>
            </div>

            <!-- Page Image Container (Smoothly Translated & Scaled) -->
            <div 
                class="relative will-change-transform shadow-2xl rounded-2xl border border-stone-300/80 dark:border-stone-800 bg-white"
                :class="isDragging ? 'transition-none' : 'transition-transform duration-75 ease-out'"
                :style="`transform: translate3d(${panX}px, ${panY}px, 0px) scale(${zoom}); transform-origin: center center;`">
                
                <img 
                    x-ref="pageImage"
                    :src="imageUrl" 
                    x-on:load="loading = false; hasError = false;"
                    x-on:error="loading = false; hasError = true;"
                    alt="برگه مأخذ چاپی"
                    class="max-h-[82vh] w-auto max-w-[90vw] object-contain rounded-2xl block pointer-events-none select-none"
                    draggable="false"
                />
            </div>

        </main>

        <!-- Bottom Status & Keyboard Navigation Guide -->
        <footer class="relative z-10 flex items-center justify-between px-4 sm:px-6 py-2 bg-white/95 dark:bg-[#15192C]/95 border-t border-stone-200 dark:border-[#272F4C] text-[11px] text-stone-600 dark:text-stone-400">
            <div class="flex items-center gap-4">
                <span class="hidden sm:inline text-stone-400 dark:text-stone-500 font-medium">
                    کلیدهای ناوبری:
                </span>
                <span class="flex items-center gap-1">
                    <kbd class="px-1.5 py-0.5 bg-stone-100 dark:bg-stone-900 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono text-stone-700 dark:text-stone-300">↑</kbd>
                    <kbd class="px-1.5 py-0.5 bg-stone-100 dark:bg-stone-900 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono text-stone-700 dark:text-stone-300">↓</kbd>
                    <kbd class="px-1.5 py-0.5 bg-stone-100 dark:bg-stone-900 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono text-stone-700 dark:text-stone-300">←</kbd>
                    <kbd class="px-1.5 py-0.5 bg-stone-100 dark:bg-stone-900 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono text-stone-700 dark:text-stone-300">→</kbd>
                    <span>جابه‌جایی تصویر</span>
                </span>
                <span class="flex items-center gap-1">
                    <kbd class="px-1.5 py-0.5 bg-stone-100 dark:bg-stone-900 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono text-stone-700 dark:text-stone-300">PgUp</kbd>
                    <kbd class="px-1.5 py-0.5 bg-stone-100 dark:bg-stone-900 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono text-stone-700 dark:text-stone-300">PgDn</kbd>
                    <span>ورق زدن</span>
                </span>
                <span class="hidden md:flex items-center gap-1">
                    <kbd class="px-1.5 py-0.5 bg-stone-100 dark:bg-stone-900 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono text-stone-700 dark:text-stone-300">+</kbd>
                    <kbd class="px-1.5 py-0.5 bg-stone-100 dark:bg-stone-900 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono text-stone-700 dark:text-stone-300">-</kbd>
                    <span>بزرگ‌نمایی</span>
                </span>
                <span class="hidden lg:flex items-center gap-1">
                    <kbd class="px-1.5 py-0.5 bg-stone-100 dark:bg-stone-900 border border-stone-300 dark:border-stone-700 rounded text-[10px] font-mono text-stone-700 dark:text-stone-300">ماوس / Drag</kbd>
                    <span>کشیدن آزاد</span>
                </span>
            </div>

            <div class="text-[10px] text-stone-400 dark:text-stone-500 font-mono">
                فهارس • نسخه چاپی <span x-text="catalogName"></span>
            </div>
        </footer>

    </div>

</div>
