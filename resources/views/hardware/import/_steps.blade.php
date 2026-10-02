{{-- ERS Phase 6A: step indicator for the secure asset CSV import. --}}
@php
    $ersImportSteps = ['upload', 'target', 'mapping', 'review'];
    $ersImportCurrent = array_search($current, $ersImportSteps, true);
@endphp
<ol class="list-inline" aria-label="{{ trans('admin/hardware/import.title') }}" style="margin-bottom: 15px;">
    @foreach ($ersImportSteps as $index => $step)
        <li @if ($index === $ersImportCurrent) aria-current="step" @endif>
            <span class="label {{ $index === $ersImportCurrent ? 'label-primary' : ($index < $ersImportCurrent ? 'label-success' : 'label-default') }}">
                {{ $index + 1 }}. {{ trans('admin/hardware/import.steps.'.$step) }}
            </span>
        </li>
    @endforeach
</ol>
