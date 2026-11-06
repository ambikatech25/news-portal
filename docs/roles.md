# Roles and permissions

Code: `wp-content/plugins/portal-core/includes/roles.php` and `includes/restricted-categories.php`.
Test: `powershell -ExecutionPolicy Bypass -File tests\test-restricted-categories.ps1` (10 checks).

## Who can do what

| Our role | WordPress role | Write | Publish | Upload images | News / Crime / Case Files |
|---|---|---|---|---|---|
| Visitor | (not logged in) | – | – | – | read only |
| Member | Subscriber | – | – | – | read only |
| Writer | Contributor | yes | no — goes to **Pending review** | **yes** (added by portal-core) | **blocked** |
| Trusted writer | Author | yes | own posts | yes | **blocked** |
| Editor | Editor | yes | anyone's posts | yes | **allowed** |
| Admin | Administrator | yes | everything | yes | **allowed** |

Premium (Task 20) is a membership on top of any role, not a separate role.

## How the editor-only rule works

- Permission name: `portal_use_restricted_categories`. Editors and Admins have it.
  To let another role post in News, give that role this permission — no code change.
- Editor-only category slugs: `news`, `crime`, `case-files` (filter `portal_restricted_category_slugs`).
- **Layer 1 – REST API** (block editor, mobile app, any API client): a save that adds a
  restricted category is refused with HTTP 403 and the message
  "Only editors can publish in: News. Please choose a different category."
- **Layer 2 – safety net** (quick edit, XML-RPC, other plugins): a restricted category added
  by a user without permission is removed straight after; if nothing is left the post goes to
  Community Blogs.
- If an Editor puts an Author's post in News, the Author can still edit that post — the
  Editor's choice is kept.

## Known limits

- In wp-admin's block editor, writers still **see** the News/Crime/Case Files checkboxes;
  ticking one makes the save fail with the message above. Writers will normally use the
  front-end "Write a post" form (Task 11), which only lists allowed categories.
- Role permissions are stored in the database. They are applied when portal-core is activated
  or when `PORTAL_CORE_DB_VERSION` in `portal-core.php` is increased.

## Security note

Wordfence's "Disable application passwords" setting is ON (default) and should stay on.
The mobile app will use JWT login (Task 22) instead.
