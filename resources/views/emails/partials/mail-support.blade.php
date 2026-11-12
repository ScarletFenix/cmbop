@php
    $supportLine = $supportLine ?? \App\Support\MailNotificationPresenter::supportLine($intent ?? 'order');
@endphp
@if(filled($supportLine))
<p style="margin:8px 0 0;color:#64748b;font-size:14px;line-height:1.5;">{{ $supportLine }}</p>
@endif
