# Security and backups

Code: `portal-core/includes/security.php` (headers, XML-RPC off, real visitor IP),
`includes/profiles.php` (no Gravatar). Settings: `scripts/setup-security.php` (Wordfence, UpdraftPlus).
Test: `powershell -ExecutionPolicy Bypass -File tests\test-seo-security.ps1` (11 checks, with SEO).

## What is set up

| Protection | Setting | Why |
|---|---|---|
| Wrong passwords | 5 in 4 hours → locked out 4 hours (was 20) | Stops password guessing early |
| Password-reset requests | 5 → locked out | Stops reset-e-mail floods |
| Usernames `admin`, `administrator` | Blocked on sight | Bots try them first; nobody here uses them |
| Leaked passwords | Refused for everyone who can publish | Editors/Authors are the accounts attackers want |
| Login error message | Does not say whether name or password was wrong | Gives attackers nothing |
| Malware scan | Automatic (Wordfence free) | E-mails the admin if something is found |
| Security alerts | E-mailed to the site admin | |
| "Hide usernames" (author scan) | **Off** (since Task 17) | Profile addresses no longer contain logins (Task 9); the block stopped editors seeing story authors ("(No author)") |
| Security headers | No framing by other sites, no file-type guessing, limited referrer, no camera/mic/location, HTTPS-only once live | Standard browser protections |
| XML-RPC | Switched off | Old remote-control door, used for password attacks; the app uses the REST API |
| WordPress version | Not shown in page code | |
| Gravatar | Not used: built-in silhouette for people without a photo | Faster, and no e-mail codes sent to an outside company (DPDP) |
| Audit log | Task 16 | |

## Owner steps

### 1. Two-factor login for staff (do this on the live site, ~5 minutes per person)

Every Administrator and Editor should use a phone code as well as the password.

1. Install **Google Authenticator** or **Microsoft Authenticator** on your phone.
2. wp-admin → **Wordfence → Login Security** → tab **Two-Factor Authentication**.
3. Scan the QR code with the app, type the 6-digit code it shows → **Activate**.
4. **Download the recovery codes** and keep them in the company password manager
   (they let you in if the phone is lost).
5. Tab **Settings** → **Require 2FA for**: tick **Administrator** and **Editor** → **Save**.
   Each editor is then asked to set it up at their next login.

### 2. Backups to Google Drive (on the live site, after Task 18)

The schedule is already set: **database every day (14 kept), files every week (4 kept)**.

1. wp-admin → **Settings → UpdraftPlus Backups** → tab **Settings**.
2. Remote storage shows **Google Drive** (already chosen). Scroll down → **Save Changes**.
3. A box appears: click the link to **sign in with Google**. Use the **Ambika company Google
   account** → **Allow** → on the UpdraftPlus page click **Complete setup**.
4. Back in WordPress you see "you are now authenticated".
5. Tab **Backup / Restore** → **Backup Now** → wait → check Google Drive for a folder **UpdraftPlus**.

Test a restore once (on a copy, not the live site) before launch: a backup you never restored is a hope, not a backup.

### 3. Wordfence firewall at full strength (live site)

Wordfence → **Firewall** → if it says *"Basic WordPress Protection"*, click **Optimize the
Wordfence Firewall** → keep the suggested server setting → **Continue** → download the backup file it
offers → **Continue**. It then shows *"Extended Protection"*.

### 4. Behind Cloudflare

If you set up Cloudflare (see `cloudflare.md`): Wordfence → **All Options** → **General Wordfence
Options** → **How does Wordfence get IPs** → *Use the Cloudflare "CF-Connecting-IP" HTTP header* →
**Save**. portal-core asks Wordfence for the visitor's address, so report limits keep working.

## On the live server (Task 18)

- In `wp-config.php`: `define( 'DISALLOW_FILE_EDIT', true );` (no code editing from wp-admin).
- File permissions as Hostinger recommends; SSH key login.
