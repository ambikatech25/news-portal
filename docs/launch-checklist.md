# Launch checklist (Task 19)

One step at a time. **Owner** = you (clicks in wp-admin, hPanel, Google). **Dev** = done by Claude
over SSH, only with your OK. Tick each line when done. When everything is ticked, sign at the bottom.

Live site: https://citycharcha.com · wp-admin: https://citycharcha.com/wp-admin (login `acehitansh`)

## A. Protect the site first

| # | Step | Who | Time | Guide | Done |
|---|---|---|---|---|---|
| 1 | **Two-factor login** for your admin account (phone code + password); require it for Admins and Editors | Owner | 5 min | `security.md` step 1 | [x] 18 Nov |
| 2 | **Backups to Google Drive** (UpdraftPlus): sign in with the company Google account, run one backup | Owner | 10 min | `security.md` step 2 | [ ] |
| 3 | **Wordfence firewall at full strength** ("Optimize the Wordfence Firewall") | Owner | 3 min | `security.md` step 3 | [ ] |

## B. Sign-in and roles

| # | Step | Who | Time | Guide | Done |
|---|---|---|---|---|---|
| 4 | **Google sign-in on the live site**: add the live addresses in Google Cloud, paste the keys into Nextend on the live site, publish the Google app | Owner | 15 min | `accounts.md` steps D and E | [ ] |
| 5 | **Test every role on the live site** with temporary test accounts (Member, Writer, Trusted writer, Editor): sign-up, writing → Pending, editor-only sections blocked, review, sensitive two-editor rule, reports, tips. Test accounts and their posts deleted afterwards | Dev | 20 min | this file, "Role test results" | [ ] |
| 5b | **Admin e-mail on citycharcha.com**: create a mailbox (e.g. `desk@citycharcha.com`) → wp-admin **Settings → General → Administration Email Address** and **Users → Profile → Email** (WordPress e-mails a confirmation link to the new inbox: open it via Webmail) → then Dev points Wordfence alerts and UpdraftPlus reports to it (they still use the old Gmail) | Owner + Dev | 10 min | - | [x] 18 Nov |
| 6 | **Editor accounts** for the news desk (each with their own `@citycharcha.com` mailbox): Users → Add New → role **Editor** → they set up two-factor login at first sign-in | Owner | 5 min each | `security.md` step 1 (first switch on the 10-day grace period in Login Security → Settings) | [ ] |

## C. Legal (India)

| # | Step | Who | Time | Guide | Done |
|---|---|---|---|---|---|
| 7 | **Legal pages written by you / your lawyer** and pasted in (Pages → edit): Terms of Use, Privacy Policy, Community Guidelines, Editorial & Corrections Policy, About / Ownership. They now hold placeholder text (Privacy Policy holds WordPress's sample text) | Owner + lawyer | - | "What each legal page must cover" below | [ ] |
| 8 | **Grievance Officer named** on `/grievance-officer/`: name, designation, postal address, `officer@citycharcha.com`, "acknowledged within 24 hours, resolved within 15 days" (as your lawyer advises) | Owner | 10 min | `moderation.md` | [ ] |
| 9 | **Ministry of Information & Broadcasting**: digital news publishers must give their details to the Ministry under the IT Rules, 2021 (Part III), and follow the Code of Ethics and the grievance system. Ask your lawyer for the current form, deadline and whether to join a self-regulating body | Owner + lawyer | - | Reminder only | [ ] |

## D. Content, Google and money

| # | Step | Who | Time | Guide | Done |
|---|---|---|---|---|---|
| 10 | **First real stories** published (aim for 10-15 across sections) before announcing the site. Demo stories were never put on the live site (nothing to remove) | Editors | - | `writing.md`, `review.md` | [ ] |
| 11 | **Google Search Console** via Site Kit, then submit `sitemap_index.xml` | Owner | 10 min | `seo.md` | [ ] |
| 12 | **PageSpeed** mobile ≥ 80 on the home page and one story (after item 10) | Dev | 5 min | `speed.md` | [ ] |
| 13 | **Apply for Google AdSense** once there is real content (usually 20-30 good stories) and the legal pages are in; Site Kit → AdSense. Ads appear only after Task 19C builds the ad slots | Owner | 15 min | Site Kit | [ ] |
| 14 | *(Optional)* **Cloudflare** in front of the site (free; extra protection and speed) | Owner + Dev | 30 min | `cloudflare.md` | [ ] |

## What each legal page must cover (for your lawyer)

This is a list of topics, not legal text.

- **Terms of Use:** who runs CityCharcha (company name, address), age 18+, accounts and suspension,
  what users may post, the review before publishing, takedowns and reports, user content licence,
  Premium (₹99/month, ₹999/year, 7-day trial, auto-renewal, cancellation, refunds), limits of liability,
  governing law and courts.
- **Privacy Policy:** what we collect (account details, Google sign-in, comments, tips with photos
  and optional phone, reports, cookies, analytics), why, how long (e.g. tip phone numbers 90 days after
  closing), who receives it (Hostinger, Google, Razorpay later, e-mail service), GPS data removed from
  photos, user rights (access, correction, deletion), Grievance Officer contact. Written for India's
  Digital Personal Data Protection Act, 2023.
- **Community Guidelines:** no hate, threats, harassment, fake news, naming victims or minors, copyright
  copying, spam; how reports work; what happens to rule-breakers.
- **Editorial & Corrections Policy:** how news is checked (Verified badge, FIR details), second editor
  for sensitive stories, reader tips are verified before publishing, how corrections are shown,
  sponsored content always labelled "Sponsored", advertisers never influence news.
- **About / Ownership:** who owns CityCharcha (company, directors), contact address, the editorial team.
- **Grievance Officer:** name, designation, address, e-mail, response times, the complaint form (already there).

## Role test results

(filled in by the developer after item 5)

## Sign-off

Checklist complete and CityCharcha ready to announce.

Owner: ______________________  Date: ____________
