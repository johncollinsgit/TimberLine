<input type="hidden" name="episode" value="{{ $episode['id'] }}">
<input type="hidden" name="revision" value="{{ $episode['revision'] }}">
<label>Video asset<select class="block w-full" name="asset" required>
@foreach($episode['assets'] as $asset)
    @if($asset['kind'] !== 'captions')<option value="{{ $asset['digest'] }}">{{ ucfirst(str_replace('_', ' ', $asset['kind'])) }} · {{ substr($asset['digest'], 0, 8) }}</option>@endif
@endforeach
</select></label>
