{{-- Visitor chat (public + advertiser/publisher). Order chat is separate. --}}
@php
    $tawkSrc = \App\Support\TawkChat::embedSrc();
    $tawkVisitor = null;
    if ($tawkSrc && auth()->check()) {
        $user = auth()->user();
        $name = trim((string) ($user->name ?? ''));
        $email = trim((string) ($user->email ?? ''));
        if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $tawkVisitor = ['name' => $name, 'email' => $email];
        }
    }
@endphp
@if ($tawkSrc)
<script>
var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
@if ($tawkVisitor)
Tawk_API.visitor = {!! json_encode($tawkVisitor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
@endif
window.slbOpenSupport = function () {
  if (window.Tawk_API && typeof window.Tawk_API.maximize === 'function') {
    window.Tawk_API.maximize();
    return;
  }
  var toggle = document.getElementById('helpFeedbackToggle');
  if (toggle) toggle.click();
};
(function(){
var s1=document.createElement('script'),s0=document.getElementsByTagName('script')[0];
s1.async=true;
s1.src={!! json_encode($tawkSrc, JSON_UNESCAPED_SLASHES) !!};
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
s0.parentNode.insertBefore(s1,s0);
})();
</script>
@else
<script>
window.slbOpenSupport = function () {
  var toggle = document.getElementById('helpFeedbackToggle');
  if (toggle) toggle.click();
};
</script>
@endif
