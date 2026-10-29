@extends('layouts.admin')

@section('title', 'پیام به کاربران')
@section('page_title', 'پیام به کاربران')
@section('page_actions')
    <x-ui.btn :href="route('admin.messages.templates')" size="sm">قالب‌های آماده</x-ui.btn>
    <x-ui.btn :href="route('admin.messages.create')" variant="primary" size="sm">پیام تازه</x-ui.btn>
@endsection

@section('content')
<x-admin.tabs :options="[
    ['label' => 'همه', 'url' => route('admin.messages.index'), 'active' => ! $filter],
    ['label' => 'گزارش‌شده به دست کاربر', 'url' => route('admin.messages.index', ['filter' => 'reported']), 'active' => $filter === 'reported'],
]" />
<p class="mb-3 text-xs text-muted">پیام‌های مستقیم مدیریت فهارس به کاربران و پاسخ‌هایشان. پرسش‌های داوران درباره‌ی پیشنهادها در صفحه‌ی هر پیشنهاد آمده است.</p>

<x-admin.table :paginator="$threads" :is-empty="$threads->isEmpty()" empty="پیامی فرستاده نشده است.">
    <x-slot:head>
        <x-admin.th>موضوع</x-admin.th>
        <x-admin.th>کاربر</x-admin.th>
        <x-admin.th>فرستنده</x-admin.th>
        <x-admin.th>آخرین پیام</x-admin.th>
    </x-slot:head>
    @foreach($threads as $t)
        @php $last = $t->messages->last(); @endphp
        <x-admin.row>
            <x-admin.cell main>
                <a href="{{ route('admin.messages.show', $t) }}" class="font-bold text-ink hover:text-cinnabar">{{ $t->subject }}</a>
                <span class="block text-xs text-muted">
                    @if($t->unreadByStaff())<b class="text-cinnabar">پاسخ تازه</b> · @endif
                    @if($t->reported_at)<b class="text-cinnabar">گزارش‌شده</b> · @endif
                    @if($t->closed)بسته · @endif
                    {{ \App\Support\Fa::num($t->messages->count()) }} پیام
                </span>
            </x-admin.cell>
            <x-admin.cell label="کاربر">{{ $t->user?->name }}</x-admin.cell>
            <x-admin.cell label="فرستنده">{{ $t->starter?->name ?? '—' }}</x-admin.cell>
            <x-admin.cell label="آخرین پیام">{{ $last && ! $last->is_staff ? 'پاسخ کاربر' : 'از سوی ما' }} · @jago($t->last_message_at)</x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection
