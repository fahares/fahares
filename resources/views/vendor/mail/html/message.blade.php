{{-- The site's logo and Persian title above every email, and a Persian footer; the texts are set in the admin's «قالب ایمیل‌ها» --}}
@php($site = \App\Models\SiteSetting::class)
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<tr>
<td class="header">
{{-- The site's own mark: the shamseh beside «فهارس», with the subtitle under the name --}}
<a href="{{ config('app.url') }}" style="display: inline-block; text-decoration: none;">
<table align="center" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
<tr>
@if($site::get('mail.show_logo') === '1')
<td style="padding-left: 12px; vertical-align: middle;"><img src="{{ asset('images/brand/shamseh-180.png') }}" width="52" height="52" alt="" style="display: block; width: 52px; height: 52px; border: 0;"></td>
@endif
<td style="vertical-align: middle; text-align: right;">
<span class="header-name">فهارس</span>
@if(filled($site::get('mail.subtitle')))
<span class="header-subtitle">{{ $site::get('mail.subtitle') }}</span>
@endif
</td>
</tr>
</table>
</a>
</td>
</tr>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ $site::get('mail.footer') }}<br>
[{{ parse_url(config('app.url'), PHP_URL_HOST) }}]({{ config('app.url') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
