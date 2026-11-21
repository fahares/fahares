@props(['from', 'label' => 'از اثر اصل'])
{{-- The mark beside a value a part reads from its whole (step 14.308): «(از اثر اصل: جوهریه سیمی)» --}}
<span class="text-xs font-normal text-muted whitespace-nowrap" data-inherited>({{ $label }}: <a href="{{ route('works.show', $from) }}" class="border-b border-line-strong hover:text-cinnabar hover:border-cinnabar transition">{{ $from->primary_title }}</a>)</span>
