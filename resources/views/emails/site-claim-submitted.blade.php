@component('mail::message')
# New site ownership claim

**Site:** {{ $siteName }}  
**Domain:** {{ $claim->domain }}  
**Claimer:** {{ $claimerName }} ({{ $claimerEmail }})  
**Name match:** {{ $claim->name_matches ? 'Yes' : 'No — verify carefully' }}

**Proof message:**  
{{ $claim->proof_message }}

@component('mail::button', ['url' => $adminUrl])
Review site claims
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
