# Reader news tips (Task 17D)

Any signed-in member can **send news**. Editors check every tip privately and either turn it into
a News story or decline it with a reason. Members never publish into News or Crime themselves.

Code: portal-core `includes/tips.php` (form, rules, editors' screen, e-mails, API), theme
`inc/tips.php` (My tips, credit line, header link), `scripts/setup-tips.php` (creates the
Send news page; part of `build-site.sh`). Test: `tests/test-tips.ps1`.

## For readers

1. **Send news** in the header (or My Account → My tips → *Send news*). Visitors are asked to sign in or create an account first.
2. The form asks:
   - what happened (one line) and the details;
   - where (town or district) and when;
   - how they know: saw it / someone told me / have documents;
   - up to **4 photos** (JPG, PNG or WebP, 8 MB each);
   - optionally a **video link** (YouTube unlisted or Google Drive) and a **mobile number** (only editors see it);
   - **keep me anonymous** (the default) or **credit me by name**;
   - a tick: "true to my knowledge, I may share these photos".
3. They get an e-mail receipt with a reference (**TIP-123**), and later a second e-mail: *published* (with the link) or *not published* (with the reason).
4. **My Account → My tips** lists every tip they have sent: *Received* → *Being checked* → *Published* (with a link) or *Not published* (with the reason).

## For editors

**wp-admin → Reader tips** (the menu shows how many are new). Click a tip to open it: everything
the reader sent, who they are (name, e-mail), their phone number, and the photos.

| Button | What happens |
|---|---|
| **I am checking it** | The reader sees "Being checked" |
| **Write it up as a News story** | A **draft** in News is created in your name with the reader's text, place and date, and the photos (first one as the main picture). Check the facts, call the reader if needed, rewrite, blur faces/plates (`blurring.md`), then publish as usual. Sensitive stories still need a second editor. When it is published, the reader is told automatically, and the story ends with *"Based on a reader report."* (or *"Based on a report by <name>, a CityCharcha reader."* if they asked for credit) |
| **Decline and e-mail the reader** | Needs a short reason (at least 10 characters); the reader gets it by e-mail and in My tips |

Every step (tip received, checking, written up, published, declined) is in the **audit log**.

## Safety and privacy rules (built in)

| Rule | Why |
|---|---|
| Tips are never public: no page, no feed, no search, no public API | Unchecked claims must not leak |
| "Anonymous" = anonymous **to readers**; editors always see the sender | Editors must be able to check and contact them |
| Photos lose their GPS/camera data and get random file names | A photo's hidden data or file name can reveal where and when it was taken, and so the sender's home |
| Video **files** are not accepted, only links | Video files also hide the filming location, which we cannot remove on shared hosting |
| Phone numbers are shown only to editors, never in e-mails, and deleted **90 days** after the tip is closed (daily job); a declined tip's photos are deleted then too | Keep personal data only as long as needed (India's data protection law) |
| 5 tips a day per member, plus a hidden spam trap | Stops floods and bots |

## For the app (REST API)

| Call | Who | What |
|---|---|---|
| `POST /wp-json/portal/v1/tips` | signed in | Send a tip: `title`, `details`, `where`, `when` (YYYY-MM-DD), `how` (saw / told / documents), `video`, `phone`, `credit` (anonymous / name), `consent`, files `photos[]` → `201 {id, reference, state}`; `400` wrong input, `429` more than 5 a day |
| `GET /wp-json/portal/v1/me/tips` | signed in | Your tips: `reference`, `title`, `date_gmt`, `state`, `state_label`, `reason`, `story` (link when published). No phone number. |

## Live server note (Task 18)

The upload limit on the server must be at least **10 MB per file** (photos are up to 8 MB). The form
shows the real limit if the server's is lower. Locally this is set in `docker/php-uploads.ini`.
