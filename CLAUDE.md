# Project: CityCharcha – Blog & News Portal (citycharcha.com)

> Name: **CityCharcha**. Domain: **citycharcha.com** (bought; goes live in Task 18).

## What we are building
A **news-style website with community blogs**, built on **WordPress** within a small budget (₹50k–1 lakh total).
- An editor-run **news desk**: logistics, industry, coal and steel, crime, and regional news for Chhattisgarh and central India.
- **Community blogs**: anyone can sign up, write and submit posts, and read others' posts.
- **Reader news tips**: any signed-in member can send news (what, where, when, photos); editors verify it and publish it as a News story credited to a reader report. Members never publish directly into News or Crime.
- **Admins** control users, content, reviews and takedowns.
- One paid plan, **CityCharcha Premium at ₹99/month or ₹999/year** with a 7-day free trial (Razorpay on the web; in-app purchase in the future mobile app). See "Premium and ads" below.
- The look is a **news site first** (headlines, sections, breaking strip, trending), with a lighter blog feel (author profiles, follows, comments).
- A **community reading experience** inspired by reading apps (ideas only, never another brand's look or name): series of posts, comfortable reading mode, a Community home with trending writers and editors' picks, reading lists.
- Income: **ads for free readers** (Google AdSense and direct local sponsors), sponsored posts, and Premium.
- **Website only for now.** A mobile app (Flutter or React Native) comes later and will use the WordPress REST API, so everything must be API-ready.

## Tech stack (do not change without asking)
- WordPress (latest), PHP 8.2+, MySQL/MariaDB
- Local development: Docker (`docker compose`) with WP-CLI. Production: **MilesWeb Premium WordPress hosting** (LiteSpeed, mPanel, SSH, WP-CLI). The domain citycharcha.com stays registered at Hostinger and points to MilesWeb.
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
- Categories: News, Logistics, Industry, Crime, Region, Community Blogs, Case Files (premium), Industry Intelligence (premium, added in Task 21).
- **Editor-only categories:** News, Crime, Case Files, Industry Intelligence. Contributors and Authors must never be able to post in them; enforce this in `portal-core`, not only in the UI.
- **Series:** community posts can belong to a series (Part 1, 2, 3…), Task 17A.
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
7. **No ads** for Premium members, and none on Crime or Sensitive stories. Ads are labelled "Advertisement"; paid posts are always labelled **"Sponsored"** and never appear in news sections without that label. Advertisers never influence news coverage.

## Premium and ads (agreed with the owner, 2026-11-12)
| Free for everyone | CityCharcha Premium (₹99/month, ₹999/year, 7-day trial) |
|---|---|
| All daily news, crime, region; public-safety news and corrections (never paid) | **Industry Intelligence**: weekly coal / steel / scrap price tracker, freight and rake updates, tenders digest (own reporting or permitted sources only) |
| Community blogs, series, comments, likes, follows | **Case Files**: in-depth investigations |
| Breaking strip and alerts | **7 am morning briefing** e-mail; **ad-free**; Premium badge; early access |

- Do not switch the paywall on until there is a month of Premium content ready.
- **Premium series (agreed 2026-11-12):** a writer can make a community series Premium (an editor approves). The **first 3 parts are always free**; Part 4 onward shows the ~150-word preview and a subscribe box to non-members. One CityCharcha Premium subscription unlocks every series (no per-writer subscriptions until phase 2). Paid-part reads are counted per writer (Writer of the Month now, revenue share in phase 2).
- **Trial:** the 7-day free trial starts only when the reader subscribes and sets up Razorpay autopay (UPI AutoPay or card); the first charge is on day 8 unless cancelled. One trial per person.
- Writers may mark a post Premium (an editor approves). **Writer subscriptions with revenue share are phase 2** (about 6 months after launch or 500 Premium members); until then a manual "Writer of the Month" reward.
- Ads: fixed slots in our own theme (not AdSense Auto ads), lazy-loaded so PageSpeed stays ≥ 80; each slot shows AdSense, a direct sponsor banner, or nothing (portal-core screen, Task 19C).

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
