# Sign-up, login and My Account

Code: `portal-core/includes/accounts.php` (consent, consent record, login redirect, My Account
menu, photo box), `includes/local-mail.php` (local e-mail), theme `inc/account.php` +
`assets/css/login.css` (look of the login page, header link).
Settings: `scripts/setup-accounts.php`.
Test: `powershell -ExecutionPolicy Bypass -File tests\test-accounts.ps1` (11 checks).

## How it works for a reader

| Step | What happens |
|---|---|
| **Sign up with e-mail** | Header → *Sign in* → *Register*. Username + e-mail + tick "I am at least 18 and agree to the Terms of Use and Privacy Policy". WordPress e-mails a link to set a password, so only real e-mail addresses get an account. |
| **Sign up with Google** | *Continue with Google* button on the same pages (once the Google keys are in, see below). The first time, the reader must tick the same agreement before the account is made. |
| **New accounts** | Always **Member** (Subscriber). Writers are promoted by an admin (Task 11). |
| **After login** | Members land on **My Account** (`/account/`). Writers and staff go to wp-admin as before. |
| **My Account** | Profile photo, Edit Profile (name, e-mail, password), Change Password, Log out. |
| **wp-admin** | Members are sent back to the website and see no admin bar. Writers and staff keep both. |

**Consent record.** Every sign-up stores when the person agreed, how (e-mail or Google) and
which version of the Terms of Use and Privacy Policy pages they saw. Admins see it on the
user's screen in **Users → (user) → Consent**. Accounts made by an admin have no record.

**Bots.** The sign-up form has a hidden trap field that people never see; simple bots fill it
and are refused. If bot sign-ups still appear later, switch on Wordfence's free reCAPTCHA
(Wordfence → Login Security → Settings).

## Owner steps: Google sign-in (about 15 minutes)

Do this signed in with the **Ambika company Google account**, so the company owns it.
Tip: later add a second company person as Owner (IAM → Grant access), so access is never
lost if one person leaves.

### A. Create the project

1. Open **https://console.cloud.google.com** and sign in with the Ambika account.
2. At the top, click the **project picker** (next to "Google Cloud") → **New project**.
3. Project name: `News Portal` → **Create**. When it is ready, make sure it is selected in the project picker.

### B. Consent screen (what readers see when they choose Google)

4. Menu (☰) → **APIs & Services** → **OAuth consent screen** (it may be called **Google Auth Platform**) → **Get started**.
5. **App information:** App name = the portal's public name; User support email = a company address. **Next**.
6. **Audience:** choose **External**. **Next**.
7. **Contact information:** a company e-mail. **Next**, tick the agreement, **Create**.
8. Left menu → **Branding**: add Application home page, Privacy policy link and Terms of
   service link (the live site addresses once the domain exists; leave empty for now if there
   is no domain yet). **Do not upload a logo yet**: a logo makes Google review the app, which
   takes days. **Save**.
9. Left menu → **Data access**: nothing to add. The sign-in only asks for name, e-mail and
   photo, which need no Google review.
10. Left menu → **Audience** → **Test users** → **Add users** → add the Gmail address(es) you
    will test with → **Save**. (While the app is in *Testing*, only these people can use Google sign-in.)

### C. Create the keys

11. Left menu → **Clients** → **Create client**.
12. Application type: **Web application**. Name: `News Portal website`.
13. **Authorized JavaScript origins** → **Add URI** → `http://localhost:8080`
14. **Authorized redirect URIs** → **Add URI** → `http://localhost:8080/wp-login.php?loginSocial=google`
    (the exact address is also shown in WordPress under Settings → Nextend Social Login → Google → *Getting Started*; they must match exactly).
15. **Create**. A box shows the **Client ID** and **Client secret**. Click **Download JSON**
    and keep it in the company password manager: Google may not show the secret again.

### D. Put the keys into the website

16. In WordPress (logged in as admin): **Settings → Nextend Social Login** → click **Google** → **Settings** tab.
17. Paste **Client ID** and **Client Secret** → **Save Changes**.
18. Click **Verify Settings**. A Google window opens: choose a test-user account. You come back to WordPress.
19. Click **Enable** next to Google (Settings → Nextend Social Login, main list). The status turns green.
20. Test: log out → open **http://localhost:8080/wp-login.php?action=register** → **Continue with Google**.

Never paste the Client secret into chat, e-mail or git. It lives only in the WordPress settings
and the password manager.

### E. Before launch (Task 18)

- In the same Google client, add the live addresses: origin `https://<domain>` and redirect
  `https://<domain>/wp-login.php?loginSocial=google`.
- Fill in Branding with the live home page, Privacy Policy and Terms links, add `<domain>` under
  *Authorized domains*.
- **Audience → Publish app** (moves from *Testing* to *In production*) so everyone can sign in.

## Local e-mail (development only)

The local Docker setup includes **Mailpit**, which catches every e-mail the site sends:
open **http://localhost:8025** to read sign-up and password e-mails. Nothing reaches a real
inbox. On the live site, real e-mail sending is set up in Task 18.

## For the app (later)

The app will log in with a token (Task 22, JWT). Google sign-in in the app uses Google's
mobile SDK with the same Google Cloud project (separate Android/iOS clients, same consent screen).
The consent record and Subscriber role rules stay the same.

## Known limits

- Edit Profile (WP User Frontend, free) covers name, e-mail and password. Writers edit their
  **bio and website** in wp-admin (Users → Profile) for now.
- Display name: set from first and last name in wp-admin; members' names are never shown
  publicly anyway (no public profile for members, Task 9).
