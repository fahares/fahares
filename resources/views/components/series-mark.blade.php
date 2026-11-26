@props(['manuscript'])
{{-- The letter of a second number series of the copy's library, beside its shelfmark: «ع» for the National Library's
     Arabic series, which Fankha prints without it (App\Support\MelliSeries, step 14.416). It follows the shelfmark's
     own ltr span, so it reads after the number. --}}
@if($mark = $manuscript?->seriesMark())<span class="ms-1" data-series-mark title="{{ $mark['title'] }}">{{ $mark['mark'] }}</span>@endif
