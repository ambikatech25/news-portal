# Sensitive stories: two editors before publishing

Code: `portal-core/includes/sensitive.php` (rule, second approval, API), `includes/post-meta.php`
(second approver cannot be typed in), `includes/review.php` + `assets/js/review-panel.js` (panel).
Test: `powershell -ExecutionPolicy Bypass -File tests\test-sensitive.ps1` (11 checks).

CLAUDE.md publishing rule 2: a story with `portal_sensitive = true` (crime, minors, sexual
offence) cannot be published until a **second, different** Editor or Admin has approved it.

## How it works

1. An editor ticks **Sensitive** in the **Editorial** panel (post sidebar) and saves.
2. The **Review** panel shows a yellow box: *Sensitive story: two editors needed*.
   *Approve & publish* is greyed out with the reason.
3. A **second editor** opens the story and presses **Give second approval**.
   The box shows *Second approval by <name>, <date and time>*.
4. **Any other editor** (not the one who gave the second approval) can now publish:
   *Approve & publish* in the Review panel, or WordPress's own *Publish* button.

So at least two different people (besides the writer) have looked at every sensitive story.

| Who tries | What happens |
|---|---|
| An editor publishes with no second approval | Refused: *"This story is marked Sensitive. A second editor must give their approval first…"* (in WordPress: "Publishing failed. …") |
| The editor who gave the second approval tries to publish | Refused: *"You gave the second approval … so a different editor must publish it."* |
| The writer of the story (even an editor) tries to be second approver | Refused |
| Writers (Contributors/Authors) | Cannot give a second approval at all |
| Someone types a colleague's ID as "second approver" (API) | Refused: the field is read-only, only set by *Give second approval* |
| Tick Sensitive and press Publish in the same click | Refused |
| Any other route (Posts list "Approve & publish", plugins, scripts) | The story stays in *Waiting for review* |
| Sensitive is unticked | The second approval is cleared, and the untick is recorded for the audit log (Task 16), so nobody can quietly untick it to get round the rule |

**Withdraw:** the editor who approved (or an admin) can press **Withdraw my approval**.

**Already published stories:** the rule guards the step *into* published. Marking a story Sensitive
after it is live does not take it offline; editors should unpublish it first if needed.

## Records

`portal_second_approver` (editor's user ID) and `portal_second_approved_at` (time) on the story.
Announced as `portal_admin_action`: `sensitive_second_approval`, `sensitive_approval_withdrawn`,
`sensitive_cleared` (audit log, Task 16).

## For the app (API)

| Request | Returns |
|---|---|
| `POST /wp-json/portal/v1/review/<id>/second-approval` | Editors. Gives my second approval → review data. 403 own story / not an editor, 409 someone else already approved, 400 not sensitive |
| `DELETE /wp-json/portal/v1/review/<id>/second-approval` | The approver or an admin |
| `GET /wp-json/portal/v1/review/<id>` | Now includes `sensitive: { is, approver: {id, name, at}, can_approve, can_publish, message }` |

## Found while building

- WordPress keeps a refused "Publish" as an unsaved change. The Review panel now uses the
  story's saved status (it used to disappear after a refused publish) and resets the status
  before its own actions (otherwise "Give second approval" would retry the refused publish).
- Wordfence's "hide usernames" setting blocks `/wp/v2/users/<id>` for editors, so the block
  editor shows the author as "(No author)". Publishing is not affected; to be reviewed in Task 17.
