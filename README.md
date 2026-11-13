# CakePHP Application Skeleton

[![Build Status](https://img.shields.io/travis/cakephp/app/master.svg?style=flat-square)](https://travis-ci.org/cakephp/app)
[![License](https://img.shields.io/packagist/l/cakephp/app.svg?style=flat-square)](https://packagist.org/packages/cakephp/app)

A legacy convention portal currently being migrated to CakePHP 5 for PHP 8.5
deployment.

The framework source code can be found here: [cakephp/cakephp](https://github.com/cakephp/cakephp).

## Runtime targets

- Current local runtime: PHP 8.5 / CakePHP 5.4
- Migration target: PHP 8.5 / CakePHP 5.x

## Installation

1. Download [Composer](http://getcomposer.org/doc/00-intro.md) or update `composer self-update`.
2. Run `php composer.phar create-project --prefer-dist cakephp/app [app_name]`.

If Composer is installed globally, run
```bash
composer create-project --prefer-dist cakephp/app [app_name]
```

You should now be able to visit the path to where you installed the app and see
the setup traffic lights.

## Configuration

Local-only preparation for separate Demo and Live environments is documented in
[Deployment preparation](docs/deployment-preparation.md). Hosted environments
require protected, explicit configuration; the existing local credential files
remain unchanged. Release packaging is a design only, not an executed deployment.

Read and edit `config/app.php` and setup the 'Datasources' and any other
configuration relevant for your application.

Judge registration normally follows the school registration closing date.
`JudgesRegistration.closingDates` in `config/app.php` extends judge registration
for specific convention-season slugs through the configured date (inclusive,
Australia/Brisbane time). Indonesia Student Convention 2026 remains available
through 9 October 2026. This does not reopen school registration or submissions.

In the admin 24/7 Squad Content Editor, **Convention Dates Message** can replace
the public convention dates with a notice and hide convention fees. Enter a
message, tick its display checkbox and save to show it. Untick and save to
show the retained dates and fees again without deleting the message. Convention
selection remains available without fee amounts while the message is enabled.

The admin season-events listing now resolves from
`templates/element/Admin/Conventions/events.php` (lowercase `element`).
The uppercase `Element` copy forwards to this file for compatibility.

Global qualifying thresholds and current records are managed from
**Manage Global Events → Qualifying Times & Records**. Saving a global event's
qualifying value and record updates all existing convention-season entries for
that event; newly imported season events inherit the same values. Apply the
database changes in order with
`php config/migrations/004_add_global_qualifying_fields_to_events.php` and
`php config/migrations/005_seed_global_qualifying_fields.php`. Existing values
are seeded from the latest non-empty season entry (most recently modified entry
wins ties) and then synchronized across seasons.

Guideline breach reviews use Minor, Major, and Extreme levels. Apply the schema
change with `php config/migrations/006_add_guideline_breach_tiers.php` before
using the Events Team review controls. Accepted breaches apply their tier
restrictions; rejected breaches restore normal judging and placement
eligibility.

Evaluation forms show a collapsed, event-specific Guidelines accordion with
readable excerpts and links to the original local PDF pages. The shared element
is `templates/element/Judgeevaluations/guidelines.php`; event-number mappings and
source-section boundaries are maintained in `bin/build_event_guidelines.py`.
Rebuild `config/event_guidelines.json` after changing those mappings or replacing
PDFs in `webroot/files/guidelines`:

```bash
python3 bin/build_event_guidelines.py
```

The build command needs Python 3 and PyMuPDF (`fitz`) on the maintainer's machine;
production PHP reads the checked-in JSON and needs no PDF extraction software.
Each event includes its specific rules, applicable division rules and checklists.
Mappings use event numbers rather than shared evaluation-form names, including
Bible Bowl (1057), which has a legacy Spelling-form association. The supplied
2025-2026 Instrumental PDF is used instead of older Instrumental rules.
Legacy Online events without a dedicated section and conflicting Instrumental
Ensemble accompaniment wording are explicitly flagged for Events Team
confirmation; unknown event numbers show a warning and are logged rather than
receiving another event's rules.
The accordion reflows PDF lines into paragraphs, numbered rules and checklists.
Event requirements appear first; lengthy general guidelines are separately
expandable so they do not overwhelm the evaluation form.

## User portal interface

Signed-in school, supervisor, judge, and student pages using the `home` layout
share the responsive portal shell. Its scoped styles are in
`webroot/css/front/portal.css`, with navigation behaviour in
`webroot/js/front/portal.js`. Public pages retain their existing header and styles.
The dashboard provides role-aware shortcuts, configured instructional videos,
and events-team announcements without changing registration permissions.
The Quick Access shortcuts are in an accordion that starts closed on each page
load. Its open and close animation is skipped when reduced motion is requested.
The global Supervisors List groups Active, Archive, and Inactive accounts in
separate accordions, each with its own pagination. They start closed on page
loads and animate unless reduced motion is requested.

Navigation is grouped in `templates/element/user_left_menu.php`; the uppercase
`Element` PHP copy forwards to it. Both PHP `home` layout variants load the
shared portal header/footer elements. Mobile navigation starts closed and can
be dismissed with Escape or the backdrop. On desktop, the hamburger collapses
navigation to a 72px icon rail rather than hiding it. Links retain accessible
names and hover titles, the hamburger stays above the rail, and the content
expands into the available space. This desktop preference is remembered.

Student Event Registration uses a single table card with aligned search and
pagination controls. On narrow screens, the table scrolls horizontally inside
the card without widening the page.

School Result Package and its print view share `ResultPackageService`, which
bulk-loads placements, group members, student/school names, and Command
Performance flags instead of querying per event or student. Results remain
ordered by place, with tied placements ordered by result-position ID. Existing
ownership, results-release checks, and private/no-store responses are unchanged.
Run `php tests/result_package_performance_test.php <registration-id>` locally
for a read-only comparison with the previous queries and a two-second data-load
assertion. Verify full browser navigation separately, including asset loading.

### School Student Performance Reports

School accounts can open **Reports** from Workspace without selecting a
convention. `GET /reports` supports `q`, `page`, and `limit` (1-100); the preview
is `GET /reports/students/{studentId}`. All other HTTP methods are rejected.
The endpoints preserve existing login/impersonation sessions and use private,
no-store responses.

`src/Service/StudentPerformanceReportService.php` performs SELECT-only ORM
queries. It counts distinct global student/convention-season/event entries from
`crstudentevents`, not submissions, judging rounds, or presumed attendance.
Only the authenticated school's global students and owned registrations qualify.
Placements come from saved result headers/positions and require
`conventionseasons.results_release = 1`; unreleased placement data is never
included in HTML or chart JSON. Repeated identical awards collapse to one;
contradictory positions are excluded and flagged as incomplete totals.
Published automatic awards are included and labelled separately.
Saved individual awards lacking a matching event-entry record still count,
with a data-quality notice; they do not inflate registration-based event totals.

Group awards require exact registration/event/group membership. Combined-school
awards additionally require a matching approved source-school request, a unique
exact student name in that source event, and stable-ID host-school membership.
Ambiguous legacy links are excluded, never inferred from similar names.
History uses year, registration opening date, then convention-season ID because
historical convention occurrence dates are not consistently available.

The seven-series chart uses locally served Chart.js **4.4.8** in
`webroot/js/vendor/chartjs-4.4.8/` with its MIT license. Unreleased or conflicting
placement series have gaps rather than fabricated zeros. The history table
works without JavaScript.

Focused tests are in `tests/TestCase/Service/StudentPerformanceReportServiceTest.php`
and `tests/TestCase/Controller/ReportsControllerTest.php`. Pure policy tests use
synthetic records; read-only integration checks use the existing local sample
school 7991/student 8214. They do not create fixtures or alter publication flags.

## Workspace organization

- Active application/runtime files remain in the project root (`src/`,
  `templates/`, `config/`, `plugins/`, `webroot/`, `vendors/`, `tmp/`,
  `logs/`).
- Non-runtime root artifacts (db dumps, ad-hoc imports, temp files, phpinfo
  outputs, installer leftovers) are archived under timestamped folders in
  `Backup/root-artifacts/`.
- In-tree backup/edit artifacts (`*.bak*`, `*.new`) from `src/` and
  `templates/` should be archived under timestamped folders in
  `Backup/in-tree-artifacts/` instead of being kept beside active source files.
- Plugin backup copies should also stay outside `plugins/` to avoid duplicate
  autoload namespaces.
- `templates/Element` vs `templates/element` (and `Layout/layout`,
  `Email/email`) currently contain intentionally mixed compatibility content.
  Do not bulk-merge or delete one side without a route-by-route render test.
  Keep case-duplicate audits in `Backup/reports/` before any consolidation.

## Scheduling conflict status

Conflict checks now use `src/Scheduling/TimetableValidator.php`, the same
ruleset used by timetable generation and repair planning. Checks include student
overlaps, room clashes/lane capacity, knockout order, blocked periods, outside
hours, pinned constraints and unscheduled rows.

Conflict checks treat bookings with a NULL `is_bye` value as normal bookings.
Categories that do not use byes store NULL, so an `is_bye != 1` SQL condition
alone would exclude those bookings from the check.

Participants are students only: School rows store school ids in `user_id` /
`user_id_opponent`, so team rows count their `group_name_user_ids` /
`group_name_opponent_user_ids` members instead (a school may run several teams at
once). Team members are filled for every group row, including sequential group
events without an opponent, both after scheduling and before Resolve Conflict.

The old one-click resolver now redirects to a **dry-run** preview:

1. **Check & Resolve Conflicts** opens a preview plan (nothing is saved).
2. `src/Scheduling/ConflictRepairPlanner.php` proposes the smallest safe moves.
   Bookings with no problem of their own are never moved. When two bookings
   conflict, the more recently edited one moves first, and only one side of a
   knockout feeder/dependent pair is moved. Bookings that share one batch
   session (spellers, combined schools) are treated as using a single lane.
3. The admin reviews before/after counts, proposed moves and unresolved rows.
4. Apply requires explicit confirmation.
5. Every applied run is stored in `logs/schedule_repairs/season_<id>/` and can
   be reverted safely (rows changed after apply are skipped, not overwritten).

Apply and revert both run through `src/Scheduling/ConflictRepairService.php`.
Each one:

- holds a per-season MySQL lock (`GET_LOCK`) and locks the season's rows with
  `SELECT ... FOR UPDATE`;
- refuses a stale preview: the timetable, rooms, events, pinned settings or hours
  changed after the preview was made;
- re-checks the plan against the current database, both before and after writing;
- rolls everything back if any new issue or any error appears.

A revert that would create a new issue is refused as a whole. Booking moves that
cannot be repaired stay where they are and are listed with the rules that blocked
them.

The repair files sit outside `webroot/`. Directories are created 0750 and files
0640. Writes are atomic (temp file + rename), and status updates use a
per-season file lock. Plan tokens and run ids are format-checked, so they cannot
be used as paths.

Preview, apply, revert and report require a logged-in admin. This is checked in
`SchedulingsController::beforeFilter`, which stops the action; a redirect from
`initialize()` alone does not. Apply and revert accept POST only, and must carry
the session-bound `_repair_token` form field (the app has no global CSRF
middleware). Localhost auto-admin now needs both a localhost `Host` header and a
loopback client address.

The planner uses the shared `TimetableLedger` + `SlotPlanner` constraints and
never accepts a plan that introduces new issue keys when re-validated.

Every generation run records its random seed in `schedulings.schedule_seed`
(`config/schema/schedule_seed.sql`) and in
`logs/scheduling_seed_season_<id>.json`. Entering the same seed in the wizard
reproduces the same timetable for the same data. A blank seed picks a new random
seed.

The schedule category page runs Start Scheduling one step per request and shows
a live progress bar (categories 1-4, team members, conflict check). Scheduling
actions answer requests carrying `X-Schedule-Progress: 1` with
`{"redirect": "<next step URL>"}` instead of an HTTP redirect; normal requests
still redirect through the chain. Keep the page open until it finishes.

## Knockout brackets (categories 2 and 3)

Elimination draws are planned by `src/Scheduling/KnockoutBracketPlanner.php`.
Round 1 is padded with byes up to the next power of two (a lone entrant gets a
single walkover bye). Rounds 2..Final are planned once and stop at the Final;
both individual (2) and group (3) events get a 3rd-place playoff, saved after the
Final in the final round, only when both semi-finals are real matches. Later
rounds keep the random pairing and reference their feeders through
`schtimeautoid1` / `schtimeautoid2`.

When matches are given times, a match never starts before its feeder matches
finish on that date, and is not placed on an earlier date than its feeders.

## Conflict-free placement (all four categories)

Every category places its bookings through `src/Scheduling/SlotPlanner.php`,
which reads and updates one shared `src/Scheduling/TimetableLedger.php` loaded
from the season's saved bookings. A slot is accepted only when all of these hold:

- the slot fits the event's band (convention days and hours, first-day override,
  pinned day/start) and passes `findValidSlot` (room availability windows);
- it does not overlap lunch, judging breaks or the sports-day block;
- no other event is in the room at that time, and the event stays within the
  room's lanes (`number_games` for categories 2/3; one lane per room for 1/4;
  one lane for chess-style rounds; the speller batch size for category 4). The
  lowest lane limit among the overlapping sessions applies, so a single-board
  round (for example a Checkers semi-final) gets the room to itself, whichever
  booking was placed first;
- no confirmed participant is already competing then. Group bookings are
  checked against every team member (`crstudentevents`) before placement, and
  approved combined schools are placed together in one slot;
- knockout matches start after their feeders finish. Students who might reach
  a later round are only a soft preference, never a hard block.

A 5-minute gap between one student's bookings is preferred when it costs
nothing; it is not required. Nothing is placed after the convention window. A
booking that cannot meet every rule stays unscheduled (category 1 rows are not
created). These bookings are flashed to the admin and written, with their
reasons, participants, rooms, duration and pinned settings, to
`logs/scheduling_unscheduled_season_<id>.json`.

Unit tests (no Composer dev install is needed):

    php phpunit.phar --no-configuration --bootstrap vendors/autoload.php tests/TestCase/Scheduling

## Day distribution strategies

The Scheduling Wizard's **Distribution** setting (`schedulings.schedule_band_mode`,
added by `config/schema/schedule_band_mode.sql`) chooses how events are spread
across the convention days:

- **Earliest Available** (`earliest`): every event fills the earliest valid slots.
- **Round-Robin Days** (`round_robin`): successive events start on successive days;
  if the preferred day leaves bookings unscheduled, the other days are tried.
- **Balanced Days** (`balanced`): each allowed start day is tried on a copy of the
  timetable, and the trial with the lowest cost is kept. The cost combines:
  - room workload away from an even, capacity-weighted share per day;
  - extra days the event spans;
  - minutes in the last hour of the day;
  - back-to-back (< 5 minute) bookings for its students.

  A trial with fewer unscheduled bookings always wins. Heavier Category 2 and 4
  events are placed first.

Seasons saved before this setting existed keep their old behaviour: the round-robin
checkbox maps to Round-Robin Days, otherwise Earliest Available. Choosing a strategy
also keeps `round_robin_day_distribution_yes_no` in sync.

Every strategy behaves the same in these ways:

- It only changes the band order offered to `SlotPlanner`, so student, room, lane,
  break, hours, pinned and knockout rules are always enforced.
- Knockout brackets never wrap to an earlier day.
- Events with a pinned day or start time always use date order.

The logic is in `src/Scheduling/DayStrategy.php`, with tests in
`tests/TestCase/Scheduling/DayStrategyTest.php`.

Category 1 entries that cannot be scheduled are reported in one warning. Each line
names the school, the event and the students, and says whether the group name is
blank and/or the event submission is missing.

## CakePHP 5 migration notes

- Event submission queries execute with `all()` before using result collection
  methods such as `extract()`, including combined-school registration lookups.

- Dependency targets are now set for CakePHP 5 / PHP 8.5 in `composer.json`,
  and lock resolution is updated in `composer.lock`.
- Template path has been migrated from `src/Template` to `templates` via
  `config/app.php` and `config/app.default.php`.
- PHP view templates are generated in `templates/` for CakePHP 5 `.php`
  template resolution.
- A compatibility auth component exists at
  `src/Controller/Component/AuthComponent.php` for legacy `$this->Auth` usage.
- A compatibility mail wrapper exists at `src/Utility/LegacyEmail.php` for
  legacy `new Email()` call patterns while transitioning to CakePHP 5 mailer
  behavior.
- Duplicate plugin backups should be kept outside `plugins/` (for example
  `Backup/plugin-copies/`) so Composer autoload doesn't register them as
  active plugin namespaces.

## Deploy on PHP 8.5 (WHM/cPanel)

Run these in the project root on the target server using PHP 8.5 CLI:

```bash
composer install --no-dev --optimize-autoloader
bin/cake migrations migrate
```

Then clear caches and warm route cache:

```bash
bin/cake cache clear_all
bin/cake routes >/dev/null
```

CodeRabbit PR test marker (2026-10-12 14:45:28 +1000)
