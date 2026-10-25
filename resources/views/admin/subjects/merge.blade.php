@extends('layouts.admin')

@section('title', 'ادغام موضوعات')
@section('page_title', 'ادغام موضوعات')
@section('breadcrumb')
    <a href="{{ route('admin.subjects.index') }}" class="hover:text-cinnabar">موضوعات</a>
    <span>/</span>
    <span>ادغام</span>
@endsection

@section('content')
@php $option = fn ($s) => $s->name . ' (#' . \App\Support\Fa::digits($s->id) . '، ' . \App\Support\Fa::num($s->works_count) . ' اثر)'; @endphp
<div class="max-w-3xl space-y-5 text-[13px]">
    <x-admin.notice>
        همه‌ی آثار <b>موضوع تکراری</b> به <b>موضوع اصلی</b> منتقل و شمارها دوباره حساب می‌شوند، و موضوع تکراری حذف می‌شود.
        ادغام در لاگ ممیزی ثبت می‌شود و از همان‌جا بازگرداندنی است.
    </x-admin.notice>

    <x-ui.card>
        <form action="{{ route('admin.subjects.merge') }}" method="POST" class="space-y-5">
            @csrf

            <x-admin.field label="۱. موضوع تکراری (حذف می‌شود)" for="source_subject_id" error="source_subject_id">
                <select name="source_subject_id" id="source_subject_id" required class="admin-field w-full !border-cinnabar/50">
                    <option value="">موضوعی را که در موضوع دیگر ادغام می‌شود برگزینید</option>
                    @foreach($subjects as $s)
                        <option value="{{ $s->id }}" @selected(old('source_subject_id', $preloadedSourceId ?? '') == $s->id)>{{ $option($s) }}</option>
                    @endforeach
                </select>
            </x-admin.field>

            <x-admin.field label="۲. موضوع اصلی (می‌ماند)" for="target_subject_id" error="target_subject_id">
                <select name="target_subject_id" id="target_subject_id" required class="admin-field w-full !border-emerald-600/50">
                    <option value="">موضوعی را که آثار به آن می‌رسد برگزینید</option>
                    @foreach($subjects as $s)
                        <option value="{{ $s->id }}" @selected(old('target_subject_id', $preloadedTargetId ?? '') == $s->id)>{{ $option($s) }}</option>
                    @endforeach
                </select>
            </x-admin.field>

            <div class="pt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line">
                <x-ui.btn :href="route('admin.subjects.index')" variant="ghost">انصراف</x-ui.btn>
                <button type="submit" onclick="return confirm('آثار موضوع تکراری به موضوع اصلی منتقل و موضوع تکراری حذف شود؟')"
                        class="px-6 py-2.5 rounded-sm bg-cinnabar text-on-cinnabar font-bold hover:opacity-90 transition cursor-pointer">ادغام موضوعات</button>
            </div>
        </form>
    </x-ui.card>
</div>
@endsection
