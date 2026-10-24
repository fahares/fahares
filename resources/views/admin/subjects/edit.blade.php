@extends('layouts.admin')

@section('title', 'ویرایش ' . $subject->name)
@section('page_title', $subject->name)
@section('breadcrumb')
    <a href="{{ route('admin.subjects.index') }}" class="hover:text-cinnabar">موضوعات</a>
    <span>/</span>
    <span>ویرایش #{{ \App\Support\Fa::digits($subject->id) }}</span>
@endsection
@section('page_actions')
    @can('subjects.merge')
        <x-ui.btn :href="route('admin.subjects.merge', ['source_subject_id' => $subject->id])" size="sm">ادغام با موضوع دیگر</x-ui.btn>
    @endcan
    <x-ui.btn :href="route('subjects.show', $subject)" target="_blank" size="sm">صفحه در سایت ↗</x-ui.btn>
@endsection

@section('content')
<x-ui.card class="max-w-2xl space-y-5 text-[13px]">
    <p class="text-muted">آثار: <b class="text-ink tabular-nums">{{ \App\Support\Fa::num($subject->works_count) }}</b></p>
    <form action="{{ route('admin.subjects.update', $subject) }}" method="POST" class="space-y-5">
        @csrf
        @method('PUT')
        @include('admin.subjects.partials.fields', ['subject' => $subject])
        <x-admin.form-actions :cancel="route('admin.subjects.index')" />
    </form>
</x-ui.card>
@endsection
