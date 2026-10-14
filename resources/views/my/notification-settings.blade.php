@extends('layouts.app')

@section('title', 'تنظیمات اعلان | فهارس')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-4">
    <div>
        <h1 class="text-lg sm:text-xl font-bold text-stone-900 dark:text-stone-100">تنظیمات اعلان</h1>
        <p class="mt-1 text-xs text-stone-500 dark:text-stone-400">برای هر نوع اعلان، راه دریافت آن را انتخاب کنید. ایمیل‌ها به {{ auth()->user()->email }} فرستاده می‌شوند.</p>
    </div>

    @if(session('success'))
        <div class="p-3 rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900 text-emerald-800 dark:text-emerald-200 text-xs font-bold">{{ session('success') }}</div>
    @endif

    <form action="{{ route('notifications.settings.save') }}" method="POST" class="bg-white dark:bg-[#15192C] rounded-3xl border border-[#EADFCF] dark:border-[#272F4C] shadow-sm overflow-hidden">
        @csrf
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-stone-50 dark:bg-stone-800/50 text-xs text-stone-500 dark:text-stone-400">
                    <tr>
                        <th class="p-4 text-right font-bold">نوع اعلان</th>
                        @foreach($channels as $key => $label)
                            <th class="p-4 text-center font-bold whitespace-nowrap">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
                    @foreach($types as $type => $definition)
                        <tr>
                            <td class="p-4 text-stone-800 dark:text-stone-200">{{ $definition['label'] }}</td>
                            @foreach($channels as $key => $label)
                                <td class="p-4 text-center">
                                    @if(array_key_exists($key, $definition['channels']))
                                        <input type="checkbox" name="enabled[{{ $type }}][{{ $key }}]" value="1" @checked($enabled[$type][$key]) aria-label="{{ $definition['label'] }}: {{ $label }}"
                                            class="w-4 h-4 rounded border-stone-300 dark:border-stone-600 text-[#B38A50] focus:ring-[#B38A50]">
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
        <div class="p-4 border-t border-stone-100 dark:border-stone-800 flex justify-end">
            <button type="submit" class="px-5 py-2.5 bg-[#B38A50] hover:bg-[#9C753F] text-white rounded-xl text-sm font-bold shadow-md transition">ذخیره</button>
        </div>
    </form>
</div>
@endsection
