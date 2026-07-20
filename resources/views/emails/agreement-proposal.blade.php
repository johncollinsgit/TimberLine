<p>Hello,</p>
<p>{{ $agreement->tenant->name }} has a secure Everbranch agreement ready for your review, electronic acceptance, and any applicable payment.</p>
<p><a href="{{ $proposalUrl }}">Open the secure agreement</a></p>
<p>Proposal password: <strong>{{ $password }}</strong></p>
<p>This access expires {{ optional($agreement->access_expires_at)->toDayDateTimeString() }}. Do not forward this message.</p>
