# Plugins

All plugins are free, from WordPress.org, and installed by `scripts/setup-plugins.sh`.
Settings live in the **database**, not in git — this file is the record of what we chose.

Legend: **Task** = the TASKS.md task where the plugin gets fully set up. **Owner** = needs an account, key or decision from the owner.

| Plugin | Status | Why we have it | Task |
|---|---|---|---|
| PublishPress Planner | active | Editorial statuses, calendar, notifications | 13 |
| Nextend Social Login | active | "Sign in with Google" | 10 |
| WP User Frontend | active | Writers submit posts from the website, not wp-admin | 11 |
| Rank Math SEO | active | Titles, meta, sitemaps, news schema | 17 |
| LiteSpeed Cache | active | Page cache on Hostinger (does nothing on local Apache) | 17 |
| Wordfence | active | Firewall, malware scan, 2FA for staff | 17 |
| UpdraftPlus | active | Scheduled backups to Google Drive | 17 |
| Antispam Bee | active | Comment spam filter, no account, no data sent away | 12 |
| Polylang | active | Hindi + English | later |
| MailPoet | active | Newsletter / daily briefing | 21 |
| Fluent Forms | active | Grievance form, news tip form | 16 |
| Site Kit by Google | active | Analytics, Search Console, AdSense | 17–19 |
| EWWW Image Optimizer | active | Compress images, strip EXIF/GPS | 15 |
| OneSignal | **installed, OFF** | Browser push notifications | Owner |

## Notes per plugin

### PublishPress Planner
- Used for the **Content Board**, **Calendar** and **Editorial Comments**.
- After first activation it redirects admins to its calendar once — normal.
- Task 13 findings: this version (Planner 4.x) no longer includes custom statuses ("Changes
  requested" / "Rejected" are registered in portal-core), and its notification rules were switched
  off (they skipped e-mails as "duplicates"). Review e-mails come from portal-core. See `review.md`.

### Nextend Social Login
- Task 10: **Owner** creates the Google Cloud OAuth client and pastes Client ID/Secret into Settings → Nextend Social Login → Google (steps in `accounts.md`). Terms checkbox at Google sign-up is switched on by `scripts/setup-accounts.php`.
- New users must get the **Subscriber** role (WordPress default role is already Subscriber).

### WP User Frontend
- Task 10: provides the **My Account** page (`/account/`): Edit Profile, Change Password. Members cannot open wp-admin and see no admin bar (`admin_access` = edit_posts). Its own login/sign-up forms are NOT used (WordPress pages verify e-mail).
- Task 11: "Write a story" form (built by `scripts/setup-writing.php`), pages `/write/` and `/edit-story/`. Contributors end in Pending via portal-core, not the form setting (see `writing.md`).
- `portal-core` still enforces the category rule on the server side — the form alone is not trusted.

### Rank Math SEO
- Task 17: configured by `scripts/setup-seo.php` (no Rank Math account needed). See `seo.md`.
- News sitemap is a paid (Pro) feature: not used; ask before buying.

### LiteSpeed Cache
- Task 17: configured by `scripts/setup-speed.php`; only caches on LiteSpeed servers (Hostinger). See `speed.md`.
- **Task 21 must make sure Premium stories are never served from a visitor's cached copy** (cache must
  vary by membership, or Premium story pages are excluded).

### Wordfence
- Task 17: login limits, alerts and scans set by `scripts/setup-security.php`; "hide usernames" off.
- **Owner**: 2FA for Admins/Editors and the firewall "Optimize" step on the live site (see `security.md`).
- Free version is enough. Do not paste a licence key unless the owner buys Premium.

### UpdraftPlus
- Task 17: schedule set (database daily, keep 14; files weekly, keep 4; Google Drive).
- **Owner** connects Google Drive on the live site (see `security.md`).

### Antispam Bee
- No account needed. Task 12 (`scripts/setup-engagement.php`): trust approved commenters, keep spam 30 days
  (time to rescue mistakes) then delete, Gravatar and country checks off (no data to outside services). See `engagement.md`.

### Polylang
- Add languages English (default) + हिन्दी. Translating content is an editorial decision — set up when the owner wants Hindi pages.

### MailPoet
- Shows a welcome wizard in admin on first visit. Sending real email needs a sending service (MailPoet's free plan up to 1,000 subscribers, or Hostinger SMTP) — **Owner**, Task 18/21.

### Fluent Forms
- Task 16: **Grievance** form on the Grievance Officer page (built by `scripts/setup-moderation.php`;
  reference numbers, acknowledgement and audit log come from portal-core, see `moderation.md`).
- Not built yet: a **news tip** form (CLAUDE.md mentions it; no task in TASKS.md covers it).

### Site Kit by Google
- Task 17–19: **Owner** signs in with the company Google account; connect Analytics, Search Console, and later AdSense.

### EWWW Image Optimizer
- Free, no account, compresses on upload.
- **Remove Metadata** is ON (checked in Task 15: it strips GPS/camera data and turns phone photos upright).
  It trims up to 15 px off one edge when it turns a photo (it works in 16-px blocks); invisible on real photos.
- portal-core (`includes/image-safety.php`) is the safety net after it: if EWWW is off, busy or
  cannot run its tools on the live server, photos are still cleaned (or refused). See `blurring.md`.
- Do **not** enable its paid "Easy IO"/CDN options.

### OneSignal (installed, switched off)
- Without a OneSignal App ID it loads an empty script on every page and logs PHP warnings, so it stays off.
- **Owner**: create a free OneSignal account → add a Web app for the site URL → copy the App ID.
- Then: Plugins → OneSignal → Activate → paste App ID → Save.
