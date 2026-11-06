# Custom post fields

Code: `wp-content/plugins/portal-core/includes/post-meta.php` (fields) and
`assets/js/editorial-panel.js` (block editor panel).
Test: `powershell -ExecutionPolicy Bypass -File tests\test-custom-fields.ps1` (10 checks).

## Fields

| Key | Type | Default | Public API | Meaning |
|---|---|---|---|---|
| `portal_verified` | boolean | false | yes | Checked by the editorial desk → Verified badge |
| `portal_fir_number` | string | "" | yes | FIR number shown with the badge |
| `portal_police_station` | string | "" | yes | Police station shown with the badge |
| `portal_is_premium` | boolean | false | yes | Premium post (preview + subscribe box for non-members) |
| `portal_breaking` | boolean | false | yes | In the red Breaking strip for 24 hours after publishing (see `home-page.md`) |
| `portal_sensitive` | boolean | false | **no** | Crime/minors/sexual offence → needs second approval (Task 14) |
| `portal_second_approver` | integer | 0 | **no** | User ID of the second Editor/Admin (Task 14) |

- **Who can change them:** only users with `portal_manage_editorial_fields` (Editors, Admins)
  who can also edit that post. Anyone else gets HTTP 403 when they try.
- **"Public API: no"** means the field is left out of normal responses and only appears with
  `?context=edit` for users allowed to edit the post.
- Text fields are cleaned on save (HTML and scripts removed).
- **`portal_read_minutes`** (read time) is also on every post in the API, read-only (see `article-page.md`).
- **`portal_views`** (number of reads) is also on every post in the API, but it is read-only:
  it is stored as protected meta `_portal_views` and only real reads change it.

## Using them from the app / API

Read (anyone):

```
GET /wp-json/wp/v2/posts/123
→ "meta": { "portal_verified": true, "portal_fir_number": "123/2026",
            "portal_police_station": "Civil Lines, Raipur", "portal_is_premium": false, ... }
```

Write (Editor, logged in):

```
POST /wp-json/wp/v2/posts/123
{ "meta": { "portal_verified": true, "portal_fir_number": "123/2026" } }
```

## Editor panel

In the block editor, Editors and Admins see an **Editorial** panel in the Post sidebar:
Breaking news, Verified (FIR number + police station appear when it is on), Sensitive (with a reminder
not to identify victims or minors), Premium. Writers do not see the panel.
The second approver is not in the panel; Task 14 adds the approval step.
