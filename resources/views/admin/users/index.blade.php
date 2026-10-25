@extends('layouts.admin')

@section('title', 'کاربران')
@section('page_title', 'کاربران')

@section('content')
@php
    $tabs = [['label' => 'همه', 'url' => route('admin.users.index', array_filter(['q' => $search])), 'active' => ! $role, 'count' => $roleCounts->sum()]];
    foreach ($roles as $key => $label) {
        $tabs[] = ['label' => $label, 'url' => route('admin.users.index', array_filter(['role' => $key, 'q' => $search])), 'active' => $role === $key, 'count' => $roleCounts[$key] ?? 0];
    }
@endphp
<x-admin.tabs :options="$tabs" />

<x-admin.filters :action="route('admin.users.index')" :reset="$search ? route('admin.users.index', array_filter(['role' => $role])) : null" :total="$users->total()" noun="حساب">
    @if($role)<input type="hidden" name="role" value="{{ $role }}">@endif
    <input type="search" name="q" value="{{ $search }}" placeholder="نام یا رایانامه" aria-label="جستجوی کاربر" class="admin-field flex-1 basis-48">
</x-admin.filters>

<x-admin.table :paginator="$users" :is-empty="$users->isEmpty()" empty="کاربری با این مشخصات یافت نشد.">
    <x-slot:head>
        <x-admin.th>نام</x-admin.th>
        <x-admin.th>رایانامه</x-admin.th>
        <x-admin.th>نقش</x-admin.th>
        <x-admin.th>وابستگی علمی</x-admin.th>
        <x-admin.th>عضویت</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($users as $u)
        <x-admin.row>
            <x-admin.cell main>
                <span class="flex items-center gap-2.5">
                    <x-ui.avatar :user="$u" />
                    <span>
                        <span class="font-bold text-ink">{{ $u->name }}</span>
                        @if($u->is_verified_scholar)
                            <span class="text-emerald-700 dark:text-emerald-300" title="پژوهشگر تأییدشده">✓</span>
                        @endif
                        <span class="text-xs text-muted tabular-nums">#{{ \App\Support\Fa::digits($u->id) }}</span>
                    </span>
                </span>
            </x-admin.cell>
            <x-admin.cell label="رایانامه"><span dir="ltr" class="text-muted break-all">{{ $u->email }}</span></x-admin.cell>
            <x-admin.cell label="نقش">
                <x-ui.chip :tone="in_array($u->role, ['admin', 'editor'], true) ? 'accent' : 'default'">{{ $roles[$u->role] ?? $u->role }}</x-ui.chip>
            </x-admin.cell>
            <x-admin.cell label="وابستگی" class="text-muted">{{ $u->affiliation ?: '—' }}</x-admin.cell>
            <x-admin.cell label="عضویت" class="text-muted whitespace-nowrap">@jdate($u->created_at)</x-admin.cell>
            <x-admin.cell actions>
                <span class="inline-flex items-center gap-1">
                    <x-ui.btn :href="route('admin.users.edit', $u)" size="sm">ویرایش</x-ui.btn>
                    @if($u->id !== auth()->id())
                        <form action="{{ route('admin.users.destroy', $u) }}" method="POST" onsubmit="return confirm(@js('حساب «' . $u->name . '» حذف شود؟ این کار برگشت‌پذیر نیست.'))">
                            @csrf
                            @method('DELETE')
                            <x-ui.btn type="submit" size="sm" variant="ghost" class="hover:!text-cinnabar">حذف</x-ui.btn>
                        </form>
                    @endif
                </span>
            </x-admin.cell>
        </x-admin.row>
    @endforeach
</x-admin.table>
@endsection
