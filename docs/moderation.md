# Reports, takedowns, suspensions, grievances and the audit log

Code: `portal-core/includes/moderation.php` (Reports screen, take down, suspend),
`includes/audit-log.php` (log, screen, CSV), `includes/grievance.php` (grievance e-mails),
`includes/reports.php` (Report button storage, Task 8). Settings: `scripts/setup-moderation.php`.
Test: `powershell -ExecutionPolicy Bypass -File tests\test-moderation.ps1` (11 checks).

## Reports (Editors and Admins)

Readers press **Report** under a story or comment (Task 8 / 12). Editors get an e-mail for the
**first** report on an item (not for every report, to avoid floods).

**wp-admin → Reports** (the red number = items waiting):

- One row per reported story or comment, with the number of reports, the reasons and the
  readers' notes.
- Type a **reason** (required), then:
  - **Take down**: a story moves to the private status *Taken down* (not deleted: evidence is kept
    and it can be restored); a comment goes to the trash. The writer gets an e-mail with the reason.
    All open reports on that item are closed.
  - **Dismiss (keep up)**: the content stays; the reports are closed with your reason.
- Tabs **Taken down** and **Dismissed** show past decisions; **Restore story** puts a taken-down
  story back online (Sensitive stories still need their second approval).

## Suspending an account (Admins)

**Users** → hover the person → **Suspend** → type the reason → **Suspend account**.

- They cannot log in (password or Google) and see: *"This account has been suspended…"*.
- They are logged out everywhere at once (website, app).
- Their stories and comments stay; take them down separately if needed.
- **Reinstate** (same place) lets them log in again. Admins cannot be suspended this way.

## Grievances (India's IT Rules, 2021)

The **Grievance Officer** page (`/grievance-officer/`) has a complaint form (Fluent Forms):
name, e-mail, type of complaint, link, description, declaration.

On every complaint:

1. The person sees and receives by e-mail a **reference number** (e.g. `GRV-12`) and the promise to
   reply **within 15 days**.
2. The Grievance Officer gets an e-mail with all the details.
3. It is written to the audit log.

All complaints: **wp-admin → Fluent Forms → Entries → Grievance** (mark read, add notes).

**Owner to-do before launch:**
- Name the Grievance Officer and write the page text above the form (name, designation, address,
  e-mail, response times). The text must come from you / your lawyer.
- Set the officer's e-mail (until then the site admin e-mail is used):
  ```
  docker compose run --rm -T wpcli option update portal_grievance_email officer@citycharcha.com
  ```
- Reply to every complaint within 15 days and keep a note of the outcome in the entry.

## Audit log (Admins only)

**wp-admin → Reports → Audit log**: every admin action, newest first, filterable by action,
downloadable as **CSV** (for police or court requests).

Recorded: reports received and decisions (take down, restore, dismiss), suspensions and
reinstatements, grievances received, review decisions (approve / changes / reject), writer
requests, Sensitive approvals and unticking, stories trashed or deleted, comments approved /
marked spam / trashed by a moderator, role changes, deleted accounts.

There is no edit or delete button on purpose: a log that admins can quietly change is not evidence.

## For the app (later)

Moderation happens in wp-admin; the app only needs the existing endpoints:
`POST /wp-json/portal/v1/reports` (Report button, Task 8) and the grievance web page.

## Notes

- Visitors can send 10 reports an hour (Task 8). The tests reset this counter before running
  (`Reset-ReportLimit` in `tests/lib.ps1`), because all test runs come from one address.
- **Before launch (Task 19):** the local database holds test entries in the audit log, reports and
  Fluent Forms. Start the live site with empty tables (handled in the deployment steps).
