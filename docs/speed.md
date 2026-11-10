# Speed

Settings: `scripts/setup-speed.php` (LiteSpeed Cache). Theme: no emoji script (`functions.php`).

## Measured

Lighthouse 12 (the engine behind Google PageSpeed), **mobile**, local site, 10 Nov 2026:

| Page | Performance | First content | Largest content | Blocking time | Layout shift |
|---|---|---|---|---|---|
| Home | **100** | 1.2 s | 1.4 s | 0 ms | 0 |

Target in TASKS.md: **80+ on mobile**. Why it is fast:

- No jQuery or other heavy scripts on news pages (only on the form pages that need it).
- Pictures load as you scroll; the top story picture loads first (`fetchpriority="high"`).
- System fonts (no web-font download), small child-theme CSS, no page builder.
- No emoji script, no Gravatar requests (Task 17).

**The official number must be taken on the live site** (PageSpeed cannot reach `localhost`, and on
the live site the server's own response time counts):

1. After going live, open **https://pagespeed.web.dev** → type `https://citycharcha.com` → **Analyze**.
2. Look at the **Mobile** tab, *Performance*. Target: 80 or more. Check one story page too.

## LiteSpeed Cache (works on Hostinger's LiteSpeed server)

| Setting | Value | Why |
|---|---|---|
| Page cache | On; home 30 min, stories 6 hours | Fast for visitors; the Breaking strip never stays long after its 24 hours. Publishing a story refreshes the affected pages at once |
| Logged-in people | Private cache, 30 min | Personal pages are never shown to anyone else |
| Never cached | Grievance Officer, My Account, Write, Edit story, login | Forms with security tokens and personal pages |
| API answers | Never cached | Likes, follows, reports are personal |
| Browser cache | 30 days | Pictures, CSS and JS are not downloaded again |
| CSS / JS / HTML | Minified, not combined | Smaller files; combining is slower on modern connections |
| Defer JavaScript | **Off** | Breaks the Write page's form scripts (Task 11) |
| Lazy load images | Off in LiteSpeed | The theme already does it, and keeps the top story picture first |
| Image optimisation (QUIC.cloud) | Off | Needs an account; EWWW does it (Task 15) |
| Guest mode, crawler | Off | For very large sites; costs server capacity |

### Check after going live

1. Open the site in a private window, press F12 → **Network** → reload → click the page →
   **Headers**: look for `x-litespeed-cache: hit` (second load). `miss` on the first load is normal.
2. Log in and check that My Account, likes and the Write page work (they must never be cached).

## Re-measuring locally (developer)

```
npx lighthouse@12.3.0 http://localhost:8080/ --only-categories=performance --form-factor=mobile --chrome-flags="--headless=new"
```
(needs Node.js and Chrome; npx downloads Lighthouse the first time.)
