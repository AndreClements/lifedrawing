# Quick session notes during uploads

Status: implemented locally on 2026-09-26; not deployed. Uses migration 024.
Created and revised: 2026-09-26.
Companion: [Facilitator editing and management](facilitator-management.md).

Implementation: private notes are available on Upload Artworks and Manage session,
with collapsible history, explicit no-show/undo and the preview-first
`tools/session-notes.php` CLI. Local integration and phone-browser checks passed.

## Outcome

Give the facilitator a quick way to record what happened while uploading photos:
no-shows, late arrivals, sitter loo breaks, early departures, pose changes and
other useful details. Support the web upload screen and assisted phone-photo
imports, with notes also usable when there are no photos.

The usual interaction is a short sentence or a tap on a named participant.
Minutes and pose references are optional. Ordinary exceptions should take less
than 30 seconds to record, with clear saved/error feedback and later correction.

## Scope and existing code

- All logged-in facilitators/admins can read and manage these notes, regardless
  of session ownership. Use the companion plan's shared role-based
  `canManageSession($session)` policy and migrate upload access to match.
- `ld_session_participants.attendance` already has `booked`, `attended` and
  `no_show`. Reuse them; lateness and breaks are notes, not attendance states.
- The current no-show toggle accepts only earlier dates. Replace it with explicit
  set/restore commands and the shared session-end rule for same-day recording.
- Database roles are `artist`, `model`, `facilitator` and `observer`.
  Display `model` as Sitter without inventing a new database role.
- `ld_sessions.session_notes` exists but has no current PHP readers/writers.
  Check for non-empty production values read-only before building legacy display.
  Preserve the field; show its existing content privately only if content exists.
  `users.sitter_notes` remains separate person-wide context.
- Uploads use `modules/lifedrawing/Views/gallery/upload.php`,
  `GalleryController` and `public/assets/js/app.js`. Notes need separate forms
  and response targets so upload errors/reloads do not erase their input.
- Use the companion plan's checked audit writes, transaction rules and post-commit
  statistics refresh. The existing provenance logger silently suppresses failures.
- Actual notes and contact data belong in the database or ignored runtime inputs,
  never in tracked examples or photo captions.

## Capture experience

Put a compact **Session notes - private** panel above the upload form and on the
authorized Manage session view. Show the session date/title and roster with roles.
A Contact details shortcut opens the companion plan's private user panel without
discarding the current note or selected photos.

| Control | Quick interaction | Stored effect |
| --- | --- | --- |
| Note | Type or dictate; Save note or Ctrl+Enter | Session log entry |
| No-show / Undo no-show | Tap the selected participant's action | Attendance change and log entry |
| Late | Choose a participant; optionally add minutes | Log entry; attendance unchanged |
| Sitter loo break | Preselect the sole sitter; optional minutes/pose | Break entry with Loo break text |
| Other break | Optional participant, minutes and text | Log entry |
| Left early | Choose a participant; optional explanation | Log entry; attendance unchanged |
| Pose change / Other detail | Optional pose and short text | Log entry |

If several sitters exist, require a choice before attaching a break to one.
If none exists, allow an unassigned session note and link participant management.
Never guess identity, silently change a role or infer authorship from the roster.

Fictional examples:

- Artist A arrived about 15 minutes late; missed the warm-up.
- Sitter loo break during pose 3, about 5 minutes; resumed the same pose.
- Final pose shortened to 15 minutes. Use the later retake of this drawing.

Quick actions show Saved and Edit/Undo as appropriate. Free text has an explicit
Save note button. Keep optional fields collapsed. Show who recorded an entry and
when; that timestamp is the recording time, not when the incident happened.
A specific arrival/break time can be included in text; no structured event-time
or break-subtype field is needed initially.

