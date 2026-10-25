@extends('layouts.app')

@use('App\Support\Fa')
@php
    $answerLabels = $queue->answers();
    $previous = $opinion?->status === 'pending' ? $opinion : null;
    // «page:ج ۲، ص ۴۵», «some:12,15»: the answer's button is the part before the colon
    $previousAnswer = $previous ? strtok((string) $previous->review_answer, ':') : null;
    $detailed = $queue->detailed();
    $hidden = fn () => collect($filters)->map(fn ($v, $k) => '<input type="hidden" name="filters[' . e($k) . ']" value="' . e($v) . '">')->implode('');
@endphp

@section('title', $queue->title() . ' | ' . config('contributions.review_title') . ' | فهارس')
@section('meta_description', $queue->blurb())

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 py-6 sm:py-8 space-y-4">
    @include('contribute.frame')

    @if(! $card)
        <x-ui.card class="space-y-3 text-center">
            <p class="text-sm text-ink m-0">داوری این مورد پایان یافته است یا دیگر در صف نیست.</p>
            <x-ui.btn :href="route('contribute.next', [$queue->key()] + $filters)" variant="primary">مورد بعدی</x-ui.btn>
        </x-ui.card>
    @else
        {{-- The card's own inputs (records or copies to mark) join the answer form through form="review-answer" --}}
        <x-ui.card framed class="space-y-5 sm:p-7" data-review-card="{{ $queue->key() }}:{{ $itemId }}"
                   x-data="{ answer: {{ \Illuminate\Support\Js::from(old('answer', $previousAnswer)) }}, notes: {{ \Illuminate\Support\Js::from((bool) old('note', $previous?->rationale_citation)) }} }">
            @include('contribute.cards.' . $queue->key(), $card + ['previous' => $previous])

            @auth
                <form id="review-answer" method="POST" action="{{ route('contribute.answer', [$queue->key(), $itemId]) }}" class="space-y-3 pt-4 border-t border-line">
                    @csrf
                    {!! $hidden() !!}

                    @if($previous)
                        <p class="m-0 text-xs text-muted">پاسخ پیشین شما: <b class="text-ink">{{ $queue->describe($previous) }}</b>. اگر نظرتان عوض شده، پاسخ تازه را بزنید.</p>
                    @endif
                    @if($errors->any())
                        <p class="m-0 text-xs font-bold text-cinnabar">{{ $errors->first() }}</p>
                    @endif

                    <div>
                        <button type="button" @click="notes = ! notes" class="text-xs text-cinnabar hover:underline cursor-pointer" :aria-expanded="notes">
                            <span x-text="notes ? 'بستن توضیح' : 'افزودن مستند یا توضیح (اختیاری)'">افزودن مستند یا توضیح (اختیاری)</span>
                        </button>
                        <textarea name="note" x-show="notes" x-cloak rows="3" maxlength="2000" placeholder="مثلاً: شماره‌ی نسخه در سطر دوم برگه آمده، ولی عنوان دیگری است."
                                  class="mt-2 w-full p-2.5 rounded-sm border border-line-strong bg-paper text-ink text-sm leading-7">{{ old('note', $previous?->rationale_citation) }}</textarea>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach(array_diff_key($answerLabels, array_flip($card['hide'] ?? [])) as $value => $label)
                            @if(in_array($value, $detailed, true))
                                {{-- An answer with a second step: what to mark or write shows below --}}
                                <button type="button" @click="answer = '{{ $value }}'" :class="answer === '{{ $value }}' ? 'border-cinnabar text-cinnabar' : ''"
                                        @class(['px-4 py-3 rounded-sm border border-line-strong bg-surface text-ink text-sm font-semibold hover:border-cinnabar hover:text-cinnabar transition cursor-pointer text-center',
                                            'ring-2 ring-offset-2 ring-gold ring-offset-surface' => $previousAnswer === $value])>{{ $label }}</button>
                            @else
                                <button type="submit" name="answer" value="{{ $value }}"
                                        @class(['px-4 py-3 rounded-sm border text-sm font-semibold transition cursor-pointer text-center',
                                            'bg-cinnabar border-cinnabar text-on-cinnabar hover:opacity-90' => $loop->first,
                                            'border-line-strong bg-surface text-ink hover:border-cinnabar hover:text-cinnabar' => ! $loop->first,
                                            'ring-2 ring-offset-2 ring-gold ring-offset-surface' => $previousAnswer === $value])>{{ $label }}</button>
                            @endif
                        @endforeach
                        <button type="submit" formaction="{{ route('contribute.skip', [$queue->key(), $itemId]) }}" formnovalidate
                                class="px-4 py-3 rounded-sm border border-transparent text-muted text-sm hover:text-cinnabar transition cursor-pointer text-center">نمی‌دانم؛ مورد بعدی</button>
                    </div>

                    @foreach($detailed as $value)
                        <div x-show="answer === '{{ $value }}'" x-cloak class="flex flex-wrap items-end gap-2 p-3 bg-paper border border-line rounded-sm">
                            @include('contribute.details.' . $queue->key() . '-' . $value, $card + ['previous' => $previousAnswer === $value ? $previous : null])
                        </div>
                    @endforeach

                    <label class="flex items-start gap-2 text-xs text-muted cursor-pointer">
                        <input type="hidden" name="credit" value="0">
                        <input type="checkbox" name="credit" value="1" @checked(old('credit', $credit)) class="mt-0.5 rounded border-line-strong text-cinnabar focus:ring-cinnabar">
                        <span>اگر داوران پاسخم را پذیرفتند، نامم («{{ $creditName }}») در «{{ config('contributions.title') }}» درج شود.</span>
                    </label>
                </form>
            @else
                <div class="pt-4 border-t border-line space-y-3 text-center">
                    <p class="m-0 text-sm text-ink leading-7">برای پاسخ دادن وارد شوید یا در یک دقیقه ثبت‌نام کنید. پاسخ شما فقط به داوران می‌رسد و اگر پذیرفته شد، نامتان کنارش درج می‌شود.</p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <x-ui.btn :href="route('contribute.sign-in', [$queue->key(), $itemId])" variant="primary">ورود و پاسخ</x-ui.btn>
                        <x-ui.btn :href="route('register')">ثبت‌نام</x-ui.btn>
                        <form method="POST" action="{{ route('contribute.skip', [$queue->key(), $itemId]) }}">@csrf {!! $hidden() !!}<x-ui.btn type="submit" variant="ghost">مورد بعدی</x-ui.btn></form>
                    </div>
                </div>
            @endauth
        </x-ui.card>

        <p class="text-[11px] text-muted leading-6 m-0">
            پاسخ شما چیزی را در داده‌ها تغییر نمی‌دهد: کنار پاسخ دیگران به داوران نشان داده می‌شود و تصمیم با آنان است.
            نظر پژوهشگران تأییدشده وزن بیشتری دارد. هر وقت خواستید می‌توانید پاسخ خود را به همین مورد عوض کنید.
        </p>
    @endif
</div>
@endsection
