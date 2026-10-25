@extends('layouts.admin')

@section('title', 'موضوع تازه')
@section('page_title', 'موضوع تازه')
@section('breadcrumb')
    <a href="{{ route('admin.subjects.index') }}" class="hover:text-cinnabar">موضوعات</a>
    <span>/</span>
    <span>موضوع تازه</span>
@endsection

@section('content')
<x-ui.card class="max-w-2xl text-[13px]">
    <form action="{{ route('admin.subjects.store') }}" method="POST" class="space-y-5">
        @csrf
        @include('admin.subjects.partials.fields', ['subject' => null])
        <x-admin.form-actions :cancel="route('admin.subjects.index')" submit="ثبت موضوع" />
    </form>
</x-ui.card>
@endsection
