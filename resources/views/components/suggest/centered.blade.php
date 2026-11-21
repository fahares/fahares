@props(['name'])
{{-- A centred line with its suggestion controls (the readings badge and the pencil of <x-suggest.field>). From sm up the
     controls hang outside the text, at its inline end, so the text alone is centred; an equal margin on both sides keeps
     room for them when the text fills the line (the parent shrinks to its content, so a percentage max width would
     not). On a phone they stay in the flow, where a long line has no room beside it. suggest-row lights the faint
     pencil on hover (app.css). --}}
<span {{ $attributes->merge(['class' => 'suggest-row relative inline-block sm:mx-20']) }}>{{ $slot }}<span class="inline-flex items-center gap-1 ms-1 align-middle whitespace-nowrap sm:absolute sm:top-1/2 sm:-translate-y-1/2 sm:start-full sm:ms-2" data-suggest-controls><x-suggest.field :name="$name" /></span></span>
