@props(['estimate', 'name'])
@use('App\Support\Fa')
{{-- A date nothing documents, as fahares:estimate-dates reckons it (step 14.170): set apart from a documented date by
     its «تخمینی» mark. «چرا؟» opens x-date-estimate-why of the same name, a row of its own under this one --}}
<span x-data data-date-estimate>
    <span class="text-muted">{{ Fa::digits($estimate['text']) }}</span>
    <span class="ms-1 px-1.5 py-px border border-line rounded-sm text-[11px] text-muted align-middle">تخمینی</span>
    <button type="button" class="ms-1 text-[12px] font-bold text-cinnabar hover:underline cursor-pointer" @click="$dispatch('estimate-why', @js($name))">چرا؟</button>
</span>
