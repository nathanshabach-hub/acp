# Local deployment preparation (Phase H)

No hosted server changes, releases, hosted migrations, cron activation, or Git
commits are authorized by this implementation. Packaging below is a design,
not a built release. Existing local credentials have not been changed or rotated.

## Standalone judge registration fees

Deploy `src/Utility/LegacyEmail.php` with the judge registration workflow:
its view-builder compatibility proxy supports `setClassName()` used by the
activation email. Without it, account creation can succeed before email
rendering fails. Do not retry account creation for an already saved email;
use the existing account verification/support workflow to recover it.

The public judge account registration convention dropdown includes all current
season conventions within their judge registration window, not only conventions
whose names contain New Zealand or Indonesia. Existing closing-date overrides
remain in effect.

Admin **Add Season** and **Change Prices** now include **Judges Registration**.
The season list displays this fee. A blank price is unconfigured and blocks
new fee selections; an explicit `0` permits free registration with an auditable
zero-value invoice request. Existing seasons start unconfigured; existing
registrations and payments are not automatically billed.

Only verified standalone `Judge` accounts without a school pay this fee.
Supervisors acting as judges and school-linked judges keep their existing
judging flow, with no additional judge charge.

For standalone judges, **Convention Registrations > Register / Events** opens
the price structure first. Saving the selected fee opens judging-event selection.
The judging-event selection page has separate Pre-Judged Events and In-Person
Events accordions with independent search and pagination. They share the admin
judging list's pre-judging categories; all other events appear under In-Person.
Deploy `src/Utility/JudgingEventCategories.php`,
`src/Controller/ConventionregistrationsController.php`,
`templates/Conventionregistrations/judgesregisterconvention.php`, and
`templates/Admin/Conventionregistrations/judginglist.php` together.
Submitting events atomically saves those choices and creates a pending invoice
request in admin Transactions. Direct event URLs cannot skip fee selection.
The selected fee is a price snapshot; subsequent admin price changes do not
silently increase an already selected or submitted charge. A stale price
selection POST must be reviewed again.

Admin generates and explicitly emails the invoice using the existing transaction
detail workflow. The invoice has a dedicated judge registration charge, the
judge's full name, and their registered email. Judge Notifications can securely
open/download the sent invoice. Generating/sending the invoice does not confirm
payment or approve judge eligibility. Payment confirmation updates the judge
billing line but does not approve their registration.

Repeated saves reuse the same pending or paid request. Admin cancellation removes
the unpaid request, its judge line and saved invoice; a later event submission
creates a fresh request. Confirmed transactions retain the existing explicit
confirmed-deletion workflow.

Deploy together:
- `config/migrations/015_add_judge_registration_fees.php`
- `config/my_const.php` and `config/hosted_legacy.php`
- `src/Model/Table/ConventionseasonsTable.php`
- `src/Service/JudgeRegistrationBilling.php`
- `src/Service/TransactionInvoices.php` and `TransactionCancellation.php`
- `src/Controller/Admin/ConventionsController.php`
- `src/Controller/ConventionregistrationsController.php`
- `src/Controller/UsersController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `templates/Admin/Conventions/{addseason,changeprices}.php`
- `templates/Conventionregistrations/{judgepricestructure,judgesregisterconvention}.php`
- Both `templates/element/` and `templates/Element/` variants of
  `Conventionregistrations/{judgesregistrations,judgesremainingconventions}.php`,
  `Admin/Conventions/seasons.php` and `Admin/Transactions/index.php`
- `templates/Admin/Transactions/viewdetails.php`
- `templates/element/Admin/Transactions/invoice.php`
- `templates/Users/school_notifications.php` and `webroot/sw.js`

Migration 013 and earlier transactional-storage migrations must already be
installed. Back up the database, upload the files and run from
`/home/convention/public_html/acp_demo`:

```sh
ACP_ENV=demo ACP_CONFIG_FILE=/home/convention/acp-private/demo.php php config/migrations/015_add_judge_registration_fees.php
```

Migration 015 is additive and idempotent and clears the model schema cache.
It adds nullable decimal fee fields and the InnoDB `transactionjudges` table;
it creates no transactions or emails. Configure the fee for each convention
season after deployment. Existing hosted payment/demo safety restrictions remain
in place. Demo admin Transactions permits list viewing/filtering, GET transaction
details, invoice generation/sending, and PDF downloads independently of online
payment settings. POST transaction confirmation, cancellation, deletion and
other payment actions remain blocked. Deploy
`src/Middleware/EnvironmentSafetyMiddleware.php` for this narrowly scoped access;
no migration or payment configuration change is needed for it. Existing admin
authentication and invoice CSRF checks still apply. Demo invoice mail remains
captured or redirected to the configured test recipient by its existing transport;
it does not enable unrestricted delivery to real customer addresses.

Local regression: `php tests/manual/judge_registration_fees.php` uses rollback
fixtures and an in-memory email transport; no real user accounts are modified
and no external email is sent.

### Judge in-person event availability

Deploy `config/migrations/016_add_judge_schedule_gate.php`,
`src/Scheduling/JudgeScheduleGate.php`, `src/Utility/JudgingEventCategories.php`,
`src/Controller/AppController.php`,
`src/Controller/Admin/SchedulingtimingsController.php`,
`src/Controller/ConventionregistrationsController.php`, and
`templates/Conventionregistrations/judgesregisterconvention.php` together.
Also ensure the existing scheduling dependencies `src/Scheduling/TimetableLoader.php`,
`src/Scheduling/TimetableValidator.php`, `src/Scheduling/TimetableLedger.php`, and
`src/Scheduling/SlotPlanner.php` are deployed.
Back up the database and run the migration before deploying the code:

```sh
ACP_ENV=demo ACP_CONFIG_FILE=/home/convention/acp-private/demo.php php config/migrations/016_add_judge_schedule_gate.php
```

Migration 016 is additive and idempotent. Existing schedules must be run again
to unlock In-Person Events. All scheduling stages and the conflict check must
finish, at least one event must be scheduled, and all issues shown by Check &
Resolve Conflicts must be resolved. Clearing or rerunning the schedule locks the
section again; later timetable issues also lock it until resolved. Previously
saved in-person selections are retained while locked. Pre-Judged Events remain
available. Category 1 bookings that could not be placed do not have timetable
rows for conflict repair; adjust the scheduling setup and rerun the schedule if
the Category 1 unscheduled-bookings warning appears.

### Checklist upload book selector

Deploy `templates/Element/Conventionregistrations/packageregistration.php`,
`templates/element/Conventionregistrations/packageregistration.php`,
`templates/element/Conventionregistrations/packageregistration.ctp`,
the student and group upload templates in `templates/Eventsubmissions/`, and
`webroot/css/front/portal.css` with `templates/layout/home.php`. The embedded
upload form preserves its interactive book selector and falls back to a native
multi-select if the Select2 stylesheet is unavailable. Golden Apple Award U16
defaults to Proverbs when that book is available. No migration is required.

### Adult Scripture submissions

Back up the database, deploy `config/migrations/017_add_adult_scripture_submissions.php`,
then run:

```sh
ACP_ENV=demo ACP_CONFIG_FILE=/home/convention/acp-private/demo.php php config/migrations/017_add_adult_scripture_submissions.php
```

Deploy `config/routes.php`, `src/Service/AdultScriptureSubmissions.php`, `src/Controller/ScriptureController.php`,
`src/Controller/HomesController.php`, `src/Controller/Admin/ConventionsController.php`,
`src/Middleware/ScopedPublicationCsrfMiddleware.php`, `templates/element/user_left_menu.php`,
`templates/Scripture/index.php`, `templates/Scripture/submit.php`,
`templates/element/scripture_submission_form.php`, `templates/Element/scripture_submission_form.php`,
`templates/layout/home.php`, `webroot/css/front/portal.css`,
`templates/Admin/Conventions/scriptureawardslist.php`, and
`templates/Admin/Conventions/adultscripturecertificate.php`. On hosted deployments
the application must be able to create and write `adult_scripture_submissions`
under the configured private `storageRoot`; include this directory in backups.
Local installations use `logs/adult_scripture_submissions`. Uploaded files are
available only through authenticated download actions, not public URLs.

The selected convention must have the Open awards enabled. Registered supervisors
and approved judges can upload their own documents while submissions and
registration are open. Admins can download each submission and print its
certificate from Scripture Awards. Multiple uploads are retained separately.
The single upload button opens an award selector with the corresponding form.
Book choices use searchable checkboxes; selections remain checked when a
search hides them.
Golden and Christian awards start with their configured books ticked; Silver
Apple books are selected manually.
For local uploads up to 50 MB, start the development server from the repository
root with these PHP limits (the larger POST limit leaves room for form data):

```sh
php -d upload_max_filesize=50M -d post_max_size=55M -S 127.0.0.1:8000 -t webroot webroot/index.php
```

These startup options apply locally only; hosted PHP limits are configured
separately in cPanel.
Run `ACP_ENV=local php tests/manual/adult_scripture_submissions.php` locally.

## Mandatory supervisor agreement

After a school saves student assignments, the supervisor receives a personal
unread message in the Gmail-style Notifications list. Opening that row marks
the message read and displays its signable agreement inside the message detail,
not above the inbox. Each message displays only its own convention's agreement.
Read or archived messages can be reopened in their usual folders. The agreement
lists current named students and all four supervision/medical declarations.
Ticking the agreement fills their account's first and last name as the signature;
**Accept supervisor agreement** saves it. The server obtains the name itself:
schools and other supervisors cannot sign on their behalf. Acceptance records
the signer, timestamp, declaration version, exact declarations and named roster.
A changed roster must be reviewed before the initial acceptance can be saved.

Until acceptance, the five supervisor areas (Student Event Registration, Student
Grouping, Events of the Heart, View/Edit Event Submissions, Registration
Checklist) and their related direct actions, AJAX endpoints and print view are
blocked server-side. The sidebar links to Notifications while locked.
School/student access and actual judging workflows are unchanged; switching to
a Judge profile does not bypass the gate on supervisor-specific activities.
Acceptance lasts for that convention/season/year even when assignments change
or are removed and re-added. A different convention or convention year requires
its own acceptance.

Upload together:
- `config/migrations/014_add_supervisor_agreements.php`
- `src/Service/SupervisorAgreements.php`
- `src/Service/SupervisorAssignmentPolicy.php`
- `src/Service/SchoolNotifications.php`
- `src/Controller/AppController.php`
- `src/Controller/UsersController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `templates/Users/school_notifications.php`
- `templates/element/supervisor_agreements.php`
- `templates/element/user_left_menu.php`
- `templates/layout/home.php`
- `webroot/js/front/portal.js`
- `webroot/css/front/portal.css`
- `webroot/sw.js`

