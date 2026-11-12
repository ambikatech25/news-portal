# Series (Task 17A)

A **series** is a story told in parts: "Truck diaries", Part 1, 2, 3… Readers get a series
page with a contents list, "Part 2 of 3" under the headline, and **← Previous part / Next part →**
after each part.

Code: `portal-core/includes/series.php` (rules, data, API) and, in the theme,
`inc/series.php`, `template-parts/portal/series-nav.php`, `taxonomy-portal_series.php`.
Test: `tests/test-series.ps1`.

## For writers

1. **Write page** (`/write/`): at the bottom, the **Series** box. Pick one of your series, or
   choose **+ Start a new series…** and type its name. Leave "Not part of a series" for a single story.
2. Each story added gets the next part number automatically. It still goes to **Pending review**
   like every story; readers only see it once an editor publishes it.
3. **My Account → My series**: change the name or description, upload a **cover photo** (JPG, PNG
   or WebP, up to 2 MB, wide photos look best), **change the order** (type new numbers, Save),
   see which parts are still in review, delete a series (the stories stay published).

Limits: 30 series per writer; names 3–80 characters; description up to 600 characters.

## Rules (enforced in portal-core on every route: website, app/API, wp-admin)

| Rule | Why |
|---|---|
| Only writers (Contributor and up) create series; members cannot | Same as writing stories |
| Writers put only **their own** stories into **their own** series | Nobody can hijack someone else's series |
| Editors and Admins can manage every series | Moderation |
| A story is in at most one series | Clear "Part X of Y" |
| Readers see only **published** parts, numbered 1…N in order | A part waiting for review never shows as a gap ("Part 3 of 2") |
| A series has **no public page until one part is published** | An empty series (with an unreviewed description) is never public |

## For editors

- **wp-admin → Posts → Series**: every series, its name, description and address. Edit a name or
  description here if it breaks the Community Guidelines (series descriptions are not reviewed
  before they show; they only appear once a reviewed part is published).
- The block editor has a **Series** panel on each story, so an editor can move a story into or out of a series.
- Publishing, unpublishing or taking down a part updates "Part X of Y" on all other parts at
  once (LiteSpeed Cache is told to refresh those pages on the live site).

## Addresses and Google

- Series page: `https://citycharcha.com/series/<name>/`. Renaming a series keeps the old address,
  so shared links keep working. Two writers can both use the same name; the second one gets
  `<name>-2` as its address.
- Series pages are in the sitemap (`setup-seo.php`); empty ones are left out and marked noindex.

## For the app (REST API)

| Call | Who | What |
|---|---|---|
| `GET /wp-json/portal/v1/series/<id>` | anyone | Name, description, cover, writer, published parts in order (`part` = 1…N) |
| `GET /wp-json/portal/v1/me/series` | writer | Own series with every part and its status |
| `POST /wp-json/portal/v1/me/series` | writer | Create (`name`, `description`) |
| `POST /wp-json/portal/v1/series/<id>` | owner, editor | Change `name`, `description`, `order` (story IDs in reading order) |
| `DELETE /wp-json/portal/v1/series/<id>` | owner, editor | Delete (stories stay) |
| `POST` / `DELETE /wp-json/portal/v1/series/<id>/cover` | owner, editor | Upload (`cover` file) / remove cover |
| `POST /wp-json/portal/v1/stories/<id>/series` | who can edit the story | Put it in a series (`series` = ID, or 0 for none); clear error if not allowed |
| `portal_series` on `/wp/v2/posts` | anyone | `{id, name, link, number, total, prev, next}` or `null` |

Also standard: `/wp/v2/series` (list of series) and the `series` field on posts.
