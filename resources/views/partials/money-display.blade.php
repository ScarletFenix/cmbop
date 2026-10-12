<script>
window.__slbMoney = @json(money_js());
</script>
<script src="{{ asset('js/slb-money.js') }}?v={{ @filemtime(public_path('js/slb-money.js')) ?: '1' }}"></script>
