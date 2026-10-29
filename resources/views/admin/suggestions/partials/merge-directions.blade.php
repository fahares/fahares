{{--
    The comparison of a merge for each record the reviewer may keep (backlog item 46): the form's «کدام بماند» shows
    one, and each posts its choices under fields[target] or fields[source], so the controller reads the kept one's.
    Inside relation-approve, with $target (the suggestion's record) and $relationTarget (the other one).
--}}
@foreach(['target' => [$target, $relationTarget], 'source' => [$relationTarget, $target]] as $keep => [$removed, $kept])
    <div x-show="keep === '{{ $keep }}'" @if($keep !== old('keep', 'target')) x-cloak @endif>
        @include('admin.partials.merge-compare', [
            'rows' => \App\Suggestions\MergeComparison::rows($removed, $kept),
            'inputName' => "fields[{$keep}]",
            'keptLabel' => 'رکورد ماندنی (#' . \App\Support\Fa::digits($kept->id) . ')',
            'removedLabel' => 'رکورد حذف‌شونده (#' . \App\Support\Fa::digits($removed->id) . ')',
        ])
    </div>
@endforeach
