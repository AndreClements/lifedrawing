# Facilitator editing and management

Status: implemented locally on 2026-09-26; not deployed. Migration 024 and deployment preflight are required.
Created and revised: 2026-09-26.
Companion: [Quick session notes during uploads](session-upload-notes.md).

## Agreed scope

Let any logged-in facilitator or admin handle routine corrections on the site:
session notes, attendance, participant roles, session details, artwork captions
and pose details. Include a private People directory showing any user's available
contact details, even when they have no booking or public profile.

Cancellation is a retained state, visible only to facilitators/admins through the
site. A new cancellation is allowed **only before the scheduled session start and
before any photographs have been uploaded**. At the start time or later, it is a
session; use attendance and notes to describe what happened. Do not add a
cancellation-specific image archive or authenticated image-serving system.

The implementation includes the everyday management tools and retained cancellation,
with their shared guards and queue handling.
A dashboard, session templates, new notification controls, advanced image
reordering, replacement/rotation and account merging can wait.

## Additional agreed scope: optional profile pictures

Users can upload, replace or remove a non-nude profile picture in Edit Profile.
It is highly recommended for sitters and never required for participation.
The form requires confirmation that the picture is non-nude; there is no automated
nudity classifier. Facilitators/admins can remove unsuitable pictures from People.

Reuse `users.avatar_path`, re-encode JPEG/PNG/WebP inputs into a 512px square JPEG,
and remove the previous public file on replacement or removal. Show pictures on
profiles, participant directories and the sitter queue. Consent withdrawal uses
the existing file handling; this does not add a cancellation media archive.

## Verification and release status

