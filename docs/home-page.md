# Home page

Code: `wp-content/themes/portal-child/front-page.php` (layout), `template-parts/portal/` (story card,
breaking strip), `style.css` (design), `portal-core/includes/feeds.php` (breaking, trending, views).
Test: `powershell -ExecutionPolicy Bypass -File tests\test-home-page.ps1` (11 checks).

There is nothing to build by hand: the home page fills itself from published posts.

## What editors control

| Block | What decides it | How an editor changes it |
|---|---|---|
| **Breaking strip** (red, top of every page) | Posts with **Breaking news** switched on, published in the last 24 hours (newest 3) | Post sidebar → **Editorial** → *Breaking news*. It disappears by itself after 24 hours. No breaking posts = no strip. |
| **Top story** (big picture) | The newest post marked **Stick to the top of the blog** | Post sidebar → *Summary* → tick *Stick to the top of the blog*. Untick old ones. If none is ticked, the newest news-desk post is used. |
| **Latest** | Newest news-desk posts (not Community Blogs), excluding the top story | Publishing. 8 on desktop, 5 on phones. |
| **Trending** | Most-read posts of the last 7 days (topped up with the newest if there are fewer than 5) | Readers decide. |
| **Sections** | News, Region, Industry, Logistics, Crime: newest 4 each | The category chosen for the post. Empty sections are hidden. |
| **Community Blogs** | Newest 4 posts in Community Blogs, with the writer's photo and name | Approving writers' posts. |

Only Editors and Admins can switch on Breaking, Verified or Premium, or stick a post.
Writers cannot.

**Badges on cards:** ✓ Verified (Editorial → Verified) and Premium (Editorial → Premium).

**Pictures:** each story's *Featured image*. Stories without one still show, as text only.
The top story's picture loads first and every other picture loads as the reader scrolls
(keeps the page fast on phones).

## Reads (view counts)

- Counted when a reader opens an article, by a tiny script in the page. This still works
  when the live site serves cached pages.
- One count per reader per browser session; reloading does not add more.
- Drafts and private posts are never counted. Nobody can type in a number; only real reads change it.
- Visible in the API as `portal_views` on every post.

## For the app (API)

| Request | Returns |
|---|---|
| `GET /wp-json/portal/v1/breaking` | Breaking posts: `id`, `title`, `link`, `date_gmt`, `image`, `views` |
| `GET /wp-json/portal/v1/trending?per_page=5` | Trending posts (same fields), 1-20 |
| `POST /wp-json/portal/v1/views/<id>` | Counts a read, returns `{ id, views }` |
| `GET /wp-json/wp/v2/posts?sticky=true&per_page=1` | Top story (built-in WordPress) |
| `GET /wp-json/wp/v2/posts?categories=<id>&per_page=4` | A section (built-in WordPress) |

## Colours and header

Brand colours are saved as Kadence settings by `scripts/setup-design.php`, so they can be
changed later in **Appearance → Customize → Colors** without touching code:
red accent `#C8102E`, dark text, white background. The same script shows the site name in
the header on phones and sets the footer line to "© year site name".

## Demo content (local only)

To see the design with sample stories:

```
docker compose run --rm -T wpcli eval-file /scripts/demo-content.php add
docker compose run --rm -T wpcli eval-file /scripts/demo-content.php remove
```

It adds 26 sample stories with drawn pictures and 3 sample writers, all marked as demo,
and `remove` deletes exactly those. The script refuses to run anywhere except a local
development site, so it cannot touch the live site.

## Known limits

- With page caching on the live site (Task 17), a Breaking post can stay in the strip a
  little after 24 hours, until the cache refreshes. Publishing any post refreshes it.
- Writer photos: uploaded in Users → Profile → Profile photo (see `author-profiles.md`); otherwise Gravatar.
