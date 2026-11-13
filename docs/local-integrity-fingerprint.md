# Local database integrity fingerprints

## Reproduction

Run from the application root with the local CakePHP CLI bootstrap:

```sh
php tools/database-integrity-fingerprint.php
```

The script uses only `SELECT` statements against the configured `default`
datasource. It emits table row counts, the exact ordered column list, primary
key ordering, and one SHA-256 fingerprint per table. It does not write to the
database.

The current table scope is `schedulingtimings`, `conventionseasons`,
`conventionregistrations`, `conventionregistrationstudents`, `crstudentevents`,
`eventsubmissions`, `judgeevaluations`, `results`, and `resultpositions`.
The `conventionseasons` fingerprint intentionally covers only `id` and
`results_release`, matching the publication-state scope that can affect
results visibility; the other tables cover every column in ordinal schema
order. Rows are ordered by primary-key columns in primary-key ordinal order.

## ACP-DB-FINGERPRINT-V1 encoding

The stream starts with the version marker, then a length-prefixed table name
and one length-prefixed column name plus lower-cased database type per column.
Each row is encoded in column order and terminated with a row marker.

- `NULL` is encoded as `N;`.
- Non-null text, numeric, date, and time values are encoded as UTF-8 database
  driver strings, with a type marker and byte length (`S<length>:<value>;`).
  No locale formatting, timezone conversion, or Unicode normalization is
  applied. Database date/time strings therefore retain their stored
  representation.
- Binary and bit columns are base64-encoded before byte-length framing and use
  a `B` marker.
- Row order, column order, nulls, lengths, and row boundaries are explicit,
  preventing delimiter ambiguity.
- SHA-256 is calculated incrementally over the complete framed stream.

The script's `conventionseasons` selection is limited to `id` and
`results_release`; this is recorded alongside each output fingerprint.

## Historical comparison limitation

The Phase C audit record preserves row counts and SHA-256 values, but the
canonicalization procedure and generating helper were not found in the
repository, available local audit files, or reachable Git history. The new
`ACP-DB-FINGERPRINT-V1` baseline is therefore a new baseline and must not be
claimed as equal to the Phase C fingerprints. Season 26's separate canonical
timetable SHA-1 remains reproducible through
`App\Scheduling\TimetableLoader::timingHash()`, over the ordered timetable
rows and `ScheduleRepairStore::TIMING_FIELDS`.

## Result export cleanup command

The local command `php bin/cake result_export_cleanup --dry-run` reports which
temporary export directories are eligible without deleting anything. A real
cleanup run requires omitting `--dry-run`; it is not scheduled or activated by
this change. The cleanup service restricts itself to random-named direct child
directories beneath `ROOT/tmp/student-result-exports`, retains them for at
least two hours after generation completes, and obtains a non-blocking
exclusive lock before removing a stale export. Generation holds the exclusive
lock; an authorized download holds a shared lock while reading the ZIP.

After validating the dry-run output and obtaining operational approval, a
WHM/cPanel daily cron can run at 03:15 server time:

```cron
15 3 * * * cd /home/nathan-waqa/acp_new && /usr/bin/php bin/cake result_export_cleanup >> logs/result-export-cleanup.log 2>&1
```

The example path and PHP binary must be confirmed for the target environment.
Keep this cron disabled until approved; review the log and a dry run before
the first destructive invocation.
