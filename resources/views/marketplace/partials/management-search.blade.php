<div class="mm-filterbar" data-mm-filters>
    <label class="mm-search">Search {{ $searchLabel }}<input type="search" data-mm-search placeholder="{{ $searchPlaceholder ?? 'Name, product or status…' }}" autocomplete="off"></label>
    @isset($searchStatuses)<label>Status<select data-mm-status aria-label="Status"><option value="">All statuses</option>@foreach($searchStatuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>@endisset
    <button type="button" class="button secondary compact" data-mm-clear>Clear</button>
    <span class="mm-search-count" data-mm-count role="status" aria-live="polite"></span>
</div>
<p class="fine-print">{{ $searchScope }}</p>
<p class="mm-no-results" data-mm-empty hidden>No matching items. Try another search or clear the filters.</p>
