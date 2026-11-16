@extends('layouts.admin')
@use('App\Support\Fa')
@use('App\Models\PersonPeriod')

@section('title', 'پیشنهادهای دورۀ زندگی')
@section('page_title', 'پیشنهادهای دورۀ زندگی: خوانده از زندگی‌نامه‌های منابع')

@section('content')
<div class="space-y-5 max-w-5xl text-[13px]">
    <p class="text-muted leading-7 max-w-3xl">
        این‌ها را هوشواره از متن زندگی‌نامه‌ها خوانده است و هیچ‌کدام در سایت دیده نمی‌شود. نوع دوره از یک واژه حدس زده شده و سال‌ها همان‌هایی است که در همان جمله آمده، پس هر مورد را با متن بسنجید.
        برای تأیید، صفحۀ شخص را باز کنید: آن‌جا مکان و سال‌ها را درست می‌کنید و ذخیره می‌کنید. رد کردن از همین‌جا هم می‌شود.
    </p>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('admin.people.periods.proposals') }}" class="px-2.5 py-1 border {{ request('kind') ? 'border-line text-muted' : 'border-cinnabar text-cinnabar font-bold' }}">همه <span class="tabular-nums">{{ Fa::num($counts->sum()) }}</span></a>
        @foreach(PersonPeriod::KINDS as $kind => $label)
            <a href="{{ route('admin.people.periods.proposals', ['kind' => $kind]) }}" class="px-2.5 py-1 border {{ request('kind') === $kind ? 'border-cinnabar text-cinnabar font-bold' : 'border-line text-muted' }}">{{ $label }} <span class="tabular-nums">{{ Fa::num($counts[$kind] ?? 0) }}</span></a>
        @endforeach
    </div>
    @if($proposals->isEmpty())
        <p class="text-muted">پیشنهادی در انتظار نیست.</p>
    @else
        <ul class="divide-y divide-line border-y border-line">
            @foreach($proposals as $proposal)
                <li class="py-2.5 flex flex-wrap items-start gap-x-4 gap-y-1">
                    <div class="min-w-0 flex-1 basis-96 space-y-0.5">
                        <p>
                            <a href="{{ route('people.show', $proposal->person_id) }}#periods" class="font-bold text-ink hover:text-cinnabar">{{ $proposal->person?->name ?? '—' }}</a>
                            <span class="text-cinnabar font-bold ms-2">{{ $proposal->kind_label }}</span>
                            <span class="font-bold">{{ $proposal->place_name }}</span>
                            @if($proposal->date_text)<span class="tabular-nums">{{ Fa::digits($proposal->date_text) }}</span>@endif
                        </p>
                        <p class="text-muted leading-6">«{{ $proposal->note }}»</p>
                        <p class="text-muted text-xs">{{ $proposal->source_text }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.people.periods.reject', [$proposal->person_id, $proposal->id]) }}" class="shrink-0">
                        @csrf
                        <x-ui.btn type="submit" size="sm" variant="ghost">نادرست است، رد شود</x-ui.btn>
                    </form>
                </li>
            @endforeach
        </ul>
        {{ $proposals->links() }}
    @endif
</div>
@endsection