The old `templates/element/supervisor_notification_preview.php` is no longer used.
After uploading, run from `/home/convention/public_html/acp_demo`:

```sh
ACP_ENV=demo ACP_CONFIG_FILE=/home/convention/acp-private/demo.php php config/migrations/014_add_supervisor_agreements.php
```

Migration 012 and transactional registration tables must already be installed.
Migration 014 is additive and idempotent. It also creates pending agreements
and inbox notices for existing valid active assignments; it never signs for
anyone or sends external email. Assignments/acceptance fail explicitly if the
required storage is missing. Existing school workflows must be deployed with
the migration, since new assignment saves now create agreement notices atomically.
School saves, single/admin reassignments and reactivation use the shared
assignment policy. Unassignment/removal intentionally preserves acceptance.

Local regression: `php tests/manual/supervisor_agreements.php`.

## Bulk supervisor unassignment

Student Registration now has selection checkboxes, select-all/clear controls,
and **Remove selected from supervisors** with an explicit confirmation.
This only clears `teacher_parent_id`: students stay registered, with their
events, groups, submissions and billing untouched. Already unassigned students
cannot be selected. Pending sponsorship requests must be cancelled first.
The entire batch is rejected if any selected registration belongs to another
school/convention or has a pending sponsorship request.

The School-only `unassignsupervisors` endpoint accepts CSRF-protected POSTs,
validates ownership under transactional locks and redirects to the updated list.
Upload together (no new database migration):
- `src/Service/SupervisorAssignmentPolicy.php`
- `src/Controller/ConventionregistrationsController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `templates/element/Conventionregistrations/students.php`
- `templates/Element/Conventionregistrations/students.php` (existing wrapper)
- `webroot/css/front/portal.css`
- `templates/layout/home.php`

Local regression: `php tests/manual/bulk_supervisor_unassignment.php`.

## Notifications for all portal roles

The supervisor dashboard Notification button shows the current unread inbox
count and shakes continuously while that count is nonzero. Unread buttons use
a bright yellow background with dark text in both light and night mode,
including hover/focus states. Opening messages
marks them read; returning to the dashboard recalculates the count. Archived
messages are excluded, matching the inbox's Unread folder. Marking a message
unread enables the reminder again. Reduced-motion preferences disable the
shake but retain the count. Deploy `src/Service/SchoolNotifications.php`,
`src/Controller/UsersController.php`, `templates/Users/dashboard.php`,
`webroot/css/front/portal.css` and `templates/layout/home.php` together.

School, supervisor (`Teacher_Parent`, including the Judge profile), Judge,
and Student accounts share the Notifications inbox at `/users/notifications`.
Each active verified account sees only its own notices and can mark them
read/unread or archive/restore with CSRF-protected POST actions. Notifications
are linked in Workspace for every role. The supervisor preview is now replaced
by the mandatory personal agreement described above.
Sponsorship controls and invoice downloads remain School-only.

No new migration is needed if migration 012 is already applied. The legacy
`school_notifications.school_id` field now holds the recipient's `users.id`
for all roles (not the student's or supervisor's parent school ID). Existing
school notices are unchanged. Future successfully sent portal emails create
subject-only notices for matching active verified accounts; old emails are not
imported, and email bodies, credentials and attachments are not copied.
Accounts sharing a registered email each receive their own notice.

Deploy together:
- `src/Controller/UsersController.php`
- `src/Service/SchoolNotifications.php`
- `src/Mailer/PortalMailer.php`
- `templates/Users/school_notifications.php`
- `templates/element/supervisor_agreements.php`
- `templates/element/user_left_menu.php`

The former `templates/Users/notifications.php` preview is no longer used.
Local regression: `php tests/manual/school_notifications.php`.

## Portal night mode

The dashboard welcome banner reuses the public homepage's animated tiny-dot
effect, including pointer repulsion and reduced-motion support, in place of
the decorative ring. Deploy `templates/Users/dashboard.php`,
`webroot/js/front/particle-repulsion.js`, `webroot/css/front/portal.css`, and
`templates/layout/home.php` together for this effect.

The moon/sun button beside the sidebar workspace dot toggles night mode for signed-in portal
pages. The preference is saved in this browser (and synchronized across its
open tabs), not in the school account. Public pages and admin pages are unchanged.
Deploy `templates/layout/home.php`, `templates/element/portal_header.php`,
`templates/element/user_left_menu.php`,
`webroot/css/front/portal.css`, and `webroot/js/front/portal.js` together.
No database migration is needed.
Night-mode overrides also cover registration pricing, supervisor/student
pickers, student events, grouping/combined requests, submissions, checklist
status cards, and permission forms. These styles are scoped to signed-in
portal pages in dark mode; public, admin, and light-mode styling is unchanged.
Also deploy `templates/element/Conventionregistrations/packageregistration.php`
(and its legacy `.ctp` counterpart): checklist state colours now come from the
existing status classes rather than JavaScript inline light-mode colours.
Deploy `templates/element/Conventionregistrations/studentevents.php` too for
theme-aware school approval text.

## Transaction invoice generator

Invoice email notifications include **View Invoice** and **Download PDF**.
The school-only `/users/notificationinvoice/<notification ID>` endpoint checks
both notification ownership and the invoice transaction's school ownership on
every request. Only sent, still-existing invoices are available. Exact saved
invoice numbers in existing email-notice subjects are supported without a
migration or resend. Other email notices do not expose attachments. PDFs are
private/no-store and unavailable after transaction deletion.
Deploy `src/Controller/UsersController.php`,
`templates/Users/school_notifications.php`, `webroot/sw.js`, and
`src/Service/TransactionInvoices.php` together for school access.

Transaction Details includes an admin-only **Generate Invoice** accordion below
**Payment Review** (also available for confirmed payments without Payment Review).
The former invoice URL redirects to this accordion, expanded. Generation and
email forms post to the invoice action and return to the expanded accordion;
they never submit the separate payment confirmation form.
For pending/invoiced and confirmed transactions, an admin checks
the saved charges, enters the receiving bank details, then explicitly generates
an invoice. Download and email are separate actions; generation does not send
mail or change payment status.

The official supplied PDF design is preserved as a cleaned 180-DPI background
in `webroot/img/invoice-template-background.png`; sample values, PDF form fields
and JavaScript are not carried into the generated invoice. TCPDF adds selectable
invoice text. No new runtime PDF library or Python dependency is required.

Upload together:

- `config/migrations/013_add_transaction_invoices.php`
- `src/Service/TransactionInvoices.php`
- `src/Controller/Admin/TransactionsController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `templates/element/Admin/Transactions/invoice.php`
- `templates/Admin/Transactions/viewdetails.php`
- `webroot/img/invoice-template-background.png`

