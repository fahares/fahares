@extends('layouts.admin')

@section('title', 'لاگ ممیزی')
@section('page_title', 'لاگ ممیزی')

@section('content')
@php
    $fa = fn ($v) => \App\Support\Fa::digits($v);
    $tabs = [];
    foreach (['' => 'همه', 'merge' => 'ادغام', 'update' => 'ویرایش', 'verify' => 'تأیید پیشنهاد', 'create' => 'ایجاد', 'remove' => 'حذف', 'split' => 'تفکیک', 'rollback' => 'بازگردانی'] as $key => $label) {
        $tabs[] = ['label' => $label, 'url' => route('admin.audit-logs.index', array_filter(['action' => $key, 'type' => $type])), 'active' => (string) $action === $key];
    }
    $types = collect(\App\Models\AuditLog::TYPE_LABELS)
        ->when(! auth()->user()->can('users.manage'), fn ($t) => $t->except([\App\Models\User::class, \App\Models\Role::class]));
    // A value of a change, short enough for a table cell
    $show = fn ($v) => is_null($v) ? '—' : (is_scalar($v) ? (is_bool($v) ? ($v ? 'بله' : 'خیر') : (string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE));
    $canRollback = auth()->user()->can('audit.rollback');
@endphp
<x-admin.tabs :options="$tabs" />

<x-admin.filters :action="route('admin.audit-logs.index')" :reset="$type ? route('admin.audit-logs.index', array_filter(['action' => $action])) : null" :total="$logs->total()" noun="رخداد">
    @if($action)<input type="hidden" name="action" value="{{ $action }}">@endif
    <select name="type" aria-label="نوع رکورد" class="admin-field">
        <option value="">همه‌ی رکوردها</option>
        @foreach($types as $class => $label)
            <option value="{{ class_basename($class) }}" @selected($type === class_basename($class))>{{ $label }}</option>
        @endforeach
    </select>
</x-admin.filters>

<x-admin.table grouped :paginator="$logs" :is-empty="$logs->isEmpty()" empty="رخدادی با این مشخصات ثبت نشده است.">
    <x-slot:head>
        <x-admin.th>رخداد</x-admin.th>
        <x-admin.th>رکورد</x-admin.th>
        <x-admin.th>کاربر</x-admin.th>
        <x-admin.th>زمان</x-admin.th>
        <x-admin.th><span class="sr-only">کنش‌ها</span></x-admin.th>
    </x-slot:head>
    @foreach($logs as $log)
        @php
            $old = (array) ($log->old_values ?? []);
            $new = (array) ($log->new_values ?? []);
            $keys = array_values(array_unique([...array_keys($old), ...array_keys($new)]));
        @endphp
        <tbody x-data="{ open: false }" class="block md:table-row-group border-b border-line last:border-0">
        <x-admin.row>
            <x-admin.cell main>
                <x-ui.chip :tone="str_starts_with($log->action, 'rollback') || in_array($log->action, ['remove', 'delete'], true) ? 'accent' : ($log->action === 'verify' ? 'good' : 'default')">{{ $log->actionLabel() }}</x-ui.chip>
                <span class="text-xs text-muted tabular-nums">#{{ $fa($log->id) }}</span>
            </x-admin.cell>
            <x-admin.cell label="رکورد">{{ $log->typeLabel() }} <span class="tabular-nums">#{{ $fa($log->auditable_id) }}</span></x-admin.cell>
            <x-admin.cell label="کاربر">
                {{ $log->user?->name ?: 'سامانه' }}
                @if($log->user && auth()->user()->can('users.manage'))
                    <span class="hidden md:block text-xs text-muted" dir="ltr">{{ $log->user->email }}</span>
                @endif
            </x-admin.cell>
            <x-admin.cell label="زمان" class="text-muted whitespace-nowrap">@jdate($log->created_at, 'seconds')</x-admin.cell>
            <x-admin.cell actions>
                <span class="inline-flex items-center gap-1">
                    @if($keys)
                        <x-ui.btn size="sm" @click="open = !open" ::aria-expanded="open">
                            <span x-text="open ? 'بستن' : 'جزئیات'">جزئیات</span>
                        </x-ui.btn>
                    @endif
                    @if($canRollback && in_array($log->action, \App\Models\AuditLog::UNDOABLE, true) && $old)
                        <form action="{{ route('admin.audit-logs.rollback', $log) }}" method="POST"
                              onsubmit="return confirm('این تغییر بازگردانده و وضع پیشین رکورد برگردانده شود؟')">
                            @csrf
                            <x-ui.btn type="submit" size="sm" variant="ghost" title="بازگرداندن به وضع پیش از این تغییر">بازگردانی</x-ui.btn>
                        </form>
                    @endif
                </span>
            </x-admin.cell>
        </x-admin.row>
        {{-- The change field by field, opened under its row --}}
        @if($keys)
            <tr x-show="open" x-cloak class="block md:table-row bg-paper">
                <td colspan="5" class="block md:table-cell px-4 py-3">
                    <dl class="grid grid-cols-1 sm:grid-cols-[max-content_minmax(0,1fr)_minmax(0,1fr)] gap-x-4 gap-y-1 text-xs">
                        <dt class="hidden sm:block font-bold text-muted">فیلد</dt>
                        <dd class="hidden sm:block font-bold text-muted">پیشین</dd>
                        <dd class="hidden sm:block font-bold text-muted">تازه</dd>
                        @foreach($keys as $k)
                            <dt class="font-bold text-ink pt-1 sm:pt-0" dir="auto">{{ $k }}</dt>
                            <dd class="break-words text-cinnabar" dir="auto"><span class="sm:hidden text-muted">پیشین: </span>{{ \Illuminate\Support\Str::limit($show($old[$k] ?? null), 400) }}</dd>
                            <dd class="break-words text-emerald-800 dark:text-emerald-300" dir="auto"><span class="sm:hidden text-muted">تازه: </span>{{ \Illuminate\Support\Str::limit($show($new[$k] ?? null), 400) }}</dd>
                        @endforeach
                    </dl>
                    @if($log->ip_address)
                        <p class="mt-2 text-xs text-muted">نشانی IP: <span dir="ltr">{{ $log->ip_address }}</span></p>
                    @endif
                </td>
            </tr>
        @endif
        </tbody>
    @endforeach
</x-admin.table>
@endsection
