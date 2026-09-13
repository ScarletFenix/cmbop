{{-- Public visitor chat. IDs come from config; invalid values render nothing. --}}
@php($tawkSrc = \App\Support\TawkChat::embedSrc())
@if ($tawkSrc)
<script>
var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
(function(){
var s1=document.createElement('script'),s0=document.getElementsByTagName('script')[0];
s1.async=true;
s1.src={!! json_encode($tawkSrc, JSON_UNESCAPED_SLASHES) !!};
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
s0.parentNode.insertBefore(s1,s0);
})();
</script>
@endif
