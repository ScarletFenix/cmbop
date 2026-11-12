@component('mail::message')
@if($batched)
# {{ $rows->count() }} orders are waiting to be published

Hello,

These accepted orders are past the turnaround time listed on your sites:

@component('mail::table')
| Order | Site | Was due | Payout |
| :--- | :--- | :--- | ---: |
@foreach($rows as $row)
| #{{ $row['order_number'] }} | {{ $row['site_name'] }} | {{ $row['due_at']->format('j M') }} ({{ $row['overdue_label'] }}) | {{ \App\Support\MailNotificationPresenter::euro((float) $row['payout']) }} |
@endforeach
@endcomponent

Total waiting to be released: {{ \App\Support\MailNotificationPresenter::euro((float) $rows->sum('payout')) }}.
@else
@php($row = $rows->first())
# {{ $stage <= 1 ? 'Due soon: order #'.$row['order_number'] : 'Order #'.$row['order_number'].' is overdue' }}

Hello,

@if($stage <= 1)
Your guest post for {{ $row['site_name'] }} is due {{ $row['due_at']->format('j M, H:i') }} — that is the {{ $row['promised'] }} turnaround listed on the site.

Publishing on time is the single biggest driver of repeat orders, and {{ \App\Support\MailNotificationPresenter::euro((float) $row['payout']) }} is released as soon as the advertiser approves the live link.
@else
Your guest post for {{ $row['site_name'] }} was due {{ $row['due_at']->format('j M, H:i') }} — that is {{ $row['overdue_label'] }}, against the {{ $row['promised'] }} turnaround you listed.

The advertiser is waiting, and {{ \App\Support\MailNotificationPresenter::euro((float) $row['payout']) }} stays on hold until the live link is submitted.
@endif
@endif

@component('mail::button', ['url' => $tasksUrl])
{{ $batched ? 'Open my tasks' : 'Submit the live link' }}
@endcomponent

@if($stage >= 4)
This is the final reminder. Our team is reviewing {{ $batched ? 'these orders' : 'this order' }} and may refund the advertiser so they can order elsewhere. If you have published already, paste the live URL now and reply in the order chat.
@elseif($stage >= 3)
Our team has been copied on this reminder. If something is blocking publication, send a message in the order chat so the advertiser knows where things stand.
@elseif($stage >= 2)
If you need longer, message the advertiser in the order chat — most are happy to wait when they are kept informed.
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
