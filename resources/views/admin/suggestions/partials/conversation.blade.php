{{-- Questions to the submitter before deciding (backlog item 91): the thread, and the form to ask --}}
@php
    $thread = $suggestion->thread;
    $canAsk = $suggestion->status === 'pending' && $suggestion->user_id;
@endphp
@if($thread || $suggestion->status === 'pending')
    <x-ui.card class="space-y-3" id="conversation">
        <h3 class="rubric text-base">گفت‌وگو با پیشنهاددهنده</h3>
        @if($suggestion->awaiting_reply_since)
            <x-admin.notice tone="warn">
                منتظر پاسخ پیشنهاددهنده از @jago($suggestion->awaiting_reply_since)؛ این پیشنهاد تا پاسخ او از صف «در انتظار» بیرون است.
                اگر تا {{ \App\Support\Fa::num((int) config('notifications.question_deadline_days')) }} روز پاسخی نرسد، با برچسب «بی‌پاسخ» به صف برمی‌گردد. می‌توانید پیش از آن هم تصمیم بگیرید.
            </x-admin.notice>
        @elseif($suggestion->reply_state === 'unanswered' && $suggestion->status === 'pending')
            <x-admin.notice tone="warn">پرسش بی‌پاسخ ماند و پیشنهاد به صف برگشت.</x-admin.notice>
        @endif

        @if($thread)
            @include('messages.partials.letters', ['thread' => $thread, 'staffView' => true])
        @endif

        @if($canAsk)
            <form action="{{ route('admin.suggestions.ask', $suggestion) }}" method="POST" class="space-y-2">
                @csrf
                <label for="ask-body" class="block text-xs text-muted">پرسش از پیشنهاددهنده. به نام «داور فهارس» فرستاده می‌شود و در «پیام‌ها»ی او و با ایمیل می‌رسد؛ نام و نشانی شما را نمی‌بیند.</label>
                <textarea id="ask-body" name="body" rows="3" required maxlength="{{ \App\Models\Message::MAX_LENGTH }}" class="admin-field w-full" placeholder="مثلاً: آیا نشانی فهرستی را که به آن ارجاع داده‌اید دارید؟">{{ old('body') }}</textarea>
                @error('body')<p class="text-xs text-cinnabar">{{ $message }}</p>@enderror
                <x-ui.btn type="submit" variant="primary">فرستادن پرسش</x-ui.btn>
            </form>
        @elseif($suggestion->status === 'pending')
            <p class="text-xs text-muted">این پیشنهاد از مهمان و بی حساب کاربری فرستاده شده است؛ پرسش از پیشنهاددهنده ممکن نیست.</p>
        @endif
    </x-ui.card>
@endif
