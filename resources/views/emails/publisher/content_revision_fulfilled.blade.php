@component('mail::message')
# Revised article ready

Dear Publisher,

The advertiser sent a revised article for order **#{{ $order->order_number }}** on **{{ $site->site_name }}**.

## Next steps
1. Download the updated article from the task
2. Publish the content
3. Submit the live URL when ready

@component('mail::button', ['url' => route('publisher.tasks', ['focus' => 'order', 'order' => $order->id])])
Open task
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
