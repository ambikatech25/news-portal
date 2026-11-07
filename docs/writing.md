# Writing from the website

Code: `portal-core/includes/writing.php` (Pending rule, writer requests, Write page, API),
`includes/compat.php` (WP User Frontend jQuery fix), theme `style.css` + `inc/account.php` (Write link).
Settings: `scripts/setup-writing.php` (story form and pages).
Test: `powershell -ExecutionPolicy Bypass -File tests\test-write-post.ps1` (11 checks).

## The Write page (`/write/`, "Write" in the header)

| Who | What they see |
|---|---|
| Visitor | "Write for us" with **Sign in** and **Create account** |
| Member (Subscriber) | **Become a writer** request form, or the status of their request |
| Writer (Contributor) | The story form. Their story goes to **Pending review** |
| Trusted writer (Author), Editor, Admin | The story form. Their story is **published** when submitted |

**The story form:** Headline, Story (with *Insert Photo*), Main picture, Section, Tags.
Sections offered: Community Blogs, Industry, Logistics, Region. **News, Crime and Case Files are
never offered**, and are refused by portal-core even if someone forges the request
(the story then lands in Community Blogs).

Writers find their stories (pending and published) under **My Account → Posts**, and can edit
them there. A Contributor's edit to a published story sends it back to Pending review.

Admins get an e-mail for every new story ("New story from …"). The full review workflow
(request changes, reject with a reason) comes in Task 13.

**Editing the form later:** wp-admin → **User Frontend → Post Forms → Write a story**. Labels and
help texts can be changed there safely. Re-running `setup-writing.php` resets it to our version.

## Fill the form from a document (Word, text or ChatGPT)

Code: `portal-core/includes/story-import.php` + `assets/js/story-import.js`.
Test: `powershell -ExecutionPolicy Bypass -File tests\test-story-import.ps1` (11 checks).

Above the story form, writers see **"Already written it? Fill the form from a document"**:

1. **Download the Word template** (always lists the sections writers may use today).
   It has four labels: **HEADLINE, SECTION, TAGS, STORY**. Example text is in [square brackets]
   and is ignored if left in.
2. **Upload** the finished file: `.docx` (Word, Google Docs → File → Download → .docx) or `.txt`, up to 2 MB.
3. Or **paste** text from ChatGPT. *Copy a ChatGPT prompt* gives a ready prompt that makes ChatGPT
   answer in exactly this format.

The form fills itself: headline, story (keeps paragraphs, sub-headings, **bold**, *italic*, bullet
lists and links), section and tags. Then the writer checks everything, adds pictures and presses
**Submit story**. Nothing is submitted automatically.

| Situation | What happens |
|---|---|
| Labels in English or Hindi (शीर्षक, श्रेणी, टैग, कहानी), "Headline: text" or label on its own line | Recognised |
| No labels at all | First line becomes the headline, the rest the story |
| Pictures in the document | **Not copied.** A note asks the writer to add them with *Insert Photo* / *Main picture* |
| Section is News, Crime or Case Files | Not set; note "Only editors can publish in …" |
| PDF | Refused with: open it in Word or Google Docs and save as .docx (PDFs store text as positioned pieces, so headings and lists come out scrambled) |
| Fake or broken Word file, over 2 MB | Refused with a clear message |

**Safety:** only writers can import; the file is read in memory and **never stored**; Word files are
read without fetching anything from the internet and with a size limit on the unpacked text; all
imported text is cleaned (no scripts, no `javascript:` links). The app can use the same endpoint.

### "AI tools helped write this story"

The story form has a tick box for honest disclosure. It is stored as `portal_ai_assisted = "yes"` and
editors see a yellow note at the top of the **Editorial** panel: *check facts, names, numbers and
sources carefully*. It is **not shown to readers**. Whether to label AI-assisted stories publicly
is an editorial-policy decision for the owner (India's IT Rules are moving towards labelling
AI-generated content; add it to the Editorial & Corrections Policy).

## Becoming a writer

1. A member opens **Write** and fills in: what they want to write about (30–1000 characters), an
   optional link to something they wrote, and ticks the Community Guidelines.
2. Admins get an e-mail "Writer request from …".
3. **Admin:** wp-admin → **Users** → click **Writer requests (N)** at the top of the list.
   The *Writer request* column shows their reason and sample link.
   - Click **Approve**: they become a **Contributor** and get an e-mail with the Write link.
   - Click **Turn down**: they stay a Member and get a polite e-mail; they can ask again after 7 days.
4. Promoting a good Contributor to **Author** (publishes without review) stays a manual choice:
   Users → edit the person → Role → Author → Update User.

Only admins (permission `promote_users`) can approve. Every decision is announced for the audit
log that Task 16 builds.

## Rule: Contributors always go to Pending review

CLAUDE.md publishing rule 1, enforced in portal-core for **every** route (website form, block
editor, API, other plugins): if the person saving cannot publish, a "publish" becomes "pending".
It looks at the person saving, so an Editor approving a Contributor's story publishes it normally.

## For the app (API)

| Request | Returns |
|---|---|
| `GET /wp-json/portal/v1/me/writer-request` | `{ status: none/pending/approved/rejected, can_write, at, decided_at }` |
| `POST /wp-json/portal/v1/me/writer-request` | Body `{ reason, sample_url, agree: true }` → 201; 400 bad input, 409 already waiting, 429 too soon after a turn-down |
| `POST /wp-json/wp/v2/posts` | Writers submit stories (status pending for Contributors; editor-only categories refused) |
| `POST /wp-json/portal/v1/story-import` | Writers. Multipart `file` (.docx/.txt) or field `text` → `{ title, content, category, tags, warnings[] }` |
| `GET /wp-admin/admin-post.php?action=portal_story_template` | The Word template (anyone) |

## Plugin issue worked around

WP User Frontend's form prints small scripts that need jQuery before jQuery has loaded
(our theme does not use jQuery, so nothing loads it early). Result: "jQuery is not defined"
errors that can break the picture buttons. `compat.php` loads jQuery early **only on pages with its
forms** (Write, Edit story, My Account); news pages stay jQuery-free and fast.

## Known limits

- Pictures keep EXIF/GPS data until Task 15.
- Local uploads are limited to 2 MB by the local server (Hostinger allows more).
