# Project: CityCharcha – Blog & News Portal (citycharcha.com)

> Name: **CityCharcha**. Domain: **citycharcha.com** (bought; goes live in Task 18).

## What we are building
A **news-style website with community blogs**, built on **WordPress** within a small budget (₹50k–1 lakh total).
- An editor-run **news desk**: logistics, industry, coal and steel, crime, and regional news for Chhattisgarh and central India.
- **Community blogs**: anyone can sign up, write and submit posts, and read others' posts.
- **Admins** control users, content, reviews and takedowns.
- One paid plan, **Premium at ₹99/month** (Razorpay on the web; in-app purchase in the future mobile app).
- The look is a **news site first** (headlines, sections, breaking strip, trending), with a lighter blog feel (author profiles, follows, comments).
- **Website only for now.** A mobile app (Flutter or React Native) comes later and will use the WordPress REST API, so everything must be API-ready.

## Tech stack (do not change without asking)
- WordPress (latest), PHP 8.2+, MySQL/MariaDB
- Local development: Docker (`docker compose`) with WP-CLI. Production: Hostinger (shared/cloud, LiteSpeed, SSH).
- Parent theme: Kadence or Blocksy (free). All customisation lives in our **child theme** `portal-child`.
- Our custom code lives in **one plugin**, `portal-core`.
- Approved free plugins: PublishPress (workflow), Nextend Social Login (Google sign-in), WP User Frontend (front-end posting), Rank Math (SEO), LiteSpeed Cache, Wordfence, UpdraftPlus, Antispam Bee, Polylang (Hindi + English), MailPoet (newsletter), OneSignal (browser push), Fluent Forms (grievance and tip forms), Site Kit by Google, an image optimiser that strips EXIF/GPS.
- Membership and subscription: ProfilePress (or an equivalent paid plugin) with Razorpay recurring billing. **Ask before installing any paid plugin.**
- Prefer settings and free plugins over custom code. Write custom code only when no approved plugin covers the need.

## Roles (WordPress roles)
| Our role | WP role | Rules |
|---|---|---|
| Visitor | (none) | Read free content |
| Member | Subscriber | Comment, like, follow, bookmark, report |
| Writer | Contributor | Submit blog posts for review only |
| Trusted writer | Author | Publish own blog posts directly |
| Editor | Editor | Review, edit, approve, Verified badge, News/Crime/Case Files |
| Admin | Administrator | Everything |

**Premium** is a membership level on top of any role.

## Content structure
- Categories: News, Logistics, Industry, Crime, Region, Community Blogs, Case Files (premium).
- **Editor-only categories:** News, Crime, Case Files. Contributors and Authors must never be able to post in them; enforce this in `portal-core`, not only in the UI.
- Custom post meta on posts (registered with `show_in_rest: true`):
  - `portal_verified` (bool)
  - `portal_fir_number` (string)
  - `portal_police_station` (string)
  - `portal_sensitive` (bool: crime, minors or sexual offence, which needs a second approval)
  - `portal_second_approver` (user ID)
  - `portal_is_premium` (bool)
- Verified badge: shown on the article and in cards when `portal_verified` is true, with the FIR number and police station.

## Publishing rules the code must enforce
1. Contributor posts always go to **Pending review**.
2. Posts with `portal_sensitive = true` cannot be published until `portal_second_approver` is set to a different Editor or Admin.
3. Uploaded images have EXIF/GPS data stripped.
4. Every post and comment has a **Report** button. Reports are stored and shown to admins.
5. Admin actions (approve, reject, takedown, ban) are logged.
6. Premium posts show a preview (the first ~150 words) plus a subscribe box to non-members. **Public-safety news and corrections are never premium.**

## Legal pages (create as pages; content is drafted by a human or lawyer)
Terms of Use, Privacy Policy, Community Guidelines, Editorial & Corrections Policy, Grievance Officer (contact and form), About / Ownership.

## App-readiness
- Expose all custom fields through the REST API.
- Add an authenticated REST endpoint `GET /wp-json/portal/v1/me` that returns user ID, role and Premium status.
- Store content as normal WordPress posts and fields. No content locked inside page builders.

## How to work (instructions for Claude Code)
- Work through `TASKS.md` **one task at a time**. Tick each task when it is done and tested, then stop and summarise.
- Test locally first. **Never change the production server without asking.**
- Use git. Commit after each task with a clear message.
- Never commit secrets. Keep keys in `.env` (git-ignored).
- PHP: follow WordPress Coding Standards; escape output, sanitise input, use nonces and capability checks; prefix everything with `portal_`; text domain `portal`, ready for Hindi.
- Never edit parent themes or third-party plugins directly.
- When a step needs the owner (buying, account sign-ups, API keys, admin clicks), stop and give exact click-by-click instructions.
- Explain things simply. The owner is not a developer.
