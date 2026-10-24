@extends('layouts.admin')

@section('title', 'ماتریس دسترسی')
@section('page_title', 'ماتریس دسترسی نقش‌ها')
@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-cinnabar">پیشخوان</a>
    <span>/</span>
    <span class="text-stone-400">ماتریس دسترسی</span>
@endsection

@section('content')
@php
    $super = \App\Auth\Permissions::SUPER_ROLE;
    $editable = $roles->where('key', '!=', $super);
    // Granting these lets a role manage people's accounts or this matrix; worth a second look
    $sensitive = ['users.manage', 'roles.manage', 'audit.rollback', 'system.manage'];
@endphp
<div class="space-y-6 max-w-5xl" x-data="{ active: @js($editable->first()?->key) }">

    <p class="text-xs text-stone-500 leading-6">
        هر سطر یک بخش یا عمل پنل است و هر ستون یک نقش. مدیر کل همیشه به همه‌چیز دسترسی دارد و ستونش تغییر نمی‌کند.
        دسترسی در خود مسیرها بررسی می‌شود، نه فقط در منو. هر تغییر در لاگ ممیزی ثبت و به مدیران کل اعلان می‌شود.
    </p>

    {{-- On a phone one role is shown at a time --}}
    <div class="md:hidden flex flex-wrap gap-2 text-xs font-bold">
        @foreach($editable as $role)
            <button type="button" @click="active = @js($role->key)"
                :class="active === @js($role->key) ? 'bg-ink text-paper' : 'bg-surface text-ink/80 border border-line'"
                class="px-3 py-1.5 rounded-xl transition cursor-pointer">{{ $role->label }}</button>
        @endforeach
    </div>

    <form id="matrix-form" action="{{ route('admin.roles.update') }}" method="POST"
          @submit="const changed = [...$el.querySelectorAll('input[type=checkbox]:not(:disabled)')].filter(c => c.checked !== c.defaultChecked);
                   if (!changed.length) { $event.preventDefault(); alert('تغییری داده نشده است.'); return; }
                   if (!confirm('این تغییرها ذخیره شوند؟\n\n' + changed.map(c => (c.checked ? '+ ' : '− ') + c.dataset.role + ': ' + c.dataset.label).join('\n'))) $event.preventDefault()">
        @csrf
        @method('PUT')

        <div class="bg-surface rounded-2xl border border-line shadow-xs overflow-hidden">
            <table class="w-full text-right text-xs">
                <thead class="bg-paper text-stone-500 border-b border-line">
                    <tr>
                        <th class="p-3 md:p-4 font-bold">بخش یا عمل</th>
                        @foreach($roles as $role)
                            <th class="p-3 md:p-4 font-bold text-center w-24 {{ $role->key === $super ? 'hidden md:table-cell' : '' }}"
                                @if($role->key !== $super) :class="active !== @js($role->key) && 'hidden md:table-cell'" @endif>
                                <span class="block text-ink">{{ $role->label }}</span>
                                <span class="block text-[10px] font-normal text-stone-400">{{ number_format($counts[$role->key] ?? 0) }} کاربر</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                @foreach($groups as $group => $permissions)
                    <tbody class="divide-y divide-line border-t border-line">
                        <tr class="bg-paper/60">
                            <th colspan="{{ $roles->count() + 1 }}" class="px-3 md:px-4 py-2 text-[11px] font-bold text-cinnabar">{{ $group }}</th>
                        </tr>
                        @foreach($permissions as $key => $permission)
                            <tr class="hover:bg-paper transition">
                                <td class="p-3 md:p-4">
                                    <span class="font-bold text-ink">{{ $permission['label'] }}</span>
                                    @if(in_array($key, $sensitive, true))
                                        <span class="mr-1 px-1.5 py-0.5 rounded-md text-[10px] font-bold bg-rose-500/10 text-rose-700 dark:text-rose-300">حساس</span>
                                    @endif
                                    <span class="block text-[11px] text-stone-500 mt-0.5">{{ $permission['description'] }}</span>
                                </td>
                                @foreach($roles as $role)
                                    @if($role->key === $super)
                                        <td class="p-3 md:p-4 text-center hidden md:table-cell" title="مدیر کل همیشه این دسترسی را دارد">
                                            <input type="checkbox" checked disabled class="w-4 h-4 accent-stone-400 cursor-not-allowed" aria-label="{{ $role->label }}: {{ $permission['label'] }}">
                                        </td>
                                    @else
                                        <td class="p-3 md:p-4 text-center" :class="active !== @js($role->key) && 'hidden md:table-cell'">
                                            <input type="checkbox" name="grants[{{ $role->key }}][]" value="{{ $key }}"
                                                @checked(in_array($key, $matrix[$role->key] ?? [], true))
                                                data-role="{{ $role->label }}" data-label="{{ $permission['label'] }}"
                                                class="w-4 h-4 accent-cinnabar cursor-pointer" aria-label="{{ $role->label }}: {{ $permission['label'] }}">
                                            @if(in_array($key, $defaults[$role->key] ?? [], true) !== in_array($key, $matrix[$role->key] ?? [], true))
                                                <span class="block text-[9px] text-stone-400 mt-0.5" title="با مقدار پیش‌فرض فرق دارد">≠ پیش‌فرض</span>
                                            @endif
                                        </td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    </form>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <button type="submit" form="matrix-form"
            class="px-5 py-2.5 rounded-xl bg-ink text-paper hover:opacity-90 text-xs font-bold shadow-md transition cursor-pointer">ذخیره‌ی دسترسی‌ها</button>
        <form action="{{ route('admin.roles.reset') }}" method="POST" onsubmit="return confirm('دسترسی همه‌ی نقش‌ها به مقدار پیش‌فرض بازگردد؟')">
            @csrf
            <button type="submit" class="px-4 py-2 rounded-xl border border-line-strong hover:bg-paper text-xs font-bold text-ink/80 transition cursor-pointer">بازگشت به پیش‌فرض</button>
        </form>
    </div>
</div>
@endsection
