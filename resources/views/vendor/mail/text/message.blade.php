{{-- The plain-text twin of html/message: the Persian title above, the footer and the site's address below --}}
@php($site = \App\Models\SiteSetting::class)
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            فهارس — {{ $site::get('mail.subtitle') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            {{ $site::get('mail.footer') }} — {{ config('app.url') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