Notes and images save independently. If a note is pending when Upload Batch is
pressed, save it first, then start the image request. If saving fails, preserve
the text/files and explain the error. Retrying uploads must not duplicate notes.
Avoid nested forms, whole-page swaps and automatic reloads while notes are
unsaved. Save note works without selected images. Support ordinary non-JavaScript
note submission without promising that a browser can restore file selections
after a full-page navigation.

## Attendance and cancellation rules

1. Use explicit set-no-show and restore-attendance operations, not toggles.
   Retried commands must not reverse their own effect. Preserve the legacy
   `attended` value so Undo restores the correct `attended` or `booked` state.
   Do not add a Here/attended control in this first version.
2. Extract `SitterQueueService::sessionIsOver()` to a shared helper beside
   `session_starts_at()` in `app/View/helpers.php`. Use the same helper in the
   queue, UI and attendance service, with Africa/Johannesburg time handling and
   existing fallback start/duration. Preserve and test the existing strict
   after-end boundary. Before then, an uncertain absence can be a note.
3. Target a participation ID within the session, showing its role where a person
   has several rows. Keep the protection against marking a facilitator row
   no-show. Never delete a booking as an attendance correction.
4. Commit attendance, its log entry and required audit together. Refresh that
   user's statistics afterwards. A cache failure leaves the saved attendance
   intact, with an explicit refresh retry needed. Ordinary notes do not refresh
   stats; typing no-show in text must not change attendance.
5. Undo attendance through the attendance action. Deleting or editing its log
   entry must not be an alternative way to alter history.
6. Notes and breaks do not schedule sitters or send notifications. A sitter no-show
   supplies evidence to the existing queue classifier; it must not automatically
   reverse a completed entry or requeue/notify someone. Link queue management
   when a separate correction is needed.
7. Cancellation is permitted only before session start and before any photos have
   been uploaded, as specified in the companion plan. Once started, record what
   happened through attendance/notes. Existing cancelled records retain their
   notes, readable/editable by facilitators/admins; attendance and normal upload
   activity are refused until explicit restoration. Notes cannot reactivate them.

## Small storage and service design

Add an additive migration for `ld_session_log`, choosing a free migration number
at implementation time. Keep attendance authoritative in its existing table.

Initial fields:

- ID, session ID and optional participation ID.
- Kind: `note`, `late`, `break`, `left_early`, `pose_change` or `attendance`.
- Plain text, optional non-negative minutes and optional positive pose number.
- Creator/editor IDs, created/updated timestamps and nullable `deleted_at`.
- A creation request key with a unique constraint on session plus key.

Keep text bounded, for example 4,000 characters, and escape it when displayed.
Validate references against the session, including on edits. Use deliberate
foreign-key deletion rules: session deletion can cascade its log, while removing
a participation/account must not erase unrelated session history. Do not need
an event datetime, subtype/detail field, revision counter, payload fingerprint
or separate tombstone table.

A pose reference may precede its photos, and never edits artwork metadata. When
a picker shows existing poses, use the companion plan's stable web-batch numbering
and legacy grouping normalization; do not guess pose identity from label alone.

Create one `SessionNotesService` shared by web and CLI:

- Authorize reads/writes with the facilitator/admin role; recheck session existence
  and participation scope on every operation.
- For a write, lock the affected state and save the note or attendance event plus
  required checked audit in one transaction, on the same database connection.
  Do not copy private note text into general provenance output.
- A creation retry uses the same key and returns the original recorded result.
  Reuse cannot modify that result: a correction is an explicit edit. Soft-deleted
  entries retain their key so a retry cannot recreate them.
- Edit/remove ordinary notes by ID plus expected `updated_at`. Use microsecond
  precision and ensure the timestamp advances on each write, so rapid saves can
  still detect conflicts. Reject stale edits and show the current saved version.
- Removal sets `deleted_at`; normal views exclude removed notes. Attendance
  entries are corrected through attendance commands, not ordinary note editing.
