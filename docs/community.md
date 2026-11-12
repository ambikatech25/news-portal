# Community page (Task 17C)

Address: **/community/**. It replaced "Community Blogs" in the main menu, and the home page's
Community block ("More →") goes there too. The full list of community stories is still at
`/category/community-blogs/` ("All →" on the page).

Code: portal-core `includes/community.php` (what goes in each part), theme `page-community.php`
(how it looks), `scripts/setup-community.php` (creates the page, sets the menu; part of
`build-site.sh`). Test: `tests/test-community.ps1`.

## What is on the page, top to bottom

| Part | Filled by | Rules |
|---|---|---|
| Title, intro, one button | The page's own text (wp-admin → Pages → **Community**: edit the intro there) | Button: **Write a story** (writers), **Become a writer** (members), **Sign up to write** (visitors) |
| **Editors' picks** | Editors | Up to 4 published community stories, most recently picked first. Hidden when there are no picks (editors see a short reminder instead). |
| **Trending writers** | Automatic | Up to 6 community writers, ranked by reads + likes (one like counts as 10 reads) on their community stories from the **last 30 days**; all-time numbers fill empty places. Suspended writers never appear. Worked out once an hour (sooner when a community story is published or a writer is suspended). |
| **New series** | Automatic | The 4 newest series with at least one published part, with cover (or the first part's photo), writer and number of parts |
| **Newest community stories** | Automatic | The 12 newest community stories, except those already shown as picks |

Only published community stories ever appear: no drafts, stories waiting for review, News or
Crime stories, or password-protected stories.

## For editors: picking stories

Two ways, both editors and admins only (writers cannot pick, not even their own stories):

1. **One click:** wp-admin → **Posts** → hover over a published community story → **Pick for
   Community**. Picked stories show "— Editor's pick" after the title; hover again and click
   **Remove from picks** to take it off.
2. **In the story:** the **Editorial** panel on the right → switch on **Editor's pick**.

Every pick and un-pick is written in the **audit log** (Reports → Audit log).
Tip: keep 2 to 4 picks and change them every few days, so the page feels alive.

## For the app (REST API)

`GET /wp-json/portal/v1/community` (no login needed) returns:

| Key | What |
|---|---|
| `picks` | Editors' picks: `id`, `title`, `link`, `date_gmt`, `image`, `views` |
| `writers` | Trending writers: the public profile (`id`, `name`, `link`, `avatar`, `bio`, `posts`, `followers`, ...) plus `score` |
| `series` | New series: `id`, `name`, `link`, `image`, `writer`, `parts_count` |
| `latest` | Newest community stories (same fields as `picks`) |
| `link` | Address of the Community page |

The pick itself is the `portal_editors_pick` field in `meta` on `/wp/v2/posts` (editors can change it).
