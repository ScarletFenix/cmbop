# Country domains (owner checklist)

This Laravel PR does **not** acquire domains, change DNS, or deploy Hostinger
redirects. Source intends **ccTLD → seolinkbuildings.com with path retention**
(`CanonicalHost` + `CountryHost`). Live DNS/hosting is outside the app.

Verified live (unified GSC + fetch report, 2026-11):

| Host | Live behaviour | Source intent |
|---|---|---|
| seolinkbuildings.com | Canonical public site | Apex. Keep. |
| www.seolinkbuildings.com | 301 → apex | `CanonicalHost` www hop. |
| seolinkbuildings.co.uk | Deep paths collapse to locale home | App 301s to `.com` **keeping the path** (`/marketplace` → `/marketplace`, `/de/marktplatz` → `/de/marktplatz`). Edge/hosting must not strip the path. |
| seolinkbuildings.de | Same path collapse | Same as `.co.uk`. Locale for this host is `de`. |
| seolinkbuildings.nl | Same path collapse | Locale `nl`. |
| seolinkbuildings.es | Same path collapse | Locale `es`. |
| seolinkbuildings.ch | Root and `/marketplace` → `.com/de` | Source maps `.ch` → locale `ch` (`/ch`, `/ch/marktplatz`). Live `/de` is **wrong** and is not fixed by this PR. |
| seolinkbuildings.uk | Parked | Mapped to `en`. Point DNS at the same Hostinger site as `.com` **or** leave parked; do not invent a second site. |
| seolinkbuildings.fr | Public NXDOMAIN | Mapped to `fr`. Register/point only if owned; otherwise leave undocumented in GSC. |
| seolinkbuildings.it | Public NXDOMAIN | Mapped to `it`. Same as `.fr`. |
| seolinkbuildings.pt | In `config/i18n.php` `country_hosts` | Locale `pt`. Not in the hardcoded `CountryHost` fallback; config is enough **if** the request reaches Laravel. |
| Other listed ccTLDs | Many NXDOMAIN | Map exists in `CountryHost` / `config/i18n.php`. Ownership unverified. |

## Owner configuration (required before any ccTLD is advertised)

1. Confirm **which names are actually registered** to Teqno Ltd / SEOLinkBuildings. Do not add GSC properties for NXDOMAIN hosts.
2. For each owned name, set DNS A/AAAA/CNAME to the **same** Hostinger site as `seolinkbuildings.com`. Do not create a second document root.
3. Hostinger / edge HTTPS: one hop HTTP→HTTPS. Do not add a second app hop.
4. Hostinger / edge redirect, if any, must **preserve path and query**:
   - `https://seolinkbuildings.ch/marketplace` → `https://seolinkbuildings.com/ch/marktplatz`
   - `https://seolinkbuildings.ch/` → `https://seolinkbuildings.com/ch`
   - **Not** `/de`. That is the live defect.
5. After DNS answers, verify from outside:
   - `curl -sI https://seolinkbuildings.ch/` → `301` Location `https://seolinkbuildings.com/ch`
   - `curl -sI https://seolinkbuildings.ch/marketplace` → Location contains `/ch/marktplatz` (or `/ch/marketplace` which the app then localises)
   - `curl -sI https://seolinkbuildings.de/about` → Location contains `/de/ueber-uns` or `/de/about` (path retained, then localised)
6. Add only **owned, resolving** hosts in GSC. Do not submit sitemaps on ccTLDs; the sitemap lives on `.com`.
7. Do not change Laravel `CountryHost` locale map to match a wrong live redirect. Fix the edge to match source (`ch` → `/ch`).

## Out of scope for app PRs

- Buying or transferring `.fr` / `.it` / `.uk` / other ccTLDs
- GSC sitemap or inspect-URL submissions
- Production `.env`, Hostinger panel, or DNS writes
- Partner host `partners.seolinkbuildings.com` (separate robots `Disallow: /`)
