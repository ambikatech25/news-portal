# Going live on Hostinger (Task 18)

Scripts: `scripts/deploy/update-code.sh` (put our theme + plugin on the server),
`scripts/deploy/build-site.sh` (run every setup script, Tasks 2-17, in order).

**How it works:** the live site is a fresh WordPress built by our setup scripts, not a copy of the
local database (which holds test accounts, test reports and demo stories). The result: a clean site
with exactly our settings. Stories, users and photos are then created on the live site.

**Rule (CLAUDE.md):** nothing on the live server is changed without the owner's OK.

## 1. Cost (checked on hostinger.com/in, 10 Nov 2026)

| Item | Price | Notes |
|---|---|---|
| **Hostinger "Unlimited" web hosting** (recommended) | **₹199/month** on a 48-month plan (≈ ₹9,552 + 18% GST ≈ **₹11,270 for 4 years**) | Renews at ₹649/month. Daily backups, NVMe storage, SSH + WP-CLI, free email, CDN. Shorter plans cost more per month: check at checkout |
| Alternative: "Premium" | ₹139/month (48 months) | Cheapest plan with SSH (needed). Weekly backups only, 20 GB |
| Later if traffic grows: "Cloud Startup" | ₹599/month (48 months) | 4 GB RAM, 4 CPU. Upgrade in a few clicks, no rebuild |
| Domain citycharcha.com | already bought | yearly renewal at your registrar |
| SSL certificate, email mailboxes, Cloudflare, all our plugins, Google Drive backups | ₹0 | Hostinger's free email is free for the first year on these plans; check the renewal price |

Not needed yet: Premium membership plugin + Razorpay (Task 20, ask before buying), Google Workspace.

## 2. Owner steps (about 30 minutes)

### A. Buy the hosting
1. https://www.hostinger.com/in/web-hosting → **Unlimited** → choose the period (48 months is cheapest
   per month) → create the account with the **company e-mail** → pay.
2. During setup, if asked about a domain: **"I already have a domain"** → `citycharcha.com`.

### B. Point the domain to Hostinger
- **Bought at Hostinger:** nothing to do.
- **Bought elsewhere** (GoDaddy, BigRock, ...): at that registrar → citycharcha.com → **Nameservers** →
  **custom** → `ns1.dns-parking.com` and `ns2.dns-parking.com` (check hPanel → Domains for the exact
  names it shows) → **Save**. It can take a few hours to a day.
  (If you later use Cloudflare, its nameservers replace these: see `cloudflare.md`.)

### C. Create the WordPress site
1. hPanel → **Websites → Add website → WordPress**.
2. Domain: `citycharcha.com`. Admin e-mail: company address. Admin username: **not** "admin"
   (it is blocked, Task 17). Admin password: long, from the company password manager.
3. If offered extra plugins, themes or an AI builder: **skip** all of them.

### D. HTTPS
hPanel → **Websites → citycharcha.com → Security → SSL** → install / check the free SSL is
**Active** → turn on **Force HTTPS**.

### E. E-mail mailboxes
hPanel → **Emails → citycharcha.com → Create email account**:
- `no-reply@citycharcha.com` (the site sends from this; password into the password manager),
- `officer@citycharcha.com` (Grievance Officer), and any others you want (e.g. `editor@`).

### F. Let me in (SSH key, no passwords)
1. hPanel → **Advanced → SSH Access** → **Enable**.
2. Note the SSH line it shows (like `ssh -p 65002 u123456789@1.2.3.4`) and tell me.
3. **SSH keys → Add SSH key**: paste the **public key** I give you (a public key is safe to share;
   it lets this computer in, and you can delete it any time).

Then tell me "server ready". I will not run anything until you say go.

## 3. What I run (with your OK), over SSH

```sh
# 1. our code onto the server (from this computer, or from GitHub with a read-only deploy key)
sh ~/citycharcha/scripts/deploy/update-code.sh ~/domains/citycharcha.com/public_html

# 2. every setting from Tasks 2-17, in order
cd ~/domains/citycharcha.com/public_html && sh ~/citycharcha/scripts/deploy/build-site.sh

# 3. production settings in wp-config.php
wp config set WP_ENVIRONMENT_TYPE production
wp config set DISALLOW_FILE_EDIT true --raw      # no code editing from wp-admin
wp config set DISABLE_WP_CRON true --raw         # the server's cron runs scheduled jobs instead
wp config set WP_DEBUG false --raw
wp config set FORCE_SSL_ADMIN true --raw
wp config set PORTAL_SMTP_HOST smtp.hostinger.com
wp config set PORTAL_SMTP_PORT 465 --raw
wp config set PORTAL_SMTP_USER no-reply@citycharcha.com
wp config set PORTAL_MAIL_FROM_NAME CityCharcha
```

