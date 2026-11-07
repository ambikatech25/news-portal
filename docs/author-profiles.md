# Writer profile pages

Code: `wp-content/plugins/portal-core/includes/profiles.php` (URL, who has a page, photo,
followers, API), `includes/compat.php` (plugin bug workaround), theme `author.php` (layout),
`template-parts/portal/author-card.php` (card under articles), `style.css`.
Test: `powershell -ExecutionPolicy Bypass -File tests\test-author-profile.ps1` (11 checks).

## The profile address

Every writer has a public page at **`/writer/<their-name>/`**, e.g. `/writer/asha-verma/`.

- The address is made from the writer's **public name**, never from their login name.
  WordPress normally uses the login (`/author/portaladmin/`), which tells attackers half of
  what they need to break in. Old `/author/...` addresses no longer work.
- If the name cannot make a clean address (a Hindi name, or a name that is the same as the
  login), the address is `/writer/writer-<number>/`. When that writer later sets a proper
  public name, the address is updated once.
- A good address is **never changed again**, even if the writer renames themselves, so
  links people have shared keep working.

**Who has a page:** writers (Contributor, Author, Editor, Admin) and anyone with published
stories. Plain members do not: signing up to comment never puts a reader's name online.
Their address shows "Not found".

## What the page shows

Photo, **News desk** (Editors, Admins) or **Community writer** label, name, bio,
number of stories, followers, month joined, the writer's website (if set), then their
stories, 12 per page with page numbers.

The author card under every article now shows the follower count and a *View profile* link.

## How a writer edits their profile (for now, in wp-admin)

**Users → Profile**:

| Field | Shown as |
|---|---|
| *Display name publicly as* (set First/Last name or Nickname first) | Name on the profile, cards and bylines |
| *Biographical Info* | Bio |
| *Website* | Website link |
| **Profile photo** (new, near the bottom) | Photo everywhere: profile, author card, community blog cards, comments. JPG, PNG or WebP, up to 2 MB. Tick *Remove my photo* to go back to the default |

Then click **Update Profile**. A front-end "Edit profile" page for members comes in Task 10.
Without an uploaded photo, the Gravatar picture for the writer's e-mail is used.

## Followers

- Stored in the table `wp_portal_follows` (who follows, what, when). It is also built for
  following tags, which Task 12 adds.
- Each follower counts once. You cannot follow yourself, or a member without a profile.
- When an account is deleted, its follows and its followers are removed too.
- The **Follow** button and the follow/unfollow API come in **Task 12**; until then the
  count shows 0 for everyone.

## For the app (API)

| Request | Returns |
|---|---|
| `GET /wp-json/portal/v1/authors/<id>` | `id, name, slug, link, avatar, bio, website, desk ("news" or "community"), posts, followers, joined`. 404 if no public profile |
| `POST /wp-json/portal/v1/me/avatar` | Logged in. Multipart field `file` (JPG/PNG/WebP, max 2 MB) → `{ avatar }`. 400 with a clear message for wrong type or size |
| `DELETE /wp-json/portal/v1/me/avatar` | Logged in. Removes the photo → `{ avatar }` (Gravatar again) |
| `GET /wp-json/wp/v2/posts?author=<id>` | The writer's stories (built-in WordPress) |

## Plugin bug worked around

**WP User Frontend** (free, approved) has a bug in its draft-preview feature: on pages viewed
by a logged-out visitor it briefly treats *every* user as allowed to write posts. On a page
view nobody can actually write anything with it, but it made permission checks lie (a plain
member suddenly "had" a public profile). `includes/compat.php` undoes it for logged-out page
loads only. Remove that fix if a future version of the plugin repairs it; the test
`3. A plain member has no public profile` will show whether it is still needed.

## Known limits

- The local Docker server only accepts uploads up to 2 MB in total (PHP's default), which
  also limits editors' article pictures locally. Hostinger allows more; checked in Task 18.
- Photos keep their EXIF data (camera, location) until Task 15 strips it on upload.
