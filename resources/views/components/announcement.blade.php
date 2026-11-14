{{-- The site's public notice, once per visitor (App\Models\Announcement, resources/js/announcement.js).
     Its text is drawn by the script after the page has loaded, so it is not part of any page's own text.
     ?announcement_preview=<id> shows one notice to its manager, whatever its state, and counts nothing. --}}
@php
    $preview = request()->filled('announcement_preview') && auth()->user()?->can('announcements.manage')
        ? \App\Models\Announcement::find((int) request('announcement_preview'))
        : null;
    $quiet = request()->routeIs('login', 'login.*', 'register', 'register.*', 'password.*', 'verification.*', 'account.complete');
    $item = $preview?->payload() ?? ($quiet ? null : \App\Models\Announcement::forViewer(auth()->user()));
    $config = $item ? [
        'item' => \Illuminate\Support\Arr::only($item, ['id', 'title', 'body', 'url', 'label', 'external', 'image', 'style']),
        'member' => auth()->check(),
        'preview' => (bool) $preview,
        'url' => route('announcements.event', $item['id']),
        'csrf' => csrf_token(),
    ] : null;
@endphp
@if($config)
<div x-data="announcement(@js($config))" @keydown.escape.window="close()" data-announcement>
    <template x-if="item">
        <div>
            {{-- The window's backdrop; the card has none and leaves the page free --}}
            <div x-show="open && item.style === 'modal'" x-cloak x-transition.opacity.duration.300ms @click="close()"
                 class="fixed inset-0 z-40 bg-black/50 backdrop-blur-[2px]"></div>

            <div class="fixed z-40"
                 :class="item.style === 'modal'
                    ? 'inset-0 flex items-center justify-center p-4 pointer-events-none'
                    : 'start-3 end-3 bottom-3 sm:end-auto sm:start-4 sm:bottom-4 sm:w-[22rem]'">
            <section x-show="open" x-cloak
                     x-transition:enter="transition ease-out duration-500 motion-reduce:duration-0"
                     x-transition:enter-start="opacity-0 translate-y-4"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-200 motion-reduce:duration-0"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 translate-y-4"
                     :role="item.style === 'modal' ? 'dialog' : 'complementary'" :aria-modal="item.style === 'modal' ? 'true' : null"
                     aria-labelledby="announcement-title"
                     :class="item.style === 'modal' ? 'w-full max-w-md max-h-full overflow-y-auto' : ''"
                     class="pointer-events-auto text-right bg-surface border border-line-strong rounded-lg shadow-2xl shadow-black/20 overflow-hidden">
                {{-- A paper letterhead closed by one gold line: what the notice is, and the way out --}}
                <div class="flex items-center justify-between gap-3 bg-paper border-b border-gold" :class="item.style === 'modal' ? 'px-6 py-2' : 'px-4 py-1.5'">
                    <span class="flex items-center gap-2 text-xs font-bold text-ink/70">
                        <span class="size-1.5 rotate-45 bg-cinnabar" aria-hidden="true"></span>
                        تازه‌های فهارس
                    </span>
                    <button type="button" x-ref="close" @click="close()" aria-label="بستن اعلان"
                            class="shrink-0 -me-1.5 p-1.5 rounded-full text-muted hover:text-cinnabar hover:bg-surface transition cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <template x-if="item.image">
                    <img :src="item.image" alt="" class="block w-full object-cover" :class="item.style === 'modal' ? 'max-h-56' : 'max-h-36'">
                </template>

                <div :class="item.style === 'modal' ? 'p-6 pt-4 space-y-3' : 'p-4 pt-3 space-y-2'">
                    <h2 id="announcement-title" class="font-bold text-ink leading-8" :class="item.style === 'modal' ? 'text-xl' : 'text-base'" x-text="item.title"></h2>

                    <p x-show="item.body" x-text="item.body" class="whitespace-pre-line text-ink/85 leading-7" :class="item.style === 'modal' ? 'text-sm' : 'text-[13px]'"></p>

                    <div class="flex items-center gap-4 pt-1" x-show="item.url || item.style === 'modal'">
                        <template x-if="item.url">
                            <a :href="item.url" :target="item.external ? '_blank' : null" :rel="item.external ? 'noopener' : null" @click="follow()"
                               class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-sm bg-cinnabar text-on-cinnabar text-xs font-bold hover:opacity-90 transition">
                                <span x-text="item.label"></span>
                                <span aria-hidden="true">←</span>
                            </a>
                        </template>
                        <button type="button" x-show="item.style === 'modal'" @click="close()" class="text-xs font-semibold text-muted hover:text-cinnabar cursor-pointer">بستن</button>
                    </div>
                </div>
            </section>
            </div>
        </div>
    </template>
</div>
@endif
