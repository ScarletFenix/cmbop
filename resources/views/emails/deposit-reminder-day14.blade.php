@component('mail::message')
# Add funds to place your first guest post

Hi {{ $firstName }},

It's been two weeks since you joined as an advertiser, and your wallet still hasn't been topped up with a deposit.

**Why deposit?**
- Pay placements from your EUR wallet
- Use your **€20 welcome credit** together with deposited funds at checkout
- Keep pricing transparent — no outreach chasing

**How to deposit**
1. Open **Add funds**
2. Choose an amount (many teams start with €50–€200)
3. Pay by **card** (instant) or **bank / Wise** (submit a deposit request; we confirm, then credit your wallet)

@component('mail::button', ['url' => $addFundsUrl])
Add funds now
@endcomponent

Prefer to pick sites first? [Open marketplace catalog]({{ $catalogUrl }})

You can turn off marketing emails anytime in notification settings.

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