The existing `LegacyEmail`, `PortalMailer`, branded email layouts and school
notification storage (migration 012) must also be deployed for email and inbox
notices. TCPDF is already an application dependency.

Run migration 013 from the hosted project directory with its protected config:

```sh
ACP_ENV=demo ACP_CONFIG_FILE=/home/convention/acp-private/demo.php php config/migrations/013_add_transaction_invoices.php
```

This idempotent migration creates private InnoDB invoice snapshots; it does not
issue invoices or send email. Hosted Demo allows the explicit admin transaction
viewing and invoice-management routes described above; payment confirmation,
cancellation, deletion and other admin payment operations remain blocked.

Invoice numbers use `ACP-<issue year>-<unique invoice ID>`. Each transaction has
one saved invoice, issued on its generation date in Brisbane time and due
14 calendar days later. Repeated generation/download uses the same number,
dates, school details, bank details and charge snapshot. Invoice fields cannot
be edited after generation. Invoice numbers may have gaps after rolled-back
attempts. Snapshots are database records, not publicly downloadable files.

Amounts use saved student/supervisor line items, not current prices. Golden
Discounts are the saved student discounts. Arithmetic is performed in cents;
inconsistent line items or a mismatch with `final_amount_paid` explicitly block
generation. Mixed unit prices display “Varies”; subtotals remain exact. No new
tax is calculated and this is not labelled a tax invoice. Confirmed payments
display “Paid” with zero outstanding without changing the saved invoice total.
School/event registrations are unchanged.

Email requires a separate confirmed CSRF-protected admin POST and attaches the
generated PDF to the saved school email address. A changed school email blocks
sending until accounts reviews it. Resending is explicit. The last accepted
delivery time is recorded; Debug/Demo capture does not claim delivery or set
that time. External email and database updates cannot be made atomic: if an
operation fails after SMTP acceptance, review accounts before retrying to avoid
duplicate email. Invoices are not sent automatically on payment confirmation.

Permanent transaction removal also deletes its invoice snapshot via an InnoDB
foreign key cascade. It cannot recall downloaded/emailed PDFs or refund money.
Generation, sending, confirmation and deletion serialize on the transaction
row. No real invoices were issued during implementation tests.

Local checks: `php tests/manual/transaction_invoices.php`,
`php tests/manual/transaction_cancellation.php`,
`php tests/manual/admin_transactions.php`, and
`php tests/manual/legacy_email.php`. Invoice tests inspect actual PDF text using
`pdftotext`, use transactional fixtures and memory-only mail transports.

## School Notifications inbox

The school sidebar shows **Notifications** (`/users/notifications`).
**Sponsorship Requests** (`/users/crossschoolrequests`) is accessible from the
inbox, not the sidebar. The inbox has unread
highlighting, message details, read/unread controls, archiving/restoring, and
25-message pagination. Opening a row marks it read through a CSRF-protected POST;
GET requests do not change message state. Each school can access only its own
messages. The existing supervisor notification preview is unchanged.

Upload these files together and run migration 012 before using sponsorship
creation/cancellation:

- `config/migrations/012_add_school_notifications.php`
- `src/Service/SchoolNotifications.php`
- `src/Mailer/PortalMailer.php`
- `src/Utility/LegacyEmail.php`
- `src/Service/PasswordReset.php`
- `src/Controller/UsersController.php`
- `src/Controller/ConventionregistrationsController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `templates/Users/school_notifications.php`
- `templates/element/user_left_menu.php`
- `webroot/sw.js`

For hosted Demo, run from the project directory:

```sh
ACP_ENV=demo ACP_CONFIG_FILE=/home/convention/acp-private/demo.php php config/migrations/012_add_school_notifications.php
```

The idempotent migration imports existing pending sponsorship requests using
their original dates. It does not import historical emails. New sponsorship
requests and cancellations generate notices for both schools in the same
database transaction. Approval/decline workflows are still unavailable; no
message implies that sponsorship was approved.

Future portal emails to active, verified schools' registered email addresses
(To, Cc, or Bcc) create one subject-only email notice per school after transport
success. Email bodies, credentials, recipient lists and attachments are not
stored in the inbox. Users must check their actual email for full content.
Mail-server acceptance is not proof of delivery. Debug/Demo transports do not
create sent-email notices because no external email was sent. If inbox recording
fails after delivery, an explicit error is logged without retrying the email.
Mail sent outside this application's mailers is not captured.

Local validation: `php tests/manual/school_notifications.php` (transactional
fixtures and in-memory email transport), and `php tests/manual/legacy_email.php`.

## Invoice email format compatibility

### Cancelling accidental unpaid requests

Confirmed transactions have a separate **Delete confirmed** action. It requires
an admin session, a reason and an explicit permanent-deletion checkbox on a
CSRF-protected POST. This deletes the payment record (including its reference)
and student/supervisor billing line items atomically. It does not refund money.
The school can be billed again for those registrations; other payments remain
unchanged. Student registrations and event choices are not removed. There is no
undo. A removal entry with the admin ID and reason is written to the system log.
Unpaid cancellation still rejects confirmed payments: the separate confirmed
deletion action is required. Both actions serialize against payment confirmation
using the parent transaction lock; late callbacks cannot recreate deleted rows.
No existing confirmed transactions are automatically deleted during deployment.

Admin Manage Transactions provides **Cancel request** for unpaid requests
without a payment reference. The confirmation page requires a reason and an
explicit checkbox warning that deletion cannot be undone. Cancellation is a
CSRF-protected admin POST that permanently deletes the transaction and its
student/supervisor billing details. No cancelled transaction history is kept;
the admin ID and reason are recorded in the system log only.
Paid requests or requests with confirmed line items cannot be cancelled.

Cancelled items no longer count as already billed in the school payment
summary and may be submitted again. Student/event registrations are unchanged.
Late confirmations cannot revive cancelled records. Previously sent emails
cannot be recalled; coordinate separately with accounts if an invoice was
already issued. No automatic cancellation email is sent.
Confirmed and pending/invoiced requests remain visible. Legacy status-4
cancellations can be removed through the same service, subject to the same
payment-reference and confirmed-line-item checks; deployment does not
automatically purge hosted records.

Upload the following files together; no schema migration is required:

- `src/Service/TransactionCancellation.php`
- `src/Controller/Admin/TransactionsController.php`
- `src/Controller/TransactionsController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `config/my_const.php`
- `config/hosted_legacy.php`
- `templates/Admin/Transactions/cancelrequest.php`
- `templates/Admin/Transactions/viewdetails.php`
- `templates/Transactions/viewdetails.php`
- `templates/element/Admin/Transactions/index.php`
- `templates/Element/Admin/Transactions/index.php`

Cancellation requires the existing payment tables to use InnoDB. Local checks:
`php tests/manual/transaction_cancellation.php` and
`php tests/manual/admin_transactions.php`.
The existing hosted Demo environment policy blocks admin transaction routes;
this feature does not relax that payment-safety policy. Uploading these files
alone does not enable admin cancellation in hosted Demo.

Invoice requests require the HTML `admintemplate` email layout. The legacy email
wrapper now forwards the requested format to CakePHP's dynamically provided
`setEmailFormat()` method, rather than incorrectly skipping it and looking for
a nonexistent plain-text admin layout.

Upload `src/Utility/LegacyEmail.php` for this fix; no database migration is
needed. Run `php tests/manual/legacy_email.php` locally to check HTML invoice
rendering and plain-text compatibility using DebugTransport only.

HTML emails using `admintemplate` embed the existing Accelerate logo as an
inline PNG attachment with a matching Content-ID. The header does not require
access to localhost or a remote image URL. Other attachments are preserved and
plain-text emails are unchanged. For this logo fix upload
`src/Utility/LegacyEmail.php`, `templates/layout/Email/html/admintemplate.php`,
and `templates/Layout/Email/html/admintemplate.php`; ensure
`webroot/img/front/main-logo.png` is present. The lowercase
`templates/layout/email/html/admintemplate.php` shim uses the updated layout.
Previously received emails are unchanged; verify with a newly generated email.

The admin transactions listing also materializes dropdown queries before
calling `combine()`, as required by CakePHP 5. Listing filters and convention/
school associations are applied directly to the paginated query. Upload
`src/Controller/Admin/TransactionsController.php` for this listing fix; no
migration is required. Local read-only regression:
`php tests/manual/admin_transactions.php`.

