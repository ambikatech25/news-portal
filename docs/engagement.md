# Engagement: comments, likes, saves, follows

Code: `portal-core/includes/engagement.php` (rules, storage, API), theme `inc/engagement.php`
(buttons, My Account tabs, comment Report button), `assets/js/engage.js`, `style.css`.
Settings: `scripts/setup-engagement.php` (comments, Antispam Bee).
Test: `powershell -ExecutionPolicy Bypass -File tests\test-engagement.ps1` (11 checks).

## What members can do

| Feature | Where | Notes |
|---|---|---|
| **Like** ♥ | Under every story, with the count | Once per member; press again to unlike |
| **Save** (bookmark) | Under every story | Private. Listed in **My Account → Saved** |
| **Follow a writer** | Author card under stories, writer's profile page | Follower count on card and profile. You cannot follow yourself |
| **Follow a tag** | Top of every tag page (`/tag/raipur/`) | |
| **Following feed** | **My Account → Following** | Who/what you follow (with Unfollow) and the 10 newest stories from them |
| **Comment** | Under every story | Members only. Visitors see "log in to post a comment" |
| **Report a comment** | Under every approved comment | Same Report form as for stories (Task 8); stored in `wp_portal_reports` |

Visitors see the Like, Save and Follow buttons too; pressing one takes them to sign in and back.

## Comments and spam

- **Members only** (Settings → Discussion → *Users must be registered and logged in to comment*).
- A member's **first comment waits for approval**: wp-admin → **Comments** → *Approve*. After one
  approved comment, their next comments appear at once.
- Comments with **2 or more links** wait for approval.
- WordPress refuses comments sent within 15 seconds of the previous one from the same person.
- Replies nest up to 3 levels. Pingbacks/trackbacks are off.
- **Antispam Bee** checks every comment on our own server (nothing is sent to outside services):
  a hidden trap field, typing-speed check, known spam patterns, IPs that sent spam before.
  Spam goes to wp-admin → Comments → **Spam** (rescue mistakes there) and is deleted after 30 days.
  The Gravatar check is off because it would send commenters' e-mail hashes to Gravatar.
- Editors can close comments on a single story (post sidebar → Discussion).

## Storage

- Likes and saves: table `wp_portal_marks` (who, like/bookmark, post, when). The like count is also
  kept on the post (`_portal_likes`, recounted on every change, so it cannot drift).
- Follows: table `wp_portal_follows` (Task 9), now also for tags.
- Deleting a story, a member or a tag removes its likes, saves and follows (counts are corrected).

## For the app (API)

| Request | Returns |
|---|---|
| `POST` / `DELETE /wp-json/portal/v1/likes/<post>` | `{ liked, likes }` |
| `POST` / `DELETE /wp-json/portal/v1/bookmarks/<post>` | `{ bookmarked }` |
| `POST` / `DELETE /wp-json/portal/v1/follows/author/<user>` | `{ following, followers }` (400 for yourself) |
| `POST` / `DELETE /wp-json/portal/v1/follows/tag/<term>` | `{ following, followers }` |
| `GET /wp-json/portal/v1/me/bookmarks` | Saved stories |
| `GET /wp-json/portal/v1/me/follows` | `{ authors: [profiles], tags: [{id, name, link}] }` |
| `GET /wp-json/portal/v1/me/feed` | Newest stories from followed writers and tags |
| `GET /wp-json/wp/v2/posts/<id>` | Also has `portal_likes` |
| `POST /wp-json/wp/v2/comments` | Comments (built-in WordPress; same members-only rule) |

All `portal/v1` actions need a logged-in member (401 otherwise); drafts and private stories
cannot be liked or saved (404).

## Known limits

- No notifications yet when a followed writer publishes (could be added with MailPoet or
  OneSignal later).
- Like counts are not shown on home page cards (only on the story), to keep cards clean.
