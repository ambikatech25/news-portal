# Going live on Hostinger (Task 18)

Scripts: `scripts/deploy/update-code.sh` (put our theme + plugin on the server),
`scripts/deploy/build-site.sh` (run every setup script, Tasks 2-17, in order).

**How it works:** the live site is a fresh WordPress built by our setup scripts, not a copy of the
local database (which holds test accounts, test reports and demo stories). The result: a clean site
with exactly our settings. Stories, users and photos are then created on the live site.

**Rule (CLAUDE.md):** nothing on the live server is changed without the owner's OK.

## 1. Cost (bought 16 Nov 2026)

| Item | Price | Notes |
|---|---|---|
| **Hostinger "Unlimited" web hosting, 48 months** (bought) | **₹199/month = ₹9,552 + 18% GST ≈ ₹11,271** for 4 years (sale price) | Renews in Nov 2030 at ₹649/month. Daily backups, 50 GB NVMe, SSH + WP-CLI, unlimited websites and mailboxes (mailboxes free for 1 year: check their price before Nov 2027), CDN |
| Later if traffic grows: "Cloud Startup" | from ₹599/month | 4 GB RAM, 4 CPU. Upgrade in a few clicks, no rebuild |
| Domain | registered at Hostinger for **3 years** (Nov 2026) | Renewal due Nov 2029: keep auto-renew on |
| Free domain voucher (1 year) | ₹0 | Optional: `citycharcha.in`, redirected to citycharcha.com (brand protection) |
| SSL certificate, Cloudflare, all our plugins, Google Drive backups | ₹0 | |

The plan also hosts small company / landing pages (e.g. ambikairon.com) if wanted; they share its
storage and power, so keep them few and updated. Moving an existing site: check where its e-mail runs first.

Not needed yet: Premium membership plugin + Razorpay (Task 20, ask before buying), Google Workspace.

## 2. Owner steps (about 30 minutes)

### A. Buy the hosting (done 16 Nov 2026)
1. https://www.hostinger.com/in/web-hosting → **Website hosting** (not VPS) → **Unlimited** → 48 months →
   account with the **company e-mail** → pay.
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

## Live build (16 Nov 2026)

Server: `ssh -i ~/.ssh/citycharcha_hostinger -p 65002 u587481986@147.93.109.94` (key-only; the key
lives on the developer PC; remove it in hPanel → SSH Access to revoke). Site folder:
`~/domains/citycharcha.com/public_html`; our code copy: `~/citycharcha` (outside the public folder).

`build-site.sh` ran with no errors; all 14 plugins active; https, `www` and `http` redirects, security
headers and LiteSpeed cache (`x-litespeed-cache: hit`) confirmed from outside.

Found on the way (and fixed):

| Problem | Fix |
|---|---|
| Hostinger's installer named the admin login **admin**, which our Wordfence settings block | Login renamed (password unchanged) through WordPress with `wp eval-file`; set your own display name in Users → Profile |
| Hostinger pre-installs its own plugins (AI Assistant, Easy Onboarding, Reach, Hostinger) | Removed (not on the approved list). Its two must-use helpers (preview domain, auto-updates) stay |
| **Hostinger disables `proc_open`**, so WP-CLI commands that start another process fail: `wp rewrite structure`, `wp rewrite flush --hard`, `wp plugin delete`, `wp theme delete`, `wp db query` / `export` | `setup-base.sh` saves the permalink option directly and falls back to a normal flush. For deletes: deactivate, then remove the folder. For database work: `wp eval` with `$wpdb` |
| The server has no `WP_SITE_TITLE`, so the site name was saved empty | `setup-base.sh` falls back to "CityCharcha"; `setup-seo.php` re-run |
| WordPress's "Hello world!" post was kept (WordPress was already installed, so the clean-up was skipped) | Deleted |
| An upload deleted the old scripts before the new ones were unpacked | Always unpack into a new folder, then swap (see below) |

Finished on 17 Nov 2026:

| Item | State |
|---|---|
| Mailboxes | `no-reply@citycharcha.com` (site sender), `officer@citycharcha.com` (Grievance Officer) |
| `wp-config.php` | `WP_ENVIRONMENT_TYPE production`, `DISALLOW_FILE_EDIT`, `FORCE_SSL_ADMIN`, `WP_DEBUG false`, `DISABLE_WP_CRON`, `PORTAL_SMTP_HOST/PORT/USER`, `PORTAL_MAIL_FROM_NAME`; `PORTAL_SMTP_PASS` typed in by the owner (never in git or chat). Backup of the earlier file: `~/wp-config.backup-20261117-0636.php` |
| Cron | hPanel → Cron Jobs, Custom, `*/5 * * * *`: `/usr/bin/php /home/u587481986/domains/citycharcha.com/public_html/wp-cron.php` (proven: a test job ran with WordPress's own scheduler off) |
| E-mail | Test e-mail from the site reached the officer inbox (not spam). DNS: SPF `include:_spf.mail.hostinger.com`, DKIM `hostingermail-a/b/c`, DMARC `p=none`, MX `mx1/mx2.hostinger.com` |
| Grievance e-mail option | `officer@citycharcha.com` |
| Account | login `acehitansh` (Administrator), display name "CityCharcha Desk", profile `/writer/citycharcha-desk/` |

Updating code later (from the developer PC, Git Bash, project folder):
```sh
git archive --format=tar.gz -o cc.tgz HEAD wp-content/themes/portal-child wp-content/plugins/portal-core scripts
scp -i ~/.ssh/citycharcha_hostinger -P 65002 cc.tgz u587481986@147.93.109.94:cc.tgz && rm cc.tgz
ssh -i ~/.ssh/citycharcha_hostinger -p 65002 u587481986@147.93.109.94 \
  'set -e; rm -rf ~/cc-new && mkdir ~/cc-new && tar -xzf ~/cc.tgz -C ~/cc-new && rm -rf ~/citycharcha && mv ~/cc-new ~/citycharcha && rm ~/cc.tgz \
   && sh ~/citycharcha/scripts/deploy/update-code.sh ~/domains/citycharcha.com/public_html'
```
(Use a relative file name with `tar` in Git Bash: a `C:\...` path is read as a remote computer.)

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