## School approval of student event choices

Students save new choices for school review, not directly into live event
registrations. Pending choices appear in Student Event Registration with a
**Review choices** action for the student's own school. Assigned supervisors
have **Manage Events** and **Remove All**, matching the school action column.
Supervisor saves apply directly, including changes to already-approved choices,
recording the supervisor as the approving actor and unlocking student uploads.
They cannot use the school-only **Review choices** endpoint, but can manage the
displayed pending choices directly. Unassigned students and other schools'
registrations remain inaccessible. Supervisor agreement acceptance is still
required before any of these actions. A school
student-view session has student permissions and must return to school before
reviewing choices.

The review page lists the saved choices and requires confirmation. Approval
registers exactly the reviewed revision, displays a green approved tick, and
unlocks individual/group uploads. Students can revise pending choices; a stale
approval fails rather than approving unseen changes. Approval locks choices for
students; the school or assigned supervisor can change them. Normal edits still
respect registration dates; a school may approve an already-submitted selection
after registration closes. Submission locks continue to apply.

Existing nonempty event registrations remain approved without backfilling or
requiring another review. Pending choices do not create event entries,
automatic submissions, group membership, or scheduling entries. Checklist and
My Events explain pending review. Direct student upload URLs enforce approval,
event enrollment and actual group membership. Legacy add/edit event routes
redirect to Manage Events; Remove All is a CSRF-protected POST restricted to the
school or assigned supervisor, subject to registration dates and combined-team
membership protections. Deploy `src/Service/StudentEventSelections.php`,
`src/Controller/ConventionregistrationsController.php`,
`templates/Conventionregistrations/managestudentevents.php`, and both case-variant
`templates/element/Conventionregistrations/studentevents.php` /
`templates/Element/Conventionregistrations/studentevents.php` together.

Open individual events are hidden and rejected on save/approval below age 16,
or below age 17 for Physical Education. Age is convention year minus birth year.
Individual PE U17 events are restricted to ages 14-16, so under-14 students
cannot see or save U17 or Open individual PE choices.
This restriction does not change team-event eligibility or remove existing
approved registrations. Older-teammate validation and the remaining entry-rule
differences are separate from this individual-event restriction.

Before deploying, back up the Demo database and put the site into maintenance
while uploading these application files:

- `config/migrations/011_add_student_event_reviews.php`
- `src/Service/StudentEventSelections.php`
- `src/Controller/ConventionregistrationsController.php`
- `src/Controller/EventsubmissionsController.php`
- `src/Controller/AppController.php`
- `src/Controller/UsersController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `templates/Conventionregistrations/reviewstudentevents.php`
- `templates/Conventionregistrations/managestudentevents.php`
- `templates/Users/myevents.php`
- `templates/element/Conventionregistrations/studentevents.php`
- `templates/Element/Conventionregistrations/studentevents.php`
- `templates/element/Conventionregistrations/packageregistration.php`
- `templates/Element/Conventionregistrations/packageregistration.php`
- `webroot/sw.js`

Both case-sensitive element directories are listed deliberately. This update
requires migration 011, in addition to the previously deployed student account
and combined-team storage. Run from cPanel Terminal:

```bash
cd /home/convention/public_html/acp_demo
ACP_ENV=demo ACP_CONFIG_FILE=/home/convention/acp-private/demo.php php config/migrations/011_add_student_event_reviews.php
```

The migration is repeatable and verifies transactional InnoDB storage. It does
not reset student credentials or change existing event registrations. Restore
normal site access only after it succeeds.

Local regression checks (not for execution against hosted data):

```bash
php tests/manual/student_event_reviews.php
php tests/manual/student_portal_accounts.php
php tests/manual/combined_teams.php
node tests/manual/student_bulk_export.js
```

The new review test uses transactional fixtures and rolls them all back; it does
not alter real user accounts, passwords, or event choices.

## Student ID and password enrollment

Students in a school's Global Students List receive a unique random six-character
Student ID. This is separate from the legacy billing/customer code. A convention
registration is not required for authentication. Existing IDs are backfilled;
new ORM-created students receive an ID automatically. Legacy students without
a school are reported by the migration and cannot enroll until assigned to a
school. Inactive, archived, unverified students and students of inactive or
unverified schools cannot enroll or sign in.

The Account column is between Status and Action. It shows the Student ID and a
red cross until the student's first successful password login, then a green
tick. Creating a password does not mark the account as having signed in.

Only the student's active school account can issue a setup/reset code through
Account > Setup / reset code. Supervisors can view their own school list but
cannot issue codes. Issuance is a confirmed, CSRF-protected POST and shows the
code only in that response; copy it and give it privately to the student.
Codes expire after seven days, are stored as hashes, and can be issued at most
once per minute per student. A replacement revokes any previous code.

The public Student Password Setup page requires both ID and single-use code,
plus matching passwords (at least six characters, at most 72 UTF-8 bytes).
Redemption is atomic and works only once, including concurrent attempts. A
student then selects Student on Login and signs in with ID and password.
ID alone, surname prefixes, birth dates and customer codes cannot authenticate.
The passwordless `logintotest` shortcut is disabled for all accounts so it cannot
bypass school authorization to issue codes.

Students can change their password through Edit Profile > Change password by
supplying their current password. If forgotten, the school issues a new
setup/reset code. Issuing that code does not change the existing password;
redeeming it replaces the password and invalidates older student sessions.
Password changes also revoke older sessions while preserving the session
making the change. Legacy student sessions must sign in again. Account ticks
record historical successful login and remain green after a password reset.
No email delivery is required for this school-issued flow.

### School access to a student portal

The Action column on the active Global Students List includes **Open portal**
for active, verified students. This is a confirmed, CSRF-protected POST, not
a public/passwordless login URL. Only the logged-in active school can open its
own students. Supervisors and students cannot use it, and nested student-view
sessions are rejected.

The school sees the student's portal and can make normal student changes,
including profile edits. A persistent **Viewing as student** banner explains
that changes are saved and provides a POST **Return to school** button. Password
changes, student password setup/reset and alternate sign-in routes are blocked
server-side while viewing. Password links are hidden. The school does not learn
or change the student's password by entering this mode.

This mode works before a student has created a password, without changing
first-login/account indicators, last-login timestamps, credentials or setup
codes. Each student-view request rechecks both accounts' eligibility and current
school ownership. Invalidated access requires sign-in again; Return to school
can restore the active school even if the target student has since been
archived. The original school convention/profile context is restored on return.
Logout ends the session rather than returning to school. Opening/closing access
is logged with school/student numeric IDs. Sessions are renewed on entry/return.

All student-view pages use no-store responses; forms in this mode receive CSRF
protection. Dashboard/schedule/event pages and the entry/return endpoints are
excluded from service-worker caching, and navigation caching respects no-store.
The viewing role applies to the browser session, so other tabs sharing the
session also view as this student until the school returns.

Deploy these files together; no database migration is needed:

- `src/Service/StudentPortalAccounts.php`
- `src/Controller/AppController.php`
- `src/Controller/UsersController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `templates/element/Users/students.php`
- `templates/Users/students.php`
- `templates/Users/editprofile.php`
- `templates/element/user_left_menu.php`
- `templates/element/portal_header.php`
- `templates/element/school_student_access_banner.php`
- Both `templates/layout/home.php` and `templates/Layout/home.php`
- `webroot/sw.js`

School-access regressions are included in
`ACP_ENV=local php tests/manual/student_portal_accounts.php`.

### Bulk setup codes

The Global Students List now offers two school-only actions:

- Select students on the current active-students page and choose **Setup codes
  for selected**. The review page warns when selected students already have a
  password; issuing codes does not replace those passwords until redemption.
- Choose **Set up all active students without a password** to include all
  eligible school students across every page. This excludes students who have
  created a password, even if they have not yet signed in.

Both actions show a review and require confirmation before issuing individual
codes. Existing IDs stay unchanged. Only active, verified students of the
logged-in active school are eligible. Supervisors cannot issue codes. If any
selected student is ineligible or throttled, the entire batch rolls back and
previous codes remain valid. Issuance rechecks eligibility and password state.

The response shows the student name, ID, individual code and expiry in server
time, with **Print list** and **Download CSV** actions. These use the already
issued response locally without another issuance request. No raw codes are
stored in the database, session, local storage or a server-side export file.
Keep the list private, give each student only their own details, and securely
delete downloaded copies after distribution. Codes cannot be retrieved after
leaving the result; reissuing generates replacements. Do not refresh or
resubmit the POST result to print/download. Printing excludes portal navigation
and repeats column headings; CSV exports escape spreadsheet formula-like names.

