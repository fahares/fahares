@extends('layouts.app')

@section('title', 'تکمیل حساب | فهارس')

@section('content')
{{-- Shown once after a first Google sign-in with a Latin name (backlog item 75); «بعداً» skips it --}}
<div class="min-h-[60vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-6 bg-surface p-8 border border-gold shadow-[inset_0_0_0_4px_var(--surface),inset_0_0_0_5px_var(--line)]">
        <div class="text-center space-y-2">
            <h1 class="font-naskh text-[26px] font-bold text-ink leading-relaxed">تکمیل حساب</h1>
            <p class="text-xs text-muted leading-6">حساب شما با گوگل ساخته شد. نام حساب گوگل شما با حروف لاتین است؛ اگر می‌خواهید، نام فارسی و شماره‌ی تماس خود را بنویسید.</p>
        </div>

        @php $field = 'w-full px-4 py-2.5 rounded-sm border border-line-strong bg-paper text-ink text-sm focus:border-cinnabar focus:ring-4 focus:ring-cinnabar/15 transition outline-none'; @endphp
        <form action="{{ route('account.complete.save') }}" method="POST" class="space-y-4">
            @csrf
            @include('auth.partials.name-field', ['value' => $user->name, 'autofocus' => true, 'inputClass' => $field])
            @include('auth.partials.phone-field', ['value' => $user->phone, 'inputClass' => $field])
            <div class="flex items-center justify-between gap-3 pt-2">
                <button type="submit" name="skip" value="1" formnovalidate class="text-xs text-muted hover:text-ink">بعداً</button>
                <x-ui.btn type="submit" variant="primary">ذخیره</x-ui.btn>
            </div>
        </form>
    </div>
</div>
@endsection
