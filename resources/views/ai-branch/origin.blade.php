@if(!empty($origin))
<input type="hidden" name="origin[kind]" value="{{ $origin['kind'] }}">
@if(isset($origin['id']))<input type="hidden" name="origin[id]" value="{{ $origin['id'] }}">@endif
@endif