For an existing deployment with migration 010 already applied, upload these
files together; **no additional migration is needed**:

- `src/Service/StudentPortalAccounts.php`
- `src/Controller/UsersController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `templates/Users/students.php`
- `templates/element/Users/students.php`
- `templates/Users/bulkstudentaccess.php`
- `webroot/sw.js`

Bulk regression is included in
`ACP_ENV=local php tests/manual/student_portal_accounts.php`, using 101 eligible
students plus ineligible and foreign-school fixtures. CSV/print-action checks:
`node tests/manual/student_bulk_export.js`. These require no new dependencies.

Student login and setup share persistent throttling: ten attempts per Student
ID and thirty per client IP in a fifteen-minute window. These are fixed windows
starting with the first attempt, not rolling windows. Credential pages use
no-store responses and are excluded from service-worker caching. Password
storage and code consumption use dedicated InnoDB tables; the legacy MyISAM
users table is not assumed to be transactional. If ID allocation fails after
a user save, the error explicitly reports the saved student and failed portal
allocation; rerun the migration to repair missing IDs rather than recreating
students.

Deploy together during a maintenance window, after a database backup:

- `config/migrations/010_add_student_portal_accounts.php`
- `src/Service/StudentPortalAccounts.php`
- `src/Model/Table/UsersTable.php`
- `src/Controller/AppController.php`
- `src/Controller/UsersController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `templates/Users/login.php`
- `templates/Users/studentsetup.php`
- `templates/Users/studentaccess.php`
- `templates/Users/editprofile.php`
- `templates/Users/students.php`
- `templates/element/Users/students.php` (the uppercase path delegates here)
- `webroot/sw.js`

Run from the hosted application root with the website's protected configuration:

```sh
ACP_ENV=demo ACP_CONFIG_FILE=/home/convention/acp-private/demo.php php config/migrations/010_add_student_portal_accounts.php
```

Migration 010 is additive/idempotent. It creates no passwords or setup codes
and does not change customer codes. Keep the site in maintenance if it fails.
Existing students need a school-issued setup code before their first login
under the new system. Uploading files alone does not run this migration.

Local regression: `ACP_ENV=local php tests/manual/student_portal_accounts.php`
with the local server running at `http://127.0.0.1:8000`. The test refuses hosted
configuration, creates disposable schools/students/supervisor, and explicitly
cleans up MyISAM user fixtures and InnoDB credentials. It tests real HTTP login,
setup, code issuance, CSRF, account indicators, profile protection, password
changes, scoping, eligibility, throttling and concurrent single-use consumption.
It sends no email. It exercises the real localhost IP throttle without clearing
other local users' throttle state; repeated runs may require waiting fifteen
minutes.

## Whole-team combined requests

One school requests a combined team after agreeing with the other school outside
the portal. It selects active students already registered in the same group
event at each school. At least one student from each school is required, and
the total must meet the event's minimum/maximum. School names and student names
are shown; no partner contact details or dates of birth are exposed by the roster.

Both schools see the same request. Admin approves or declines the whole roster
once, with POST/CSRF protection. Approval atomically groups all selected students
at both registrations and creates one canonical shared submission. The partner
does not accept, send another request, or configure a group. Both schools have
an Upload/update shared team link on their submission list. Existing files are
preserved when the other school updates only context. Replacing a file updates
the shared submission for both; simultaneous edits to the same field are
last-save-wins. Submission locking still applies.

There is one request per unordered school pair, convention season and event.
Pending and approved rosters reserve student IDs against overlapping combined
teams in that event. Decline preserves the roster history and releases the
students for other school pairs. A second request for the same pair/event is
rejected even after decline; contact the events team to discuss a declined
request. Cancellation, reopening, roster amendment, and dissolving an approved
team are not implemented. Existing group/student-event controls cannot split
pending or approved teams. Shared submission rows cannot be deleted individually;
either participating school can replace the uploaded content instead.

Existing per-student requests remain legacy records and are not automatically
converted from names into student identities. New approval writes marked legacy
compatibility records for scheduling/results; they are excluded from legacy
approval controls and name-based group synchronization. Result labels and
student performance report targets use exact team/group membership for new
teams, rather than combining every partner of a school into one label.

Deploy these files together during a maintenance window, after a database backup:

- `config/migrations/009_add_combined_teams.php`
- `src/Service/CombinedTeams.php`
- `src/Service/StudentPerformanceReportService.php`
- `src/Controller/CombinerequestsController.php`
- `src/Controller/GroupsController.php`
- `src/Controller/EventsubmissionsController.php`
- `src/Controller/ConventionregistrationsController.php`
- `src/Controller/JudgeevaluationsController.php`
- `src/Controller/Admin/CombinerequestsController.php`
- `src/Controller/Admin/EventsubmissionsController.php`
- `src/Controller/Admin/ResultsController.php`
- `src/Controller/Admin/ConventionsController.php`
- `src/Middleware/ScopedPublicationCsrfMiddleware.php`
- `templates/Combinerequests/addrequest.php`
- `templates/element/combined_teams.php`
- `templates/element/Combinerequests/viewlist.php`
- Both `templates/element/Admin/Combinerequests/index.php` and
  `templates/Element/Admin/Combinerequests/index.php`
- Both `templates/element/Eventsubmissions/viewlist.php` and
  `templates/Element/Eventsubmissions/viewlist.php`
- `templates/Eventsubmissions/submitgroupevent.php`
- Both lowercase/uppercase Admin Results `index.php` and `overallpositions.php`
  elements
- `webroot/sw.js`

From the hosted Demo application root, explicitly select the same protected
configuration as the website; do not rely on CLI's Local default:

```sh
ACP_ENV=demo ACP_CONFIG_FILE=/home/convention/acp-private/demo.php php config/migrations/009_add_combined_teams.php
```

Migration 009 is additive/idempotent and clears the model schema cache. It
requires InnoDB for the existing mutable tables. DDL is not transactional, so
keep the portal in maintenance if migration fails and resolve it before
reopening. No existing request or student data is migrated or removed.

Local regression command: `ACP_ENV=local php tests/manual/combined_teams.php`.
It creates transactional fixtures using existing active schools/students as
read-only identity sources, exercises request/approval/shared upload/visibility,
and rolls all database fixtures back. Uploaded test files are deleted. Do not
run this manual fixture test against a hosted production database. It does not
send email. School notification delivery requires a separate deployment smoke
test with configured mail transport.

## Supervisor approval preview

The school sidebar includes Judges Registered between Permission Forms and
Result Package. This read-only page lists Judge accounts and approved
Teacher_Parent judges (`is_judge = 1`) belonging to the logged-in school with a
registration in the selected convention season. Missing or foreign school
registration selections redirect to the dashboard. No registration or approval
is created by viewing the list.

The convention Supervisor Registration list queries only the selected
registration after verifying school ownership. Convention and supervisor
associations are loaded for full-page and AJAX responses. Missing or foreign
registration selections redirect to the dashboard. The unused legacy bulk
mutation payload is rejected; use the existing individual registration actions.

Schools can bulk archive active or inactive students from the Global Students
List using page-scoped checkboxes and Archive selected. The confirmation shows
the selection count. The POST requires CSRF and rejects selections containing
another school's students, non-students, or already archived records before
updating anything. Archiving changes the global account status only, as with
the existing single-student archive action; convention registrations are not
deleted. Selection does not carry across pages. Archived students retain the
existing individual Restore action.

The supervisor Notification page and school adult-registration forms are
previews only. No approval, signature, submission, notification delivery,
archive, or retention automation is implemented. Assignments shown are
same-school registrations in the current season. First-aid and A.C.E.
experience inputs are not stored in the current user schema and are not
represented as saved facts.

The legacy supervisor activation and password-setup links are disabled because
their identifiers are predictable. New supervisor records can still be created,
but no activation invitation is sent; existing supervisors can continue to
log in and change their passwords. Secure enrollment and working verification
delivery must be implemented and tested before restoring setup or enabling adult approvals. Existing
accounts may have been exposed to the old routes and need an appropriate
re-verification and credential recovery plan. SMTP configuration alone does
not make the old links safe. The school and supervisor profile-edit paths
reject attempts to change credentials or account identity, and those forms
and password changes require CSRF tokens. Existing school and supervisor
accounts still require independent address confirmation;
these restrictions alone do not prove account ownership. Other account roles'
legacy recovery paths have not been remediated.

### Self-service password recovery

