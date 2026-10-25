@extends('layouts.app')

@section('title', $queue->title() . ' | ' . config('contributions.review_title') . ' | فهارس')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 py-6 sm:py-8 space-y-4">
    @include('contribute.frame')

    <x-ui.card framed class="space-y-3 text-center">
        <p class="font-naskh text-lg font-bold text-ink m-0">{{ $filters ? 'در این حوزه موردی برای شما نمانده است.' : 'در این صف موردی برای شما نمانده است.' }}</p>
        <p class="text-sm text-muted m-0 leading-7">سپاس از همراهی‌تان. پاسخ‌ها اکنون نزد داوران است.</p>
        <div class="flex flex-wrap justify-center gap-2">
            @if($filters)<x-ui.btn :href="route('contribute.next', $queue->key())" variant="primary">همه‌ی حوزه‌ها</x-ui.btn>@endif
            <x-ui.btn :href="route('contribute.index')">صف‌های دیگر</x-ui.btn>
        </div>
    </x-ui.card>
</div>
@endsection