Hostinger's own extra plugins (if any were installed) are listed and removed if they are not on the
approved list in CLAUDE.md.

**The mailbox password** is typed by **you**, never by me and never in chat:
hPanel → **Files → File Manager** → `public_html/wp-config.php` → **Edit** → add above
`/* That's all, stop editing! */`:
```php
define( 'PORTAL_SMTP_PASS', 'the no-reply mailbox password' );
```
→ **Save**.

### Cron (scheduled jobs: backups, PublishPress, spam clean-up, Breaking strip timing)
hPanel → **Advanced → Cron Jobs** → **Custom** → command:
```
cd ~/domains/citycharcha.com/public_html && /usr/bin/php wp-cron.php > /dev/null 2>&1
```
every **5 minutes** → **Save**.

## 4. After the site is live (owner)

| Step | Where | Guide |
|---|---|---|
| Google sign-in: add `https://citycharcha.com` and `https://citycharcha.com/wp-login.php?loginSocial=google` to the Google client, paste the Client ID/Secret into Nextend on the live site, **Publish app** | Google Cloud, wp-admin | `accounts.md` step E |
| Grievance Officer e-mail: `wp option update portal_grievance_email officer@citycharcha.com` (I can run it) | | `moderation.md` |
| Two-factor login for admins/editors, Wordfence firewall "Optimize" | wp-admin | `security.md` |
| Connect Google Drive backups, one test restore | wp-admin | `security.md` |
| PageSpeed mobile ≥ 80 | pagespeed.web.dev | `speed.md` |
| Search Console via Site Kit, submit sitemap | wp-admin | `seo.md` |
| Cloudflare (optional, recommended) | Cloudflare | `cloudflare.md` |
| Create editor accounts (Users → Add New, role Editor) | wp-admin | |

## 5. Checks I run on the live site (with your OK)

- `https://citycharcha.com` opens with the padlock; `http://` and `www.` go to it.
- Home, a section, the Write page, sign-up and the Grievance form work.
- `wp-admin → Settings → General`: address is `https://citycharcha.com`.
- A test sign-up e-mail arrives in a real inbox (not spam).
- Cron runs (`wp cron event list` shows jobs on time).
- `x-litespeed-cache` appears in page headers (LiteSpeed cache is working).
- Upload limit at least 10 MB per file (`wp eval "echo size_format( wp_max_upload_size() );"`), so reader
  tips can carry phone photos up to 8 MB (Task 17D, `tips.md`).
- The daily tips clean-up is scheduled (`wp cron event list` shows `portal_tips_cleanup`).

## Rehearsal (done locally before going live)

`build-site.sh` was run on a brand-new, empty WordPress (second Docker site on port 8090), 10 Nov 2026.
Final run: **all steps (Tasks 2-17) with no errors**, all 14 plugins active, and the rebuilt site showed
the CityCharcha title, menus, home layout, consent box, Write page, Grievance form, Rank Math titles,
structured data and sitemap, security headers, database version 6 with all portal tables.

Three problems that would have broken the live build were found and fixed on the way:

| Problem | Fix |
|---|---|
| A plugin download failed once for no lasting reason | `setup-plugins.sh` retries each download up to 3 times |
| On a fresh site Nextend Social Login redirects on its first load, which silently cut off the next command, so **portal-core was never activated** | portal-core is activated in the same command as the other plugins, then every plugin is checked |
| On a fresh site **Rank Math stays switched off** for visitors until "Skip registration" is clicked | `setup-seo.php` records that choice |

To repeat it:
```
$env:WP_PORT='8090'; $env:PMA_PORT='8091'; $env:MAILPIT_PORT='8026'; $env:WP_SITE_URL='http://localhost:8090'
docker compose -p ccrehearsal up -d db wordpress mailpit
docker compose -p ccrehearsal run --rm -T --entrypoint sh wpcli /scripts/deploy/build-site.sh
docker compose -p ccrehearsal down -v      # remove it afterwards
```
(On a PC with little memory, stop the main site first: `docker compose stop`.)