The old predictable reset links are disabled for all account types. School,
supervisor and judge accounts may request a 30-minute, single-use reset link;
the account holder receives the link, and the events team receives a separate
notification without the link. Active and verified accounts only are eligible.
When multiple accounts of the selected type share an email address, one email
contains separately labelled links (account name, ID, and school where available).
Each link resets only its own account. The separate admin notification lists
account IDs but never contains reset links. Accounts requested in the last
minute retain their existing link rather than generating another one.
Both emails include an Accelerate-branded HTML version and a plain-text
alternative. Reset buttons and copyable URLs retain the secure token fragment.
Deploy the `password_reset.php` HTML/text templates under both `templates/email/`
and `templates/layout/email/` with the reset service and controller. These
dedicated layouts do not change unrelated application emails.
Requests are rate-limited; links use random tokens stored only as hashes.
An address or password change invalidates an outstanding link.
Reset pages require JavaScript, CSRF protection and a password of at least six
characters and at most 72 bytes (the bcrypt limit).
Existing logged-in sessions are not revoked by a reset.

The reset-mail transport is intentionally separate from normal Demo mail. It
only sends externally when the protected environment configuration explicitly
enables `passwordResetMail` with a TLS/STARTTLS SMTP host, port, username,
password, sender and events-team `notify` address. Review
`config/deployment.example.php`; never put real credentials in the repository.
Demo's ordinary mail remains captured or redirected. Verify the configured
mailbox addresses independently before enabling delivery. If configuration,
SMTP delivery, or storage is unavailable, the user sees an error; delivery
has not been verified merely by setting configuration values.

Migration `php config/migrations/008_add_password_reset_tokens.php` was applied
locally. Back up and run it separately in the intended hosted environment
before deploying the reset code; no hosted migration or configuration changes
have been made. For local testing, the server process can set
`ACP_PASSWORD_RESET_CONFIG` to a mode-0600 PHP file outside the repository
returning the same `passwordResetMail` array. Alternatively, an ignored,
mode-0600 `config/password_reset.local.php` can reference the existing local
SMTP settings. Local configuration additionally requires an `allowedRecipients`
list; both reset and notification messages are restricted to that list.
An explicit local `allowAllEligibleAccounts => true` setting permits reset
messages for all eligible accounts instead. This has been enabled locally at
the user's request; reset links still point to localhost and must be opened
on the machine running the portal. The default remains restricted when that
setting is absent. Account eligibility checks and rate limits still apply.
Use only a dedicated test mailbox
and SMTP account. This is separate from the environment variable used by the
hosted configuration. Run an end-to-end delivery test with the intended
recipient and confirm both messages; do not send production data to test inboxes.

Student assignment and reassignment require matching male/female genders and
at most 10 active students per supervisor across the same convention and
season, including assignments to students from different schools. Both
profiles must have a recognised gender. Reactivating student registrations
also checks these rules. Existing assignments are not automatically changed
or audited. The parent-child exception in the convention guidelines is not
implemented yet, so it is currently blocked as well. This check does not
verify age, clearances or the other sponsor eligibility requirements.
Before deployment, verify `conventionregistrations`,
`conventionregistrationteachers`, and `conventionregistrationstudents` use
InnoDB. Assignment changes fail explicitly if those tables cannot provide the
transactional row locks needed to enforce the limit. No schema conversion is
performed by this change.

### Cross-school sponsorship: pending requests only

The Add Student checkbox can register eligible students with their own school
and save a pending proposal for an active supervisor at another school in the
same convention and season. The student's actual supervisor is left unset:
the proposed supervisor does not receive student event-management access.
Gender and the current ten-student limit are checked when the request is made,
but pending requests **do not reserve capacity**. Capacity, eligibility and
identity must be rechecked at any future approval transition.

The school menu's Sponsorship Requests page shows pending requests to the two
schools involved. Only the originating school sees the student's name at this
stage; the receiving school sees the proposal without student names. The
originating school can cancel a request, leaving the student registered but
without an assigned supervisor. Pending requests must be cancelled before
reassigning, deactivating or removing a student. Deleting a student
registration after cancellation also deletes its request record. These
pending records are not a signed-form archive.

School approval, supervisor acceptance, personal signatures, actual
notification delivery, identity verification and five-year retention are
**not implemented**. In particular, a pending request does not establish that
the student has an accepted sponsor. Do not rely on it for safeguarding
coverage. Existing accounts need re-verification before approval can be enabled.

The local database migration has been applied. For any other environment,
back up the database, verify the registration tables use InnoDB, review the
script and deployment bundle, then explicitly run
`php config/migrations/007_add_cross_school_sponsorship_requests.php` using the
target environment's protected configuration before enabling this code. Do
not run it on a hosted server without separate authorization. Deploy the new
service and template, changed controllers, middleware, menu and service worker
together. Clear application and browser caches as appropriate. The script
creates an InnoDB table and a foreign key; it does not convert existing tables.
If the migration has not been applied, pending requests cannot be submitted.

## Working baseline and rollback

The starting worktree was branch `test/coderabbit`, commit `562aaff`, with
115 modified tracked files and 83 individual untracked files. Folder-collapsed
Git output previously reported 56 untracked entries, not 56 files.

Existing Reports, result publication, secure exports, cleanup, and scheduling
changes must be reviewed and preserved in a future release. Many of their
required classes, templates, tests, and Chart.js assets are still untracked.
Do not build from the current commit alone or copy the entire working tree.

A private, owner-accessible rollback archive and the exact starting Git
inventory were saved outside the repository in the local Copilot session
workspace before application changes. The archive contains the touched
application files as they existed with the user's earlier work. It is not a
database backup or a production rollback artifact. Restore individual files
only after reviewing subsequent edits; never reset the worktree wholesale.

### Future release classification

- Review for release: application code, active case-sensitive compatibility
  templates, public assets (including pinned Chart.js and license), Composer
  manifests/lockfile, routes, configuration templates, tests, and documentation.
- Generate dependencies from the lockfile in a clean build; do not copy the
  local dependency tree.
- Exclude private local configuration, `config/my_const.php`, database data,
  uploads, exports, logs, temporary files, caches, editor state, `.deploy`,
  `Backup`, PHPUnit caches, phpinfo files, and duplicate installer files under
  `bin`. Keep only reviewed runtime CLI entrypoints in the runtime artifact.
- Keep historical schema scripts and destructive maintenance/import helpers
  out of the runtime artifact. Review separately; never run them automatically.
- The local fingerprint baseline is operational evidence, not Demo seed data.
- Do not move or delete existing files as part of packaging preparation.

## Environment configuration

`ACP_ENV` is `local`, `demo`, or `live`. Without it, only CLI or a loopback-host
web request can select Local. Hosted environments must explicitly select their
identity and set `ACP_CONFIG_FILE` to a protected PHP configuration file.

Local continues loading the existing ignored `config/app.php` and legacy
`config/my_const.php`, without rewriting credentials or changing local session
names. Local CLI URL generation defaults to `http://127.0.0.1:8000` when
`HTTP_PATH` is not explicitly set. The local legacy SMTP/payment settings are
preserved, **not made safe for arbitrary local email/payment exercises**.

Hosted environments never load either local credential file. They use the
secret-free `config/app.default.php`, validated protected settings, and
`config/hosted_legacy.php` for compatible business dictionaries and constants.
The duplicate dictionaries deliberately preserve local credential-file
contents; keep them synchronized if those business definitions change.

Use [deployment.example.php](../config/deployment.example.php) as an inert
template, not executable setup. Its placeholders deliberately fail validation.
Provision the protected file outside both the release and public document
roots with mode 0600 (or reviewed 0640); no group write or world permissions.
Never paste secret values into logs, tickets, tests, or commits.

Required hosted keys:

| Key | Demo | Live |
| --- | --- | --- |
| `environment` | `demo` | `live` |
| `publicUrl` | exact HTTPS `/acp_demo` URL | exact HTTPS `/acp` URL |
| `releaseId` | approved artifact identity | same approved artifact identity |
| `storageRoot` | canonical private directory ending in `demo` | private directory ending in `live` |
| `securitySalt` | unique secret, at least 32 characters | independently provisioned secret |
| `database` | explicit host/name/user/password; name and user contain `demo` | explicit independent connection |
| `emailMode` | `capture` or `smtp_test` | `smtp` with explicit SMTP credentials |
| `payments` | `disabled` or `sandbox` | explicitly `disabled` or `live` |
| `integrations` | empty array | reviewed integration names |
| `commands` | empty by default | explicit approved Cake command names |

