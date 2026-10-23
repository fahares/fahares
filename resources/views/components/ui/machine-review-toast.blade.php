{{-- The toast of a review made from an ⓘ badge (backlog item 59): what was done, «بازگردانی» for a few seconds,
     or why it failed. Rendered once in the layout, for users who can access the admin panel. --}}
<div x-data x-show="$store.machineReview.toast" x-cloak x-transition.opacity role="status" aria-live="polite"
    class="fixed bottom-4 inset-x-4 sm:inset-x-auto sm:left-1/2 sm:-translate-x-1/2 z-50 sm:min-w-80 max-w-lg" data-machine-review-toast>
    <div class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-2xl text-sm border"
        :class="$store.machineReview.toast?.error ? 'bg-red-50 dark:bg-red-950 text-red-800 dark:text-red-200 border-red-300 dark:border-red-800' : 'bg-[#221C17] text-[#F3EAD6] border-gold/50'">
        <span class="flex-1 leading-6" x-text="$store.machineReview.toast?.message"></span>
        <button type="button" x-show="$store.machineReview.toast?.undo" @click="$store.machineReview.undo()" :disabled="$store.machineReview.busy"
            class="shrink-0 px-3 py-1 rounded-md font-bold disabled:opacity-50 cursor-pointer"
            :class="$store.machineReview.toast?.error ? 'hover:bg-red-100 dark:hover:bg-red-900' : 'text-gold hover:bg-white/10'">بازگردانی</button>
        <button type="button" @click="$store.machineReview.settle()" aria-label="بستن" class="shrink-0 p-1 opacity-70 hover:opacity-100 cursor-pointer">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
</div>
