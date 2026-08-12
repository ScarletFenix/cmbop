# Publisher Tasks — optional social post URLs

## Goal

When a publisher accepts an order and submits the live article URL, they must
also be able to attach links to the social posts they offered (Facebook /
Instagram / X). Those fields stay **optional** — live URL alone is enough.

## Baseline already shipped (do not redo)

| Surface | Behavior |
|---------|----------|
| Tasks → **Submit Live URL** modal | Optional channel URL inputs when `order_items.social_channels` is non-empty |
| Tasks → chat **Resubmit URL** | Same optional inputs on modification request |
| Backend `submitLiveUrl` / `resubmitLiveUrl` | Validates optional URLs; soft host warnings |
| Advertiser order detail | Shows channels + submitted post URLs |
| Admin order show | Same |
| Live-URL email | Lists social channels and any submitted post URLs |

## Gaps to close

1. **Discoverability** — modal copy should state which channels were offered and that social links are optional.
2. **Add later** — publishers often share on social *after* the article is live; there is no path to add/update social URLs without a change-request resubmit.
3. **Tasks row action** — once live URL exists, surface **Add / update social links** when social was offered.

## Implementation steps

### Step 1 — Modal UX (first submit)
- Retitle / clarify optional social block: “Social post URLs (optional)”.
- List offered channels in plain language.
- Keep live URL required; social empty OK.

### Step 2 — Add/update social after live URL
- New `POST publisher/orders/{id}/social-posts` (no live URL required).
- Allowed when item has a live URL, order is `processing` or `review`, and snapshotted `social_channels` is non-empty.
- Persist via existing `SocialPostUrlValidator` + `social_post_urls` column.
- No new email (avoid noise); advertiser sees links on next open of the order.

### Step 3 — Tasks UI wiring
- Reuse a dedicated small modal (or complete modal in social-only mode).
- Prefill existing `social_post_urls`.
- Row button: **Add social links** / **Update social links** when live URL present + channels offered.

### Step 4 — Tests
- Live URL alone still succeeds when social offered.
- Optional social URLs persist on complete.
- Social-only endpoint saves / rejects when no live URL / no channels / wrong status.
- Tasks JSON exposes channels + post URLs for the new button.

### Step 5 — Out of scope
- Homepage placement “proof” URLs.
- Forcing social URLs before advertiser approval.
- Backfilling `social_channels` on pre-feature orders from the live site offer.
