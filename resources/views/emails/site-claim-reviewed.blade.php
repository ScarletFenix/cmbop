@component('mail::message')
@if($approved)
# Your site claim was approved

Ownership of **{{ $siteName }}** has been transferred to your account. You can manage it from My Sites.
@else
# Your site claim was not approved

We reviewed your claim for **{{ $siteName }}** and could not transfer ownership at this time.
@endif

@if($claim->admin_notes)
**Note from our team:**  
{{ $claim->admin_notes }}
@endif

@component('mail::button', ['url' => $actionUrl])
{{ $approved ? 'Open My Sites' : 'View my claims' }}
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
