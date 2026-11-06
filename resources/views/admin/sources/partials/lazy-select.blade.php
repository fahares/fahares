{{-- A select of a long list (libraries, catalogers) that carries only its value; the page's script adds the rest of
     the list (data-fill) when the form is opened, so a volume of many parts does not send the lists once per part --}}
@php
    $items = $list === 'libraries' ? $libraries : $catalogers;
    $chosen = $value ? $items->firstWhere('id', (int) $value) : null;
@endphp
<select name="{{ $name }}" @isset($id) id="{{ $id }}" @endisset @isset($label) aria-label="{{ $label }}" @endisset data-fill="{{ $list }}" class="admin-field {{ $class ?? 'w-full' }}">
    <option value="">{{ $empty }}</option>
    @if($chosen)
        <option value="{{ $chosen->id }}" selected>{{ $list === 'libraries' ? $chosen->name . ' (' . $chosen->city . ')' : $chosen->name }}</option>
    @endif
</select>