Demo `smtp_test` additionally requires an explicit test SMTP account, valid
test sender, and one approved test recipient. Every To/CC/BCC recipient is
rewritten to that inbox before SMTP delivery. Demo `sandbox` requires the
PayPal Sandbox merchant email; its payment form posts only to PayPal Sandbox.
Never put live SMTP or PayPal merchant credentials in the Demo configuration.

Live additionally requires a valid sender address. Environment-variable
database, email, cache, and log URL overrides cannot replace the hosted
protected configuration. Hosted test datasources are not registered.

The database-name/user check prevents common configuration mistakes; it cannot
prove a MySQL account's grants, database contents, or password provenance.
Separate databases, separate non-overlapping grants, synthetic Demo data,
and independent secrets remain mandatory server prerequisites.

### Storage and cookies

Pre-provision `tmp`, `logs`, `sessions`, `cache`, and `uploads` under each
private storage root. Bootstrap validates them but does not create directories
or change permissions. Symlink targets for those directories must stay inside
the same environment storage root.

Hosted temporary/log/cache paths point outside the release. Cache prefixes
include both environment and release identity. Session names are
`ACP_DEMO_SESSION` and `ACP_LIVE_SESSION`, with respective `/acp_demo` and
`/acp` cookie paths, private file-session directories, secure/HttpOnly/SameSite
Lax cookies, strict handling, and a two-hour session timeout. Publication CSRF
cookies are `acp_demo_csrf` and `acp_live_csrf`; existing scoped protection and
POST-only publication behavior remain in place.

Map each release's public `webroot/files` and `webroot/uploads` to **its own**
approved persistent upload directories. Never expose private temporary
exports. Legacy upload constants use private persistent upload storage.
Other upload code still uses the mapped `WWW_ROOT` locations, so both mappings
must be verified before accepting hosted uploads.

Preserve `tmp/squad247_submissions.json` and `logs/schedule_repairs`; these are
operational records, not disposable caches. The export root itself must not
be a symlink. Generation and cleanup must resolve the same canonical `tmp`.

The active `Developer` administrator account has an Error Logs dashboard card
and an `/admin/admins/errorlogs` page. Access is checked against the
database account, not just the session username. The viewer reads current and
numeric rotated `error.log` and `cli-error.log` files from `LOGS`; symlinks and
other paths are excluded. Search and pagination display 100 matching lines per
page, newest first. Multiline stack traces are displayed as individual lines.
Known environment-policy messages are labelled `Safety block`; recognized
scheduling/filter diagnostics are labelled `Diagnostic`. Other events are
labelled `Manual investigation required`. Continuation lines inherit their
event's label, including across search and pagination boundaries. Labels do
not assert that historical issues remain active or have been resolved.
The viewer offers no automatic repairs. Its confirmed **Delete all error logs**
action is Developer-only, POST-only and CSRF-protected. It truncates all
allowlisted current/rotated error and CLI error files under `LOGS`, preserving
files for continued logging. It does not clear other logs or cPanel server logs;
deletion is irreversible and failures explicitly report possible partial clearing.
The viewer groups matching lines by event, showing the status and original
header in Minor, Major and Extreme priority columns. Minor covers warnings and
recognised diagnostics, safety blocks and rejected requests; Major covers other
errors and unrecognised entries; Extreme covers critical, alert and emergency
levels, which are never downgraded by message classification. These are recorded
severity indicators, not verified business impact. Column counts cover events
on the current filtered page only; columns stack on narrow screens.
The viewer shows the original
header once with collapsible details in original line order. Pagination still
counts matching lines, not failures, and can split an event across pages.
Known invalid-category/log-selection validation exceptions are labelled
`Request rejected`; unfamiliar exceptions still require investigation.
The Scheduling Reports conflict card opens `schedulings/resolvepreview`.
The former `schedulingtimings/removeoverlapping` URL is a GET-only redirect
to the same preview; it does not apply repairs. Applying/reverting repairs
still requires the existing POST, confirmation, and token checks.
Category 1 window diagnostics use debug logging; summaries use info for
success, warning for configuration/entry issues, and error for save failures.
The log viewer does not classify failed saves as harmless diagnostics.
Historical exception entries remain after fixes. For the global qualifying
feature, apply the reviewed additive
`config/migrations/004_add_global_qualifying_fields_to_events.php` migration
before deploying application code that reads those event columns. Seeding
qualifying values is a separate operation and must not run implicitly.
The read-only local regression check is
`ACP_ENV=local php tests/framework_compatibility_test.php`.
It does not collect cPanel, Apache, or PHP-FPM logs outside that directory.
Responses use `Cache-Control: no-store`; logs should still be treated as
sensitive operational data. Creating a local Developer account does not
provision one in a hosted environment.

Different URL paths on the same hostname are the same browser origin.
Cookie names/paths are collision prevention, not a complete browser isolation
boundary. Stronger browser separation requires a separately approved hostname.
Separate OS users/FPM pools and outbound network controls are also required
for an enforceable server-side boundary.

## Demo safeguards

- The Demo status banner is not rendered in application layouts. Verify the
  selected environment and test modes from the protected deployment
  configuration; hiding the banner does not change payment or email behavior.
- Demo supports in-memory email capture or explicit test SMTP. In test SMTP
  mode, all To/CC/BCC recipients are rewritten to the single approved inbox;
  From, Reply-To, and Return-Path are also forced to Demo-only addresses. Use
  dedicated test SMTP credentials and retain outbound network restrictions.
- The legacy email adapter reasserts the configured Demo transport at
  send/deliver time. Direct framework mailers use the same default transport.
  Arbitrary new network code still needs review and OS-level egress controls;
  PHP configuration is not a sandbox.
- Demo payment mode is disabled by default. If explicitly configured as
  `sandbox`, only the payment summary/process/return/IPN/cancel and invoice
  workflow actions are allowed; maintenance-like transaction actions remain
  blocked. Payment submission uses the configured PayPal Sandbox merchant,
  and verified Sandbox IPNs must match the Demo receiver, amount, and AUD
  currency before marking a transaction paid.
- School accounts can open Payment Summary and view their own transaction history
  and details in Demo even when payments are disabled; other schools' transactions
  remain inaccessible. This read-only exception allows GET requests only:
  submitting Payment Summary still requires the configured Sandbox payment policy.
  Upload `src/Middleware/EnvironmentSafetyMiddleware.php` to apply this change
  to the hosted site. No database migration or payment configuration change is needed.
- The PayPal payment form remains absent unless `Payments.enabled` is set by
  the validated environment configuration. Live payment credentials are not
  accepted by Demo validation.
- reCAPTCHA, geocoding, and the stage-program integration are denied in Demo
  with generic errors and policy-only logs. No authentication bypass replaces
  them. Public informational WordPress links remain unchanged.
- Hosted Cake CLI commands are denied unless explicitly allowed; help remains
  available. Demo permits only an explicit future approval of
  `result_export_cleanup`, not migration or production commands. None are
  enabled by the example configuration.
- A fixed, visible Demo indicator appears in the eight maintained PHP layout
  variants (public/school, administrator, administrator login, and default). The legacy CTP copies
  are not the CakePHP 5 runtime templates.
- Request host/base must match protected hosted configuration. This is checked
  before controller actions; Apache and static-file routing still require
  their own verified configuration.

The app cannot install, inspect, or control system crons or arbitrary standalone
PHP scripts. Demo must have no copied production crontab. Deny outbound SMTP,
production DB access and non-allowlisted integrations at the server boundary.
Restrict Demo using approved HTTP authentication/VPN/IP policy plus normal
application login, with synthetic test accounts only.

## Targeted path changes

No bulk URL replacement or root Apache rewrite change was made. Hosted
configuration avoids the `/acp_new` fallback without editing the existing
credential file. Convention toggle responses and six administrative user
actions now use Cake routing rather than hard-coded application prefixes.
Public page decoration and the payment logo use the current environment URL.
Informational main-site links remain intentional external resources.
Other legacy PDF/template URLs require route-by-route hosted acceptance;
this phase is not a claim that every legacy screen has been validated.

## Release packaging design (not executed)

1. Review all intended work, remove secrets from the future release inputs,
   and create approved commits only after separate authorization.
2. Build from a clean reviewed commit using a strict path allowlist. Use the
   committed Composer lockfile, `--no-dev --prefer-dist --no-interaction`,
   and `--no-scripts` so the legacy installer cannot create configuration,
   broaden permissions, or change a security salt.
3. Explicitly review necessary Composer/plugin build behavior. Run platform
   checks using the target PHP runtime; never ignore platform requirements.
