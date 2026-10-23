@php
    $tabsId = 'proposal-tabs-'.\Illuminate\Support\Str::uuid();
    $requestedSection = $forcedSection ?? request()->query('section');
    $activeSection = is_string($requestedSection) && array_key_exists($requestedSection, $sections) ? $requestedSection : $defaultSection;
    if (! array_key_exists($activeSection, $sections)) $activeSection = array_key_first($sections);
@endphp
<fieldset class="proposal-section-tabs">
    <legend>Proposal sections</legend>
    <div class="proposal-section-controls">
    @foreach($sections as $sectionKey => $sectionLabel)
    <input class="proposal-section-control" type="radio" name="{{ $tabsId }}" id="{{ $tabsId }}-{{ $sectionKey }}" data-proposal-section="{{ $sectionKey }}" value="{{ $sectionKey }}" aria-controls="{{ $tabsId }}-panel-{{ $sectionKey }}" @checked($activeSection === $sectionKey)>
    <label class="proposal-section-label" id="{{ $tabsId }}-label-{{ $sectionKey }}" for="{{ $tabsId }}-{{ $sectionKey }}" data-proposal-section-label="{{ $sectionKey }}">{{ $sectionLabel }}</label>
    @endforeach
    </div>
    <div class="proposal-section-panels">
        @foreach($sections as $sectionKey => $sectionLabel)
        <section class="proposal-section-panel" id="{{ $tabsId }}-panel-{{ $sectionKey }}" data-proposal-panel="{{ $sectionKey }}" role="region" aria-labelledby="{{ $tabsId }}-label-{{ $sectionKey }}">
            <h2 class="proposal-print-section">{{ $sectionLabel }}</h2>
            {!! ${$sectionKey} !!}
        </section>
        @endforeach
    </div>
</fieldset>
