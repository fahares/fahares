@extends('layouts.admin')

{{-- «صف‌های بررسی» (step 14.397): every queue the machine fills, with its waiting work --}}
@section('title', 'صف‌های بررسی')
@section('page_title', 'صف‌های بررسی')

@section('content')
<div class="space-y-6">
    <p class="text-[13px] text-muted leading-7">
        پیوندها، تطبیق‌ها و ناسازگاری‌هایی که ماشین پیدا کرده و داور باید ببیند. پیشنهادهای کاربران سایت در «پیشنهادهای اصلاح» است.
        شمار کم‌رنگ کاری است که انبوه پیشنهاد شده و می‌تواند بماند؛ در شمار منو نمی‌آید.
    </p>
    @forelse($groups as $title => $cards)
        <section>
            <h2 class="rubric text-lg mb-3">{{ $title }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($cards as $q)
                    <a href="{{ $q['url'] }}" data-queue="{{ $q['label'] }}" class="flex flex-col gap-1 bg-surface border border-line px-4 py-3 hover:border-cinnabar transition">
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="text-[13px] font-bold text-ink">{{ $q['label'] }}</span>
                            <span class="text-2xl font-extrabold tabular-nums leading-tight {{ ! $q['count'] ? 'text-muted font-semibold' : ($q['soft'] ? 'text-ink' : 'text-cinnabar') }}">{{ \App\Support\Fa::num($q['count']) }}</span>
                        </span>
                        <span class="text-xs text-muted leading-6">{{ $q['hint'] }}</span>
                        @if($q['oldest'])
                            <span class="text-xs text-muted">قدیمی‌ترین: {{ $q['oldest'] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <x-admin.notice>صفی برای دسترسی شما نیست.</x-admin.notice>
    @endforelse
</div>
@endsection
