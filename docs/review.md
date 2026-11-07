# Review workflow (approve, request changes, reject)

Code: `portal-core/includes/review.php` (statuses, decisions, e-mails, API, list buttons),
`assets/js/review-panel.js` (Review panel in the editor). Settings: `scripts/setup-review.php`.
Test: `powershell -ExecutionPolicy Bypass -File tests\test-review.ps1` (11 checks).

## The life of a story

```
Writer submits ──► Waiting for review (Pending) ──► Approve ──► Published
                         ▲          │
                         │          ├──► Request changes ──► Changes requested ──► writer edits & resubmits ─┐
                         └──────────┼────────────────────────────────────────────────────────────────────────┘
                                    └──► Reject ──► Rejected (final)
```

| Status | Who sees it | Writer can edit? |
|---|---|---|
| **Waiting for review** (Pending) | Writer, editors | Yes |
| **Changes requested** | Writer, editors | Yes; saving/submitting sends it back to review |
| **Rejected** | Writer (read-only), editors | **No** (final) |
| **Published** | Everyone | Contributors' edits go back to review |

Changes requested and Rejected stories are never shown on the website.

## How an editor reviews

**E-mail:** every Editor and Admin gets *"Waiting for review: <title>"* with a link, when a story
is submitted or resubmitted (from the website form, the block editor or the app).

**Quick approve:** wp-admin → **Posts → Pending** → hover the story → **Approve & publish**.

**Full review:** open the story. The **Review** panel on the right opens by itself:

1. Read and, if needed, correct the story directly (your edits are saved before the decision).
2. Choose:
   - **Approve & publish**: published at once; the writer gets the link.
   - **Request changes**: type what to change (at least 10 characters) → **Send to writer**.
   - **Reject**: type the reason (at least 10 characters) → **Reject story**.
3. The writer gets an e-mail with your reason. **Review history** in the panel shows every decision,
   who made it and when.

Lists: wp-admin → Posts shows the counts *Changes requested (N)* and *Rejected (N)* at the top.

## What the writer sees

- An e-mail with the decision and the reason.
- In the editor (or My Account → Posts → edit), the Review panel shows the latest feedback in a
  yellow (changes) or red (rejected) box, plus the history.
- After making the changes they press **Submit for review** (block editor) or **Save changes**
  (website form): the story goes back to *Waiting for review* and editors are e-mailed again.

## PublishPress

PublishPress (free) no longer includes custom statuses (they moved to a separate plugin,
"PublishPress Statuses", not on our approved list), so the two statuses are registered in portal-core.

Its **notification rules are switched off** (`setup-review.php`): in testing they rendered story
titles as empty, so PublishPress treated every e-mail as a duplicate of the previous one and
skipped it ("very similar to another one sent less than 10 minutes ago"); its default rules also
e-mailed the admin on every save. portal-core sends the review e-mails instead.

PublishPress stays active for: **Content Board** and **Calendar** (all stories by status / date),
and **Editorial Comments** (private notes between editor and writer inside a story).

## Records

Each decision is stored on the story (`portal_review_log`: decision, reason, editor, time) and
announced as `portal_admin_action` (`post_approved`, `post_changes_requested`, `post_rejected`)
for the audit log in Task 16.

## For the app (API)

| Request | Returns |
|---|---|
| `POST /wp-json/portal/v1/review/<id>` | Editors. `{ decision: approve/changes/reject, note }` → `{ status, history[] }`. 400 no reason, 403 not an editor, 409 not waiting for review |
| `GET /wp-json/portal/v1/review/<id>` | Editors and the story's writer: `{ status, history: [{ decision, label, note, by, at }] }` |

## Notes for later

- **Task 14** adds the second approval for sensitive stories: *Approve & publish* will then refuse
  until a second editor has approved, with a clear message.
- **Live site (Task 18):** e-mails need real sending (SMTP) and a server cron job.
