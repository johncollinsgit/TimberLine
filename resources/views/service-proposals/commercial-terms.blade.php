@php($commercialSections = \App\Proposals\CommercialSections::from($details))
@if($commercialSections !== null)
<style>
.proposal-commercial-copy table{table-layout:fixed}
.proposal-commercial-copy th,.proposal-commercial-copy td{min-width:0;overflow-wrap:break-word}
.proposal-commercial-copy th{font-size:13px;text-transform:none;letter-spacing:normal}
.proposal-commercial-copy .table-wrap:focus-visible{outline:3px solid #a0bb89;outline-offset:3px}
</style>
@foreach($commercialSections as $section)
<section class="card proposal-commercial-copy">
    <h2>{{ $section['heading'] }}</h2>
    @foreach(($section['tables'] ?? []) as $table)
    <div class="table-wrap" role="region" tabindex="0" aria-label="{{ $table['caption'] }}">
        <table>
            <caption>{{ $table['caption'] }}</caption>
            <thead>
                <tr>
                    @foreach($table['columns'] as $column)
                    <th scope="col">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($table['rows'] as $row)
                <tr>
                    @foreach($row as $cell)
                    @if($loop->first)
                    <th scope="row">{{ $cell }}</th>
                    @else
                    <td>{{ $cell }}</td>
                    @endif
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endforeach
    @foreach(($section['paragraphs'] ?? []) as $paragraph)
    <p class="body-copy">{{ $paragraph }}</p>
    @endforeach
    @if(!empty($section['bullets']))
    <ul>
        @foreach($section['bullets'] as $bullet)
        <li><p>{{ $bullet }}</p></li>
        @endforeach
    </ul>
    @endif
</section>
@endforeach
@else
@include('service-proposals.client-details', ['details' => $details])
@include('service-proposals.implementation-timeline', ['details' => $details])
@endif