Local checks: 77 management/CLI integration checks, the existing booking (50), consent
(25) and create-session (36) checks, plus 12 browser checks. Coverage includes
stale edits, nested rollback, post-commit statistics failure, cancellation racing
an upload, upload retry identity and profile picture withdrawal.
The production legacy-cancellation counts remain a deployment preflight.
See [release instructions](../deploy/README.md#facilitator-management-release).

## Original code review findings

- Sessions already have a `cancelled` status, and the CSV importer uses it.
  The pasted review reports three cancelled production sessions, four participant
  rows and no artworks. Those counts have not been independently verified here:
  confirm them read-only before implementation or deployment.
- Several public queries and statistics omit a cancellation filter. Cancelled
  dates can enter the session/weekend sequences and incorrectly dent streaks.
  Web sessions are not automatically marked completed, so excluding cancelled
  must not become a requirement for `status = 'completed'`.
- Participant tools allow any facilitator/admin. Upload, artwork deletion and
  claim resolution currently use session ownership/admin checks. Unify these.
- Web uploads omit `pose_number`; the CLI importer sets it. Existing web images
  fall back to grouping consecutive rows by label and duration.
- `Connection::transaction()` always starts a transaction. Queue add/remove
  operations open their own, so they cannot currently compose atomically.
- Same-day queue timing fixes are already in commit `c97b0b1`, with coverage in
  `tools/test-booking-lifecycle.php`. Preserve them; they are not new work.
- Cancellation currently deletes the session. Ordinary artwork deletion also
  removes files. Neither is an existing reversible archive feature.

## First step: existing cancelled records

Fix these independently of the new management interface:

1. Exclude cancelled sessions from public lists and their pagination counts,
   past-session pages, home totals, gallery, profile history/portfolios, search,
   sitemap and structured data. Audit dashboard, fragments, WhatsApp schedule
   and export queries too, even where filters already exist.
2. Ordinary viewers receive 404 for direct cancelled session/artwork routes and
   their HTML/JSON/HTMX endpoints. Facilitators/admins may inspect them through
   authorized management views; role access must not put them in public outputs.
3. Exclude cancelled sessions from attendance totals, last attendance dates,
   no-show/role/artwork totals and both session/weekend opportunity sequences.
   Use `status <> 'cancelled'` alongside each query's existing date/visibility
   rules. Run the existing `StatsService::refreshAll()` after deploying the fix.
4. Update `can_see_names()` in `app/View/helpers.php`: a booking on a cancelled
   session must not qualify someone to see real names. Preserve role exemptions.
5. Add cancelled-state guards to existing mutation routes and fix the four queue
   paths listed below. Retained cancelled rows already exist, so these are useful
   before switching the cancellation action from deletion to retention.

Use local cancelled-session fixtures for tests. Production counts in the review
are a preflight check, not test fixtures to mutate.

## Shared implementation foundations

### Permission and write boundaries

Use one shared `canManageSession($session)` policy: authenticated users with
`hasRole('admin', 'facilitator')` can manage any session. Apply it to notes,
attendance, role/detail/pose edits, cancellation and restoration, and migrate the
existing upload, artwork-delete and claim-resolution ownership guards to it.
Keep ordinary users' own booking, commenting and claim-submission rules separate.

Check permission on every route, including reads and fragments. State eligibility
is a separate check: a facilitator role does not permit ordinary writes into a
cancelled session. Only private notes and explicit restoration remain editable
there. Use existing CSRF protection and validation conventions for web writes.
For mutations that can race cancellation, recheck session state under the same
session-row lock before committing the change.

Add nested transaction support with savepoints to `Connection::transaction()`.
Use the same connection for participant, queue, session and required audit writes.
An inner success must not commit an outer transaction; rollback and exception
handling must leave the transaction depth correct. Test both inner and outer
failures before relying on this for management operations.

Use checked audit inserts for required records: the existing
`ProvenanceService::log()` suppresses failures. Do not overhaul every existing
logger call. Commit authoritative changes before refreshing statistics. A cache
refresh failure reports saved changes with a refresh needing retry; it must not
undo the edit or invite a duplicate operation. Use `refreshAll()` after session
date changes, cancellation or restoration, rather than calculating affected users.

Extract the existing `SitterQueueService::sessionIsOver()` into a shared helper
beside `session_starts_at()` in `app/View/helpers.php`. Reuse it for queue
classification and no-show eligibility, preserving current timezone, fallback
time/duration and end-boundary semantics. Cancellation uses the **start** boundary.

### Pose identity before pose editing

Assign a `pose_number` in both web-upload insert branches. Each new batch gets
one number, shared by every image in it; a retry keeps that number.

Use a stable batch token, retained by the upload form until the batch completes,
with a durable mapping unique on session plus token. Allocate a number under a
session-row lock only for a new token. Record successful per-file results so a
partial retry skips saved images and resumes the same pose. Do not allocate
`MAX(pose_number) + 1` again on a retry.

Before adding a numbered batch to a legacy session, or first editing its poses,
assign numbers to existing NULL rows from their current contiguous visual groups,
preserving existing numbered poses and display order. Show ambiguous grouping for
review rather than guessing. `pose_number` identifies a pose; `pose_index`
orders images. Preserve artwork IDs, claims, comments and URLs.

## First-release interface

Provide **Manage session** next to Upload Artworks, with compact Details, People,
Photos and Notes sections. Keep the session date/title visible. Small edits use
ordinary Save/Cancel controls and clear saved/error feedback. Errors preserve
entered values. Notes and contact panels must not discard selected upload files.

### Roster, attendance and notes

- Show Artist, Sitter, Facilitator and Observer separately. Sitter is the UI label
  for the database role `model`; do not introduce another role value.
- Reuse participant search/add and stub creation. Label removal **Remove booking**;
  use No-show for an absence so the historical booking remains.
- Implement quick notes, lateness, loo breaks, early departure, same-day no-show
  and Undo through the companion plan. Never infer attendance or authorship from
  photographs.
- Change role in place, preserving the participation ID, creation time, tentative
  flag and attendance. Keep facilitator reassignment outside this control.
- Check the unique session/user/role key before saving. If the destination row
  already exists, show the conflict for reconciliation; never silently delete it.
- A correction to a finished session must not call `onModelAdded()` to schedule
  a new waiting request onto that finished sitting. A future booking change
  reconciles the participant and applicable queue entry in one transaction.
- Explicit target-state commands must be safe to retry. Role-only changes do not
  decrease total attendance, which currently counts all roles. They do not create
  artwork claims; link existing claims for separate review if needed.

### People and contact details

Add **People** to management navigation with paginated search across all users by
display name, pseudonym, email, WhatsApp number or user ID. Phone search should
ignore ordinary spacing/punctuation without rewriting the stored value. Include
users with no bookings, stubs and pending/withdrawn-consent accounts.

An authorized user panel shows:

- Display name, pseudonym, stable ID and registration date.
- Recorded email and WhatsApp number; show missing values plainly.
- Copy email/number and usable Email, Call and WhatsApp links. Opening a panel
  sends nothing; contact actions require a deliberate click.
- Account role, stub/registered status and consent state as separate facts.
- Available sitter day preferences, auto-rejoin setting and facilitator sitter
  notes, plus links to relevant bookings/history and an available public profile.

Link this same panel from session rosters, participant search and the queue. Keep
a direct management page so access does not depend on a booking. Do not guess
contact details or a country code. Synthetic `*.stub@local` addresses are
placeholders: show No email recorded and disable the email action.

Use existing user fields, with dedicated authorized routes such as
`/manage/users` and `/manage/users/{id}`. Require the facilitator/admin role
before querying or returning details, including search/HTMX/JSON requests. Return
an explicit field allowlist, never `users.*`: passwords, hashes, API/reset/remember
tokens and session credentials are not contact details. Management responses are
private/non-cacheable. Do not add these fields to public profiles or shared exports.
Viewing a panel does not change consent, account details or notification preferences.

### Session details

Edit title, subtitle/format, date, start time, duration, venue, public description
and the existing model-sex listing field. Keep public description distinct from
private notes. Expose `max_capacity`, `capacity_published`, `booking_note` and
`model_join_enabled` with plain labels.

A capacity reduction below current bookings shows the booked count without
removing anyone. Keep artist capacity separate from sitter/facilitator counts.

For date/time changes, show the before/after values and validate booking cutoffs,
attendance eligibility and queue references. Refresh all stats after a date edit
commits. Ordinary text edits do not send messages. Keep the existing WhatsApp
schedule output available; new notification controls and templates are deferred.

### Caption and pose corrections

Edit an artwork caption and a whole pose's label/duration. Show the number of
images affected by a pose edit and preserve artwork IDs, URLs, claims, comments,
uploader history and grouping. Reject stale edits using the applicable existing
values or an updated timestamp. Pose metadata never establishes authorship.

Moving images between poses or sessions, pose reordering and bulk gallery-order
editing are later work; they should not hold up basic caption/duration corrections.

## Release 1b: retained cancellation

Replace destructive `SessionController::cancel()` with a state transition that
retains the session, participants, notes and history. Add the previous status,
cancellation actor/time and optional reason, with a checked audit record.

For a **new transition**, validate all of the following on the server:

- The actor is a facilitator/admin and the session exists in a cancellable state.
- Current time is strictly before `session_starts_at($session)`, using the
  application's Africa/Johannesburg time handling. At the exact start time and
  afterwards, refuse cancellation regardless of whether anyone attended.
- No artwork upload has been recorded for the session, including rows subsequently
  hidden or removed. Do not suggest deleting/hiding photos or changing the date
  as a workaround.

The form uses the same conditions, with a plain explanation when unavailable.
Lock/re-read the session and recheck eligibility when applying the transition.
Web and CLI uploads must use the same session-lock/state-check protocol when
recording artwork, so cancellation cannot race an in-flight upload into publishing
on a cancelled session. An already-cancelled retry returns the existing result
without rerunning queue or notification effects.

Keep **Cancelled** as a private filter for any facilitator/admin. Show retained
details, roster, notes, reason and Restore. Exclude cancelled sessions from normal
lists and all public-facing outputs, even when generated by a facilitator. Other
roles cannot retrieve them by direct request or by changing a filter parameter.

### Required guards and queue treatment

Refuse ordinary activity on cancelled sessions in every entry point, including:

- Session join/add/remove/attendance/role/detail actions.
- `AuthController::fulfillIntent()` joining and claiming after registration/login.
- Claim submission/resolution, gallery comments, uploads, image edits/deletion.
- `PoseController` scheduling and `tools/import-session-photos.php`.
- Any alternate route or CLI that writes the same session data.

Private notes remain readable/editable by facilitators/admins; restoration is the
explicit route back to normal activity. Hiding buttons is not a server-side guard.

In `SitterQueueService`, exclude cancelled sessions from:

1. The scheduled-session evidence in classification step 1.
2. The waiting-entry completed-sitting evidence in step 2.
3. `earliestFutureBooking()`.
4. The step-4 session-existence check: cancelled counts as unavailable, so a linked
   scheduled entry can return to waiting.

On cancellation, preserve the existing `onModelRemoved()` reconciliation and
`cancelQueued()` calls for the session and artwork sources. Coordinate these DB
writes with the state transition; preserve independent later queue requests. If
the existing workflow sends cancellation notices, send them only after a committed
transition, with duplicate protection and no private management links.

Restore explicitly to the recorded previous status after checking current booking
and queue conflicts. Do not schedule a queue entry onto an already-finished
session or replay old messages. Refresh all statistics after cancellation/restore.
Legacy cancelled records without previous-status metadata need an explicit valid
target state; do not guess that the session was completed. Already-deleted sessions
cannot be recovered by changing a flag.

### Keep image handling simple

Because new cancellations require no recorded uploads, this feature does not move
image files, add a private media endpoint, change image-worker storage or build a
media recovery manifest. Existing art publication and consent-withdrawal behaviour
stay in place. Notes and contact details still have ordinary role-based privacy.

Verify the review's report that existing cancelled sessions have no artworks. If
an unexpected legacy exception exists, flag it for individual correction before
calling that record fully hidden; page filtering alone does not revoke direct
public image URLs. Do not silently add an image-privacy project to this release.

## Implementation order

1. Fix existing cancelled-session visibility, statistics, name access, write guards
   and queue handling; refresh stats after deployment.
2. Add shared role authorization, nested transactions and the shared session-end
   helper. Establish web batch/pose identity before pose-dependent edits.
3. Deliver Manage session with web notes/attendance, then the small CLI notes tool.
   Include People/contact details in this first release.
4. Add role corrections and session detail edits.
5. Add caption and pose label/duration edits.
6. Replace destructive cancellation with retained cancellation, enforcing the
   pre-start/no-upload conditions and explicit restoration.

Keep migrations additive, choose free migration numbers at implementation time
and coordinate deployment with active imports and other work. This plan does not
authorize a deployment or change production.

## Acceptance checks

- Existing cancelled fixtures disappear from public lists/direct routes, totals,
  last attendance and both streak sequences. Their bookings do not unlock names.
- Facilitators/admins can manage sessions regardless of ownership and find contact
  details for any account, including stubs and users with no bookings. Guests and
  ordinary participants cannot access notes, contacts or cancelled management data.
- Notes and contact panels work on a phone without losing upload file selections.
- Role corrections preserve participation/history and do not reschedule a new
  waiting request onto a historical session. Participant and queue writes either
  both commit or both roll back, including forced inner/outer transaction failures.
- Preserve the same-day queue regression tests from `c97b0b1`; do not manipulate
  request timestamps or regress its completed-sitting evidence rule.
- Two web batches receive distinct poses; retries and partial retries retain their
  pose and saved images. Legacy grouping remains intact after normalization.
- Caption/pose/detail edits keep artwork identity and claims intact. Date edits
  trigger `refreshAll()` after commit; a refresh failure leaves the saved edit
  intact and reports the separate retry needed.
- Cancellation succeeds before start with no uploads, and is refused at/after
  start or with any recorded artwork. Test stale forms, direct requests, duplicate
  submits and an upload racing cancellation.
- A cancelled session retains its bookings/notes but refuses join, claim, comment,
  scheduling and uploads through both direct routes and registration intents.
  Queue entries and queued mail reconcile without duplicate effects.
- Restore is explicit, checks conflicts and does not replay notifications or assign
  a completed sitting to a fresh queue request.

Use focused local database/route checks, existing booking/consent suites and
phone-sized browser checks. No live participant fixtures.

## Later improvements

Consider a read-only facilitator overview, session templates, deliberate schedule
change notifications, photo rotation/replacement, reversible gallery hiding,
queue ordering and account corrections/merging after the core tools are in use.
Scope each separately. A future overview must not embed queue endpoints that
perform a sweep merely when viewed.
