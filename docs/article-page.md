# Article page

Code: `wp-content/themes/portal-child/inc/article.php` (where each part goes),
`template-parts/portal/` (article-flags, article-actions, author-card, related, report-dialog),
`assets/js/article.js` (share, copy link, report form), `style.css` (design);
`portal-core/includes/article.php` (read time, related) and `includes/reports.php` (reports).
Test: `powershell -ExecutionPolicy Bypass -File tests\test-article-page.ps1` (11 checks).

We use Kadence's own single-post template and add our parts through its hooks, so Kadence
updates keep working.

## What a reader sees, top to bottom

| Part | What decides it | How an editor changes it |
|---|---|---|
| Category, headline, writer's photo and name, date | Kadence | The post itself. An **Updated** date appears when a story is changed after publishing. |
| **Premium** label, **read time** | Premium switch; length of the text (200 words a minute, Hindi counted correctly) | Post sidebar → **Editorial** → *Premium* |
| **✓ Verified** box with **FIR No.** and **Police station** | Shown **only** when *Verified* is on | Post sidebar → **Editorial** → *Verified*, then fill in FIR number and police station. An FIR number typed in without *Verified* is never shown. |
| Picture | The post's *Featured image*, under the headline | Post sidebar → *Featured image* |
| Article text, tags | Kadence | The post itself |
| **Share** buttons | WhatsApp, Facebook, X, Telegram, LinkedIn, Copy link. On phones: WhatsApp, the phone's own Share sheet and Copy link | Nothing to set |
| **Report** button | On every post | Nothing to set |
| **Author card** | Writer's photo, name, bio, number of stories. *News desk* for Editors/Admins, *Community writer* for everyone else | The writer's own profile (Users → Profile → *Biographical Info*) |
| **Related stories** | 4 newest posts from the same section, topped up with the newest overall | Choosing the right category |
| Comments | Kadence / WordPress | Settings → Discussion (Task 12) |

Kadence's own related-posts carousel, author box and Previous/Next links are switched off
(`scripts/setup-design.php`), because the parts above replace them.

**Share buttons** are plain links: no Facebook or Twitter scripts are loaded, so they
add no tracking and no page weight.

## Reports

- Anyone can report a post, logged in or not (India's IT Rules expect every user to
  have a way to complain).
- The reader picks a reason and can add up to 1000 characters of detail.
- Reports are stored in the database table `wp_portal_reports`: what was reported, the reason,
  the detail, the member's user ID if logged in, the time, and status `open`.
- For logged-out readers the IP address is **not** stored: only a scrambled code made
  from it with a site secret, enough to spot repeats but impossible to turn back into the address.
- The same reader reporting the same post again is counted once. Each reader can send at
  most 10 reports an hour.
- **Where admins see them:** the Reports screen in wp-admin arrives in **Task 16**
  (with e-mail alerts and the action log). Until then they are only in the database.

## For the app (API)

| Request | Returns |
|---|---|
| `GET /wp-json/wp/v2/posts/<id>` | Also has `portal_read_minutes` and `portal_views`, and the Verified fields in `meta` |
| `GET /wp-json/portal/v1/related/<id>` | 4 related posts: `id`, `title`, `link`, `date_gmt`, `image`, `views` |
| `GET /wp-json/portal/v1/report-reasons` | `[ { slug, label } ]`, the reasons to offer |
| `POST /wp-json/portal/v1/reports` | Body `{ object_type: "post" or "comment", object_id, reason, details }` → 201 new, 200 already reported, 400 bad input, 404 not found, 429 too many |

## Known limits

- The writer's display name comes from their profile. The admin account currently shows
  as "portaladmin": set a public name in **Users → Profile → Display name publicly as**.
- Writer photos come from Gravatar until profile photos are added (Task 9).
- Follow button and follower count on the author card come with Tasks 9 and 12.
- On the live site behind Cloudflare (Task 17), the report limit must read the visitor's
  real address from Cloudflare's header; this is noted in `reports.php`.
