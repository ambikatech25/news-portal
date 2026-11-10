# Cloudflare (free plan) for citycharcha.com

**What it gives you:** protection against floods of fake traffic (DDoS) and bad bots before they
reach Hostinger, a free firewall, and faster delivery of pictures across India.
**Do it after** the site works on Hostinger with HTTPS (Task 18). About 30 minutes, plus up to a day
for the domain switch to spread.

Use **either** Cloudflare **or** Hostinger's own CDN, not both. Recommended: Cloudflare (stronger
protection, free).

## Steps

1. Go to **https://dash.cloudflare.com/sign-up** → sign up with the **company e-mail** → confirm the e-mail.
2. **Add a domain** → `citycharcha.com` → **Continue** → choose the **Free** plan → **Continue**.
3. Cloudflare reads your current DNS records. Check the list:
   - `A` record for `citycharcha.com` and `www` → Hostinger's IP address → **Proxied** (orange cloud).
   - **E-mail records (MX, and any `mail`, `autodiscover`, SPF/DKIM `TXT` records): DNS only (grey cloud).**
     If e-mail records are missing, copy them from Hostinger hPanel → Domains → DNS. Missing e-mail
     records = company e-mail stops working.
   → **Continue**.
4. Cloudflare shows **two nameservers** (e.g. `ana.ns.cloudflare.com`). Where you bought the domain
   (registrar) → Domain → **Nameservers** → **Change** → custom → paste the two → **Save**.
   (If the domain was bought at Hostinger: hPanel → Domains → citycharcha.com → DNS / Nameservers.)
5. Back in Cloudflare → **Check nameservers**. When it says *Active* (minutes to a day), continue.
6. **SSL/TLS** → Overview → **Full (strict)**. Edge Certificates → **Always Use HTTPS: On**.
7. **Speed → Optimization**: **Rocket Loader: Off** (it breaks site scripts). Leave other "minify"
   options off; LiteSpeed already does it.
8. **Caching → Configuration**: Caching level **Standard**; Browser Cache TTL **Respect Existing Headers**.
   Do not add rules that cache HTML pages: LiteSpeed caches pages and knows when to refresh them.
9. **Security → WAF**: keep the free managed rules on. **Security → Bots**: Bot Fight Mode **On**.
10. In WordPress: Wordfence → All Options → **How does Wordfence get IPs** → *Use the Cloudflare
    "CF-Connecting-IP" HTTP header* → **Save** (see `security.md`).

## Check

- Open https://citycharcha.com: padlock shown, site works, log in works.
- Send yourself an e-mail at the company address: it still arrives.
- In the browser (F12 → Network → the page → Headers) you see `cf-ray` (Cloudflare) and
  `x-litespeed-cache` (LiteSpeed).