4. Record commit, lockfile hash, runtime, file checksums, configuration format,
   test results, and migration requirements in a manifest. Checksum the
   artifact and retain it outside public web storage.
5. Deploy to a new Demo release directory, attach only Demo protected config
   and persistent storage, and run acceptance tests with synthetic data.
6. Promote the **same approved artifact** to Live; do not copy Demo mutable
   files or databases. Environment configuration is not part of the artifact.
7. Before promotion, verify independent database/upload/config backups and
   restore capability. Inventory actual schema differences; do not auto-run
   historical schema scripts. Schema changes require separate approval.
8. Switch an environment-specific current-release pointer only after preflight.
   Handle PHP-FPM/OPcache and caches using an approved server procedure.
9. Roll back code by restoring the prior pointer, leaving persistent data and
   secrets intact. Schema rollback needs a separately rehearsed plan; never
   overwrite newer Live data automatically.

No artifact builder, archive, production release, or deployment job was created
in this phase.

## Verification and next approval

Run environment unit tests and isolated hosted bootstrap tests without opening
a database connection, then middleware/template/mail/CSRF tests. Run Reports,
publication, export, cleanup, scheduling, and controller regressions. Existing
live-local data tests use SELECT-only queries; transitions use test doubles.
Compare all nine ACP-DB-FINGERPRINT-V1 table hashes before and after. Independently
compute Season 26's timetable hash from **ID-keyed**, ID-ordered timing rows;
the integrity script's embedded expected Season 26 metadata is not measurement.

Before preparing Demo, obtain authorized server evidence for document roots,
WordPress/maintenance rules, FPM/CLI versions, grants, storage ownership,
egress controls, Chrome/PDF support, jobs, backup/restore, and same-origin risk
acceptance. This phase does not authorize obtaining server access, provisioning
databases, deploying files, rotating secrets, enabling jobs, or migrations.

Tracked historical credential definitions need owner review and a future
rotation/removal plan. Do not rotate keys or rewrite Git history automatically.
Any Live salt change requires planning for existing sessions and signed tokens.

## Phase H implementation record (2026-10-22)

### Exact application files changed

Existing files modified in this phase (their earlier work was preserved):

1. [.gitignore](../.gitignore)
2. [README.md](../README.md)
3. [config/bootstrap.php](../config/bootstrap.php)
4. [config/paths.php](../config/paths.php)
5. [src/Application.php](../src/Application.php)
6. [src/Controller/AppController.php](../src/Controller/AppController.php)
7. [src/Controller/ConventionsController.php](../src/Controller/ConventionsController.php)
8. [src/Controller/Admin/AdminsController.php](../src/Controller/Admin/AdminsController.php)
9. [src/Controller/Admin/AdvertisementsController.php](../src/Controller/Admin/AdvertisementsController.php)
10. [src/Controller/Admin/UsersController.php](../src/Controller/Admin/UsersController.php)
11. [src/Middleware/ScopedPublicationCsrfMiddleware.php](../src/Middleware/ScopedPublicationCsrfMiddleware.php)
12. [src/Utility/LegacyEmail.php](../src/Utility/LegacyEmail.php)
13. [templates/layout/home.php](../templates/layout/home.php)
14. [templates/Layout/home.php](../templates/Layout/home.php)
15. [templates/layout/admin.php](../templates/layout/admin.php)
16. [templates/Layout/admin.php](../templates/Layout/admin.php)
17. [templates/layout/admin_login.php](../templates/layout/admin_login.php)
18. [templates/Layout/admin_login.php](../templates/Layout/admin_login.php)
19. [templates/layout/default.php](../templates/layout/default.php)
20. [templates/Layout/default.php](../templates/Layout/default.php)
21. [templates/Transactions/paymentprocess.php](../templates/Transactions/paymentprocess.php)
22. [templates/Users/squad247.php](../templates/Users/squad247.php)
23. [tests/TestCase/Middleware/ScopedPublicationCsrfMiddlewareTest.php](../tests/TestCase/Middleware/ScopedPublicationCsrfMiddlewareTest.php)

New files:

1. [config/deployment.example.php](../config/deployment.example.php)
2. [config/hosted_legacy.php](../config/hosted_legacy.php)
3. [src/Deployment/Environment.php](../src/Deployment/Environment.php)
4. [src/Deployment/SafetyPolicy.php](../src/Deployment/SafetyPolicy.php)
5. [src/Mailer/Transport/DemoTransport.php](../src/Mailer/Transport/DemoTransport.php)
6. [src/Middleware/EnvironmentSafetyMiddleware.php](../src/Middleware/EnvironmentSafetyMiddleware.php)
7. [templates/element/environment_banner.php](../templates/element/environment_banner.php)
8. [tests/TestCase/Deployment/EnvironmentTest.php](../tests/TestCase/Deployment/EnvironmentTest.php)
9. [tests/TestCase/Deployment/HostedBootstrapTest.php](../tests/TestCase/Deployment/HostedBootstrapTest.php)
10. [tests/TestCase/Deployment/DemoPresentationTest.php](../tests/TestCase/Deployment/DemoPresentationTest.php)
11. [tests/TestCase/Middleware/EnvironmentSafetyMiddlewareTest.php](../tests/TestCase/Middleware/EnvironmentSafetyMiddlewareTest.php)
12. This document.

The private rollback archive, inventory, and before/after fingerprint files are
outside the repository. PHPUnit updated its pre-existing local result cache;
that cache is not a release input. No existing application files were deleted
or committed. Tests removed only their own disposable fixtures.

### Test results and limitations

| Verification | Result |
| --- | --- |
| Environment, Demo presentation/mail, middleware and CSRF selection | 23 tests, 191 assertions; no failures; one framework deprecation |
| Final complete PHPUnit suite | 312 tests, 31,441 assertions; two pre-existing Pages failures, one known discovery warning, eight deprecations |
| Prior full applicable selection excluding legacy Pages tests | 308 tests, 31,433 assertions; no failures; existing discovery warning/deprecations |
| PHP syntax checks | All 32 new/modified PHP files passed across staged checks |
| Phase-H-only whitespace check against saved originals | Passed; full worktree still has unrelated pre-existing whitespace findings |
| Local school/admin login HTTP checks | Both 200 |
| Anonymous Reports access | 302 to local login |
| Existing local credential files | Unmodified; private checksum comparison passed |

The final full run includes one additional passing malformed-configuration
privacy test after the earlier filtered applicable selection. No test failures
were hidden or converted to success.

The legacy `PagesControllerTest::testDisplay` and
`PagesControllerTest::testMissingTemplateInDebug` fail because the Pages
controller is missing (404 instead of the skeleton test's expected response).
Both failures were reproduced with the saved pre-H Application/AppController
code. They were not repaired as part of deployment configuration work.

The existing `SmallProgramDivisionsTest.php` discovery warning remains.
Consequently the full command exits nonzero and is **not a clean deployment
gate**, even though Reports, publication, exports, cleanup, environment, and
scheduling assertions have no new failures. No hosted browser acceptance was
performed or claimed.

Hosted bootstrap tests use synthetic protected configuration, never load local
credential constants, and assert the database driver remains disconnected.
They verify environment-specific Reports URLs/assets and reject missing,
mismatched, world-readable and malformed configuration without revealing
malformed configuration contents. Controller transitions use test doubles,
not changes to local publication flags.

### Database integrity

All nine ACP-DB-FINGERPRINT-V1 table fingerprints matched before and after:

| Scope | Rows |
| --- | ---: |
| schedulingtimings | 4,249 |
| conventionseasons (id and publication flag) | 22 |
| conventionregistrations | 880 |
| conventionregistrationstudents | 3,106 |
| crstudentevents | 36,968 |
| eventsubmissions | 33,261 |
| judgeevaluations | 37,142 |
| results | 2,682 |
| resultpositions | 28,682 |

Independent SELECT-only measurement confirmed Season 26:

- 1,869 timetable rows.
- Canonical SHA-1 `68f7759f515af2ea88eefb2e0d3d02f60095041b`.
- `results_release = 1`.

No database-writing commands, migrations, schedule generation, publication
changes, commits, cron activation, hosted server connections or deployments were
performed in Phase H. This fingerprint scope does not claim full-database or
historical Phase C hash equivalence.

### Review outcome

Local preparation is implemented and ready for review, **not authorized for
deployment**. Next steps are approval of this code/configuration contract,
resolution or explicit disposition of the existing test-gate issues, and a
separately authorized Demo server preparation phase. That phase must establish
the runtime, storage mappings, independent database grants/secrets, outbound
controls, access restriction, backup/rollback, and WordPress/maintenance
compatibility before any application release is installed.
