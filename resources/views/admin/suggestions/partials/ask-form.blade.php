{{-- A question to the submitter (before the decision) or a letter (after it); backlog item 91, sent once (step 14.71).
     $reply: the form under the letters, an answer in the same thread (step 14.96); the decision panel has the other one. --}}
@php
    $pending ??= $suggestion->status === 'pending';
    $reply ??= false;
    $fieldId = $reply ? 'reply-body' : 'ask-body';
@endphp
@if($suggestion->user_id)
    <form action="{{ route('admin.suggestions.ask', $suggestion) }}" method="POST" class="space-y-2" data-send-once>
        @csrf
        <x-send-token />
        <label for="{{ $fieldId }}" class="block text-xs text-muted">
            @if($reply)
                <b class="block text-[13px] text-ink mb-0.5">پاسخ در همین گفت‌وگو</b>
            @endif
            @if($pending && $reply)
                پیشنهاد تا پاسخ او در تب «منتظر پاسخ» می‌ماند؛ تصمیم را در پنل کناری بگیرید.
            @elseif($pending)
                پرسش از پیشنهاددهنده. پیشنهاد تا پاسخ او در تب «منتظر پاسخ» می‌ماند.
            @else
                پیام به پیشنهاددهنده، مثلاً توضیح تصمیم یا سپاس. وضعیت پیشنهاد تغییر نمی‌کند و او می‌تواند پاسخ دهد.
            @endif
            به نام «داور فهارس» فرستاده می‌شود و در «پیام‌ها»ی او و با ایمیل می‌رسد؛ نام و نشانی شما را نمی‌بیند.
        </label>
        <textarea id="{{ $fieldId }}" name="body" rows="3" required maxlength="{{ \App\Models\Message::MAX_LENGTH }}" class="admin-field w-full" placeholder="{{ $reply ? 'پاسخ به پیام پیشنهاددهنده…' : ($pending ? 'مثلاً: آیا نشانی فهرستی را که به آن ارجاع داده‌اید دارید؟' : 'مثلاً: این پیشنهاد را رد کردیم، زیرا…') }}">{{ old('body') }}</textarea>
        @error('body')<p class="text-xs text-cinnabar">{{ $message }}</p>@enderror
        <x-ui.btn type="submit" variant="primary">{{ $reply ? 'فرستادن پاسخ' : ($pending ? 'فرستادن پرسش' : 'فرستادن پیام') }}</x-ui.btn>
    </form>
@else
    <p class="text-xs text-muted">این پیشنهاد از مهمان و بی حساب کاربری فرستاده شده است؛ پرسش از پیشنهاددهنده ممکن نیست.</p>
@endif
