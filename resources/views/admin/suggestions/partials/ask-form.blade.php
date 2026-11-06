{{-- A question to the submitter (before the decision) or a letter (after it); backlog item 91, sent once (step 14.71) --}}
@php $pending ??= $suggestion->status === 'pending'; @endphp
@if($suggestion->user_id)
    <form action="{{ route('admin.suggestions.ask', $suggestion) }}" method="POST" class="space-y-2" data-send-once>
        @csrf
        <x-send-token />
        <label for="ask-body" class="block text-xs text-muted">
            @if($pending)
                پرسش از پیشنهاددهنده. پیشنهاد تا پاسخ او در تب «منتظر پاسخ» می‌ماند.
            @else
                پیام به پیشنهاددهنده، مثلاً توضیح تصمیم یا سپاس. وضعیت پیشنهاد تغییر نمی‌کند و او می‌تواند پاسخ دهد.
            @endif
            به نام «داور فهارس» فرستاده می‌شود و در «پیام‌ها»ی او و با ایمیل می‌رسد؛ نام و نشانی شما را نمی‌بیند.
        </label>
        <textarea id="ask-body" name="body" rows="3" required maxlength="{{ \App\Models\Message::MAX_LENGTH }}" class="admin-field w-full" placeholder="{{ $pending ? 'مثلاً: آیا نشانی فهرستی را که به آن ارجاع داده‌اید دارید؟' : 'مثلاً: این پیشنهاد را رد کردیم، زیرا…' }}">{{ old('body') }}</textarea>
        @error('body')<p class="text-xs text-cinnabar">{{ $message }}</p>@enderror
        <x-ui.btn type="submit" variant="primary">{{ $pending ? 'فرستادن پرسش' : 'فرستادن پیام' }}</x-ui.btn>
    </form>
@else
    <p class="text-xs text-muted">این پیشنهاد از مهمان و بی حساب کاربری فرستاده شده است؛ پرسش از پیشنهاددهنده ممکن نیست.</p>
@endif
