@extends('layouts.app')

@section('title', 'تنظیمات اعلان | فهارس')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-4">
    <div>
        <h1 class="font-naskh text-2xl sm:text-3xl font-bold text-ink leading-relaxed">تنظیمات اعلان</h1>
        <p class="mt-1 text-xs text-muted">برای هر نوع اعلان، راه دریافت آن را انتخاب کنید. ایمیل‌ها به {{ auth()->user()->email }} فرستاده می‌شوند.</p>
    </div>

    <x-web-push context="settings" />

    <form action="{{ route('notifications.settings.save') }}" method="POST" class="bg-surface rounded-sm border border-line shadow-sm overflow-hidden">
        @csrf
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-paper text-xs text-muted">
                    <tr>
                        <th class="p-4 text-right font-bold">نوع اعلان</th>
                        @foreach($channels as $key => $label)
                            <th class="p-4 text-center font-bold whitespace-nowrap">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach($types as $type => $definition)
                        <tr>
                            <td class="p-4 text-ink">{{ $definition['label'] }}</td>
                            @foreach($channels as $key => $label)
                                <td class="p-4 text-center">
                                    @if(array_key_exists($key, $definition['channels']))
                                        <input type="checkbox" name="enabled[{{ $type }}][{{ $key }}]" value="1" @checked($enabled[$type][$key]) aria-label="{{ $definition['label'] }}: {{ $label }}"
                                            class="w-4 h-4 rounded border-stone-300 dark:border-stone-600 text-cinnabar focus:ring-cinnabar">
                                    @else
                                        <span class="text-stone-300 dark:text-stone-600">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-line flex justify-end">
            <button type="submit" class="px-5 py-2.5 bg-cinnabar text-on-cinnabar hover:opacity-90 rounded-sm text-sm font-bold transition">ذخیره</button>
        </div>
    </form>
</div>
@endsection
