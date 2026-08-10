@component('mail::message')
# New in the catalog

Hi {{ $firstName }},

We have added {{ $rows->count() }} {{ $rows->count() === 1 ? 'site' : 'sites' }} you have not ordered from yet@if($discounted > 0), and {{ $discounted === 1 ? 'one is' : $discounted.' are' }} running an offer@endif.

@component('mail::table')
| Site | DR | Traffic | Price |
| :--- | ---: | ---: | ---: |
@foreach($rows as $row)
| {{ $row['site']->site_name }}{{ $row['is_new'] ? ' — new' : '' }} | {{ $row['site']->dr ?? '—' }} | {{ $row['site']->traffic ? number_format($row['site']->traffic) : '—' }} | @if(!empty($row['discount']) && !empty($row['was']))~~€{{ number_format($row['was'], 0) }}~~ **€{{ number_format($row['price'], 0) }}** (−{{ rtrim(rtrim(number_format((float) $row['discount'], 1), '0'), '.') }}%)@else€{{ number_format($row['price'], 0) }}@endif |
@endforeach
@endcomponent

@component('mail::button', ['url' => $catalogUrl])
Browse the catalog
@endcomponent

Filter by country, niche, DR or traffic to find the rest — this is a small slice of what is available.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
