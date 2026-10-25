@extends('layouts.admin')

@section('title', 'ماتریس دسترسی')
@section('page_title', 'ماتریس دسترسی نقش‌ها')

@section('content')
@php
    $super = \App\Auth\Permissions::SUPER_ROLE;
    $editable = $roles->where('key', '!=', $super);
    // Granting these lets a role manage people's accounts or this matrix; worth a second look
    $sensitive = ['users.manage', 'roles.manage', 'audit.rollback', 'system.manage'];
@endphp
<div class="space-y-5 max-w-5xl text-[13px]" x-data="{ active: @js($editable->first()?->key) }">

    <p class="text-muted leading-7">
        هر سطر یک بخش یا عمل پنل است و هر ستون یک نقش. مدیر کل همیشه به همه‌چیز دسترسی دارد و ستونش تغییر نمی‌کند.
        دسترسی در خود مسیرها بررسی می‌شود، نه فقط در منو. هر تغییر در لاگ ممیزی ثبت و به مدیران کل اعلان می‌شود.
    </p>

    {{-- On a phone one role is shown at a time --}}
    <div class="md:hidden flex flex-wrap gap-1.5 text-xs">
        @foreach($editable as $role)
            <button type="button" @click="active = @js($role->key)"
                :class="active === @js($role->key) ? 'border-cinnabar bg-cinnabar/5 text-cinnabar font-bold' : 'border-line bg-surface text-ink'"
                class="px-3 py-1 rounded-sm border transition cursor-pointer">{{ $role->label }}</button>
        @endforeach
    </div>

    <form id="matrix-form" action="{{ route('admin.roles.update') }}" method="POST"
          @submit="const changed = [...$el.querySelectorAll('input[type=checkbox]:not(:disabled)')].filter(c => c.checked !== c.defaultChecked);
                   if (!changed.length) { $event.preventDefault(); alert('تغییری داده نشده است.'); return; }
                   if (!confirm('این تغییرها ذخیره شوند؟\n\n' + changed.map(c => (c.checked ? '+ ' : '− ') + c.dataset.role + ': ' + c.dataset.label).join('\n'))) $event.preventDefault()">
        @csrf
        @method('PUT')

        <div class="bg-surface border border-line">
            <table class="w-full text-right text-[13px]">
                <thead class="bg-paper text-xs text-muted border-b border-line">
                    <tr>
                        <th class="p-3 md:p-4 font-bold">بخش یا عمل</th>
                        @foreach($roles as $role)
                            <th class="p-3 md:p-4 font-bold text-center w-24 {{ $role->key === $super ? 'hidden md:table-cell' : '' }}"
                                @if($role->key !== $super) :class="active !== @js($role->key) && 'hidden md:table-cell'" @endif>
                                <span class="block text-ink">{{ $role->label }}</span>
                                <span class="block text-[11px] font-normal text-muted">{{ \App\Support\Fa::num($counts[$role->key] ?? 0) }} کاربر</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                @foreach($groups as $group => $permissions)
                    <tbody class="divide-y divide-line border-t border-line">
                        <tr class="bg-paper/60">
                            <th colspan="{{ $roles->count() + 1 }}" class="px-3 md:px-4 py-2"><span class="rubric text-sm">{{ $group }}</span></th>
                        </tr>
                        @foreach($permissions as $key => $permission)
                            <tr class="hover:bg-paper transition">
                                <td class="p-3 md:p-4">
                                    <span class="font-bold text-ink">{{ $permission['label'] }}</span>
                                    @if(in_array($key, $sensitive, true))
                                        <span class="ms-1 px-1.5 rounded-sm border border-cinnabar/40 bg-cinnabar/5 text-[11px] text-cinnabar">حساس</span>
                                    @endif
                                    <span class="block text-xs text-muted mt-0.5">{{ $permission['description'] }}</span>
                                </td>
                                @foreach($roles as $role)
                                    @if($role->key === $super)
                                        <td class="p-3 md:p-4 text-center hidden md:table-cell" title="مدیر کل همیشه این دسترسی را دارد">
                                            <input type="checkbox" checked disabled class="size-4 accent-cinnabar opacity-50 cursor-not-allowed" aria-label="{{ $role->label }}: {{ $permission['label'] }}">
                                        </td>
                                    @else
                                        <td class="p-3 md:p-4 text-center" :class="active !== @js($role->key) && 'hidden md:table-cell'">
                                            <input type="checkbox" name="grants[{{ $role->key }}][]" value="{{ $key }}"
                                                @checked(in_array($key, $matrix[$role->key] ?? [], true))
                                                data-role="{{ $role->label }}" data-label="{{ $permission['label'] }}"
                                                class="size-4 accent-cinnabar cursor-pointer" aria-label="{{ $role->label }}: {{ $permission['label'] }}">
                                            @if(in_array($key, $defaults[$role->key] ?? [], true) !== in_array($key, $matrix[$role->key] ?? [], true))
                                                <span class="block text-[10px] text-gold mt-0.5" title="با مقدار پیش‌فرض فرق دارد">≠ پیش‌فرض</span>
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
        <x-ui.btn type="submit" form="matrix-form" variant="primary">ذخیره‌ی دسترسی‌ها</x-ui.btn>
        <form action="{{ route('admin.roles.reset') }}" method="POST" onsubmit="return confirm('دسترسی همه‌ی نقش‌ها به مقدار پیش‌فرض بازگردد؟')">
            @csrf
            <x-ui.btn type="submit" variant="ghost">بازگشت به پیش‌فرض</x-ui.btn>
        </form>
    </div>
</div>
@endsection