- Repeated explicit attendance requests are harmless no-ops. Keep request keys
  for their retries too, so an old set command retried after an Undo cannot apply
  again. Undo must check the expected current attendance before changing it.
- Only after commit, refresh stats if attendance changed. Failure to write a
  required log/audit record rolls back attendance as well.

Bind the service in `app/Kernel.php` and construct it with the same connection
in the CLI. Use the current PHP/HTMX/vanilla-JavaScript stack. No autosave system,
generic revision framework or new notification pipeline is required.

## Web integration

- Add authorized panel/read and note create/edit/remove endpoints, plus explicit
  attendance actions with existing CSRF protection. Keep note requests outside
  the image-upload rate limiter.
- Render a reusable `_session_notes.php` partial only after authorization, in both
  the upload and management views. No private data should be fetched merely to
  hide it with CSS.
- Share attendance actions/end-time eligibility with the existing participant
  manager; both entry points must agree.
- Give notes and upload progress separate response targets in `app.js`, with
  pending-note handling before upload navigation. Preserve state on validation
  errors; test mobile keyboard/focus and keyboard-only use.
- Keep note text out of public pages, profiles, captions, emails, WhatsApp output,
  shared exports, Instagram preparation and LDRBot prompts. Existing public
  attendance/no-show summaries do not gain the private narrative.

## Assisted phone-photo imports

Provide `tools/session-notes.php` as a small companion to the existing image
importer. Take explicit `--session`, `--actor` and `--text` arguments, with
optional `--participant` (participation ID), `--kind`, `--minutes` and `--pose`.
Use explicit attendance subcommands for no-show/restore; ordinary text never
changes attendance. Include list/read and dry-run; mutations require `--apply`.

Use a stable `--request-key` for each creation/attendance command, retained when
retrying. An ordinary note needs no JSON manifest. If text must be staged in a
file, keep it in ignored runtime storage outside the numbered photo directories.

During assisted imports, accept a sentence alongside the photos, preserve the
supplied wording and confirm ambiguous identities against the roster. Do not
require notes for every import or infer missing facts. Report note-save and image
results separately, and read back saved notes to verify. Photo-staging cleanup
must not remove database notes. Add concise examples to the bulk-import runbook.

## Delivery and acceptance

After the companion plan's shared prerequisites, ship the web panel/attendance
actions first, then the CLI companion. Coordinate additive migrations and
deployment with active work; no production changes are part of writing this plan.

Check with local database fixtures and focused route/browser tests:

- Save text, lateness and a loo break with and without selected files; reload and
  upload another pose. Notes survive and creation retries do not duplicate them.
- Upload and note failures preserve entered data in the JavaScript flow and never
  claim an unsuccessful save. Ordinary note submission also works without JS.
- Test before/at/after scheduled end, same-day sessions and nullable start time.
  Undo preserves legacy attendance. Breaks and lateness leave stats unchanged.
- Any facilitator/admin, including a different session facilitator, can manage
  notes. Guests and ordinary participants cannot obtain them through direct
  routes/fragments/exports. Reject cross-session IDs, CSRF failures and stale edits;
  display HTML-like text safely.
- Force audit/log failures and confirm no partial attendance change. Separately
  fail the post-commit stats refresh: attendance remains saved and retrying the
  refresh does not duplicate the event.
- Retry note creation after editing or soft deletion: it does not overwrite or
  recreate the note. Retry an old attendance request after Undo: no new change.
- CLI dry-run writes nothing; applying the same command key twice has one effect.
  Cancellation blocks uploads/attendance but keeps notes accessible to authorized
  roles. Permanently deleted sessions cannot be recreated by stale requests.
- If legacy session_notes content exists, it remains privately accessible; if
  empty, omit that extra display work.
- Preserve existing booking-lifecycle queue regressions and confirm sitter notes
  do not alter queue state or notifications.

The feature is ready when ordinary details can be captured and found during
either upload workflow without a separate spreadsheet or one-off attendance SQL.
