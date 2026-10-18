{{-- Eye button for a password input; the wrapper needs x-data="{ show: false }" and the input :type="show ? 'text' : 'password'". --}}
<button type="button" @click="show = !show" :aria-pressed="show.toString()" :title="show ? 'پنهان کردن گذرواژه' : 'نمایش گذرواژه'" aria-label="نمایش گذرواژه"
        class="absolute left-2 top-1/2 -translate-y-1/2 p-1.5 rounded-lg text-muted hover:text-cinnabar focus:text-cinnabar transition">
    <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2.06 12.35a1 1 0 0 1 0-.7C3.42 8.1 7.36 5 12 5s8.58 3.1 9.94 6.65a1 1 0 0 1 0 .7C20.58 15.9 16.64 19 12 19s-8.58-3.1-9.94-6.65Z"/>
        <circle cx="12" cy="12" r="3"/>
    </svg>
    <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10.73 5.08A10.4 10.4 0 0 1 12 5c4.64 0 8.58 3.1 9.94 6.65a1 1 0 0 1 0 .7 10.8 10.8 0 0 1-1.44 2.49M14.08 14.16a3 3 0 0 1-4.24-4.24M17.48 17.5A10.7 10.7 0 0 1 12 19c-4.64 0-8.58-3.1-9.94-6.65a1 1 0 0 1 0-.7 10.8 10.8 0 0 1 4.45-5.14M2 2l20 20"/>
    </svg>
</button>
