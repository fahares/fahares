@extends('layouts.admin')

@section('title', 'ویرایش ' . $user->name)
@section('page_title', $user->name)
@section('breadcrumb')
    <a href="{{ route('admin.users.index') }}" class="hover:text-cinnabar">کاربران</a>
    <span>/</span>
    <span>ویرایش #{{ \App\Support\Fa::digits($user->id) }}</span>
@endsection

@section('content')
<x-ui.card class="max-w-2xl space-y-5 text-[13px]">
    <div class="flex items-center gap-3">
        <x-ui.avatar :user="$user" size="lg" />
        <div class="min-w-0">
            <p class="text-muted break-all" dir="ltr">{{ $user->email }}</p>
            <p class="text-xs text-muted">عضو از @jdate($user->created_at)</p>
            {{-- Given by the user for scholarly cooperation; never public (backlog item 74) --}}
            @can('users.contact')
                <p class="text-xs text-muted">شماره‌ی تماس:
                    @if($user->phone)<a href="tel:{{ $user->phone }}" class="text-ink hover:text-cinnabar" dir="ltr">{{ $user->phone }}</a>@else نداده است@endif
                </p>
            @endcan
        </div>
    </div>

    <form action="{{ route('admin.users.update', $user) }}" method="POST" class="space-y-5">
        @csrf
        @method('PUT')

        <x-admin.field label="نام و نام خانوادگی" for="name" error="name">
            <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required class="admin-field w-full font-bold">
        </x-admin.field>

        <x-admin.field label="نقش" for="role" error="role">
            <select name="role" id="role" required class="admin-field w-full">
                @foreach(\App\Models\Role::options() as $key => $label)
                    <option value="{{ $key }}" @selected(old('role', $user->role) === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @can('roles.manage')
                <p class="text-xs text-muted">دسترسی هر نقش در <a href="{{ route('admin.roles.index') }}" class="text-cinnabar hover:underline">ماتریس دسترسی</a> تعیین می‌شود.</p>
            @endcan
        </x-admin.field>

        <x-admin.field label="وابستگی علمی" for="affiliation">
            <input type="text" name="affiliation" id="affiliation" value="{{ old('affiliation', $user->affiliation) }}"
                   placeholder="مثال: دانشگاه تهران، فرهنگستان زبان و ادب فارسی" class="admin-field w-full">
        </x-admin.field>

        <label class="flex items-start gap-2 cursor-pointer">
            <input type="checkbox" name="is_verified_scholar" value="1" @checked($user->is_verified_scholar) class="mt-1 size-4 accent-cinnabar">
            <span><b>پژوهشگر تأییدشده</b> <span class="text-xs text-muted">(نشان تأیید علمی و اعتماد سامانه)</span></span>
        </label>

        <x-admin.form-actions :cancel="route('admin.users.index')" />
    </form>
</x-ui.card>

{{-- The user's recent searches, when they keep their history (backlog item 83) --}}
@can('search.stats')
    @php $searches = $user->search_history ? \App\Models\SearchLog::where('user_id', $user->id)->latest('id')->limit(30)->get() : collect(); @endphp
    <x-ui.card class="max-w-2xl mt-5 space-y-3 text-[13px]">
        <h2 class="rubric text-base">جستجوهای اخیر</h2>
        @if(! $user->search_history)
            <p class="text-xs text-muted">این کاربر نگه‌داری سابقه‌ی جستجو را خاموش کرده است.</p>
        @elseif($searches->isEmpty())
            <p class="text-xs text-muted">جستجویی ثبت نشده است.</p>
        @else
            <ul class="divide-y divide-line">
                @foreach($searches as $s)
                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-1.5">
                        <a href="{{ route('search', ['q' => $s->query, 'type' => $s->type]) }}" target="_blank" class="font-bold text-ink hover:text-cinnabar">{{ $s->query !== '' ? $s->query : 'فقط فیلتر' }}</a>
                        <span class="text-xs text-muted">{{ ['works' => 'آثار', 'manuscripts' => 'نسخه‌ها', 'people' => 'اشخاص'][$s->type] ?? $s->type }} · {{ \App\Support\Fa::num($s->results_count) }} نتیجه{{ $s->clicked_position ? ' · رتبه‌ی ' . \App\Support\Fa::digits($s->clicked_position) . ' باز شد' : '' }}</span>
                        <span class="ms-auto text-xs text-muted whitespace-nowrap">@jdate($s->created_at, 'datetime')</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
@endcan
@endsection
