# Deployment: Life Drawing Randburg

Target: `https://lifedrawing.andresclements.com/randburg`
Host: Dreamhost shared hosting, user `ldrusr`, PHP 8.2

## Initial Setup

1. **Create subdomain** `lifedrawing.andresclements.com` in Dreamhost panel
2. **Create MySQL database** in Dreamhost panel (note host, db name, user, password)
3. **SSH key setup**:
   ```bash
   # Generate key (local machine)
   ssh-keygen -t ed25519 -f ~/.ssh/dreamhost_ldr -C "ldr-deploy"

   # Install on server
   ssh-copy-id -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7
   ```
4. **Clone and install**:
   ```bash
   ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7
   cd ~/lifedrawing.andresclements.com
   git clone https://github.com/andresclements/lifedrawing.git randburg
   cd randburg
   ~/bin/composer install --no-dev --optimize-autoloader
   ```
5. **Configure environment**:
   ```bash
   cp .env.production .env
   nano .env  # fill DB_HOST, DB_USERNAME, DB_PASSWORD, MAIL_* settings
   ```
6. **Root htaccess** (maps `/randburg` to the app):
   ```bash
   cp deploy/dreamhost-root.htaccess ~/lifedrawing.andresclements.com/.htaccess
   ```
7. **Run migrations**:
   ```bash
   php tools/migrate.php run
   ```
8. **Set permissions**:
   ```bash
   chmod -R 755 storage/ public/assets/uploads/
   ```
9. **Test**: visit `https://lifedrawing.andresclements.com/randburg`

## Cron Jobs

Configure via Dreamhost panel (Goodies > Cron Jobs) or `crontab -e`:

```bash
# Process uploaded images (EXIF rotation, WebP conversion, thumbnails) — every 2 min with flock
*/2 * * * * flock -n /tmp/ldr-images.lock php ~/lifedrawing.andresclements.com/randburg/tools/process_images.php >> ~/lifedrawing.andresclements.com/randburg/storage/logs/cron.log 2>&1

# Flush notification queue (digest batching, 5-min window) — every 2 min with flock
*/2 * * * * flock -n ~/lifedrawing.andresclements.com/randburg/storage/flush_notifications.lock php ~/lifedrawing.andresclements.com/randburg/tools/flush_notifications.php >> ~/lifedrawing.andresclements.com/randburg/storage/logs/cron.log 2>&1

# Refresh artist stats daily at 2am
0 2 * * * php ~/lifedrawing.andresclements.com/randburg/tools/refresh-stats.php >> ~/lifedrawing.andresclements.com/randburg/storage/logs/cron.log 2>&1
```

## Mail Configuration

SMTP is configured via `.env`:

```
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=noreply@example.com
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="Life Drawing Randburg"
```

Test with: `php tools/test-mail.php your@email.com`

## Updating

From local machine:

```bash
ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 'cd ~/lifedrawing.andresclements.com/randburg && git pull && ~/bin/composer install --no-dev --optimize-autoloader'
```

### Deploys that carry a migration

`git pull && composer && migrate` joined by `&&` sequences only those commands. Apache keeps
serving and cron keeps firing throughout, so a request arriving between the code landing and
the migration running hits new code against a missing column. Re-running a backfill afterwards
repairs data, never the requests that already failed.

Reversing the order does not help either: the migration file only arrives *with* the pull, so
"migrate first" would find nothing pending and report a false success.

So a migration deploy needs the web gate, not just a cron pause.

**The gate lives in the app's own `.htaccess`, not the document root's.**

This is the part that cost a live outage to learn. Apache does not inherit
mod_rewrite rules from a parent directory's `.htaccess`, and `randburg/.htaccess`
turns `RewriteEngine` on — so every rewrite rule in
`~/lifedrawing.andresclements.com/.htaccess` is silently ignored for requests to
the app. That file's own routing rules are dead code for the same reason; the
live chain is `randburg/.htaccess` → `public/` → `public/.htaccess` → front
controller.

A gate installed at the document root looks convincing: the file parses, and a
syntax error in it breaks every request to the site. But not one of its rules
ever fires. The gate therefore ships in the repository's root `.htaccess` and
arrives with a normal `git pull`.

One-time setup: copy `deploy/maintenance.html` to the document root. It is inert
with the flag down, so it can sit there permanently.

Raise and lower the gate from the **document root**, one level above the app:

```bash
touch ~/lifedrawing.andresclements.com/maintenance.flag   # gate up
rm    ~/lifedrawing.andresclements.com/maintenance.flag   # gate down
```

Two more things that are easy to get wrong, both now handled:

- `R=503` with a `-` substitution returns the status without rewriting, but
  Apache discards the substitution URL for non-redirect codes, so the page has to
  come from `ErrorDocument`. The maintenance page itself must be excluded, or its
  subrequest re-enters the rule.
- `%{ENV:REDIRECT_STATUS}` is **not** empty on the initial request under PHP-FPM
  here — it reads `200`. A condition testing it for emptiness disables the whole
  block, which is exactly what happened on the first attempt.

**There is no bypass token.** An earlier design had one so `_health` could be
checked through the gate. It meant a secret in a tracked file, interpolated into
a regex, and it bought very little: verify over SSH with
`php tools/migrate.php status`, then lower the flag and check `_health`
immediately. If that fails, raise the flag again.

**Testing it needs a positive signal, not an absent one.** An unchanged
`storage/logs/` proves nothing, because a successful request logs nothing either.
Add a temporary marker that appends a line on every request — and put it **after**
`declare(strict_types=1)`, which must be the first statement in the file.
Inserting above it is a fatal error on every request:

```bash
sed -i '3a file_put_contents("/tmp/ldr-marker.log", date("c")." ".($_SERVER["REQUEST_URI"] ?? "?").PHP_EOL, FILE_APPEND);' public/index.php
php -l public/index.php      # always, before any request reaches it
```

| With the flag up | Expect |
|---|---|
| Public GET | 503, maintenance page, no new marker line |
| Public POST | 503, no new marker line |
| GET `/randburg/_health` | 503, no new marker line |
| A static asset | 503, no new marker line |
| Flag removed | 200 on all of the above |

Remove the marker with `git checkout -- public/index.php`.

**Then deploy:**

```bash
SSH="ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7"
APP=~/lifedrawing.andresclements.com/randburg

# 1. Pause both crons and WAIT for in-flight workers. Commenting out a cron line
#    does not stop a process already running mid-batch — storage/process_images.lock
#    and the notification lock are the signals.

# 2. Pull and raise the gate in one breath. The gate is a tracked file, so it
#    arrives WITH this pull; chaining the touch keeps the exposed window to the
#    length of the pull itself.
$SSH "cd $APP && git pull && touch ~/lifedrawing.andresclements.com/maintenance.flag"

# 3. Now behind the gate: install and migrate.
$SSH "cd $APP && ~/bin/composer install --no-dev --optimize-autoloader && php tools/migrate.php run"

# 4. Verify over SSH — there is no way through the gate over HTTP.
$SSH "cd $APP && php tools/migrate.php status | tail -5"

# 5. Cutover purge of source-less queued notifications, while the cron is still paused.
$SSH "cd $APP && php tools/purge-legacy-notifications.php"
$SSH "cd $APP && php tools/purge-legacy-notifications.php --execute"

# 6. Gate down, then check health immediately.
$SSH "rm ~/lifedrawing.andresclements.com/maintenance.flag"
curl -s https://lifedrawing.andresclements.com/randburg/_health

# 7. Restore the crons.
```

### What actually deploys as one unit

**Deploy the branch tip, not an individual commit.** The three commits on
`bookings-release-2` are a reading order, not a release boundary.

The consent commit (`bd47428`) looks self-contained and its own test suite passes
at that commit, which is misleading: the tests that would catch its flaws were
written afterwards. Review found five defects in it, and all five were fixed in
the later commits — withdrawal continuing when the image lock could not be taken,
verification happening after the lock was released, derivative files the database
never recorded being left public, deletion and restoration reading their
candidates before locking, and a health-check bypass keyed on `REQUEST_URI` that
the routing rewrite defeats.

So there are two maintenance windows, not three:

1. Install and test the maintenance gate. No migration.
2. Deploy the branch tip, running both migrations (020 and 021) inside one
   window, then follow both sets of extra steps below.

Splitting this into a privacy-only release first is possible but is not a cherry
pick: the fixes are interleaved with the bookings work across `AuthService`,
`NotificationService`, `StatsService`, `GalleryController`, `Kernel` and
`flush_notifications.php`. It would mean rebuilding the commits hunk by hunk,
with a real risk of silently dropping one of the five fixes — the exact class of
error this review chain kept catching. Ask for it explicitly if the smaller blast
radius is worth that.

### Consent and access release — extra steps

- Confirm `storage/withdrawn/` exists, is writable, and sits **outside** the document root.
- Run the cutover purge **inside the window, while the notification cron is still paused**, so
  nothing is delivered between the purge and the restart:

  ```bash
  php tools/purge-legacy-notifications.php            # dry run first
  php tools/purge-legacy-notifications.php --execute
  ```

  Rows queued before migration 020 carry no source identifier, so deleting an artwork cannot
  find and cancel their mail. They do not age out on their own either: `cleanup()` only removes
  rows already sent. A handful of buffered notifications are lost; that is the right trade
  against a cancellation guarantee that cannot be honoured.

- After the gate comes down, withdraw consent on a test account and **fetch its image URL
  directly**. It must 404. The database column alone has never proved anything.

### Bookings and sitter-queue release — extra steps

Ship with automatic sitter-queue completion **off**, sweep the backlog, then turn it on.

The 30-day notification cutoff is not enough on its own: a sitter stuck from *last* week is
inside that window, so the first facilitator page load after deploy would email them. Hence
the flag.

1. Deploy with `APP_SITTER_AUTO_COMPLETE` unset (it defaults to off).
2. Dry-run the repair and read the whole table before applying:

   ```bash
   php tools/fix-sitter-queue.php
   ```

   Spot-check two or three sitters against their actual session history. Completion accepts a
   past booking that is not marked `no_show`; it deliberately does **not** require
   `attendance = 'attended'`, because nothing writes that for a web booking and requiring it
   would strand the whole backlog.

3. Apply, then run it again. The second run must propose nothing but "leave alone" — that is
   the idempotence check.

   ```bash
   php tools/fix-sitter-queue.php --execute
   php tools/fix-sitter-queue.php
   ```

4. Set `APP_SITTER_AUTO_COMPLETE=true` in `.env` so the live sweep takes over.

The repair sends no email at all: `sitterSessionCompleted()` delivers immediately rather than
queueing, so replaying months of history would blast old sitters with thank-you notes.

## Creating Sessions (incl. off-pattern)

Regular sessions are created through the web form. **Off-pattern sessions** (external venue,
ticketed, sitters booked off-platform) can't be — the form doesn't expose the listing flags —
so create them on prod with `tools/create-session.php`:

```bash
ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 'cd ~/lifedrawing.andresclements.com/randburg && \
  php tools/create-session.php --date=YYYY-MM-DD --venue="..." \
    --no-publish-capacity --booking-note="Tickets via ..." --no-model-join --dry-run'
```

- `--no-publish-capacity` sets `capacity_published=0` (card shows `X/?`).
- `--booking-note="..."` sets `booking_note` (appended to the WhatsApp line as `[1]`).
- `--no-model-join` sets `model_join_enabled=0` (closes the public "Join as Model" route).
- `--dry-run` previews the row and any emails; add `--notify --yes` to create and queue
  new-session emails. Run `php tools/create-session.php --help` for the full flag list.

The three columns ship in migration `019_add_session_listing_flags.sql`, so a normal
`git pull` + `php tools/migrate.php run` (above) is the only deploy step they require.

## Bulk Photo Import

Backfill a whole session's drawings from photos taken on the facilitator's phone.

1. **Stage locally** (Windows) — phone connected via USB in file-transfer mode:
   ```powershell
   # Edit the session→date map at the top of the script, then:
   pwsh tools/stage-phone-photos.ps1   # copies Camera photos into storage/photo-import/{id}/
   ```
   Stage one pose per subdirectory (e.g. `272/1`, `272/2`) if tagging durations. Poses split on
   timestamp gaps of more than ~5 minutes between consecutive shots.
2. **Review** — prune any non-artwork shots from the staged folders. Check orientation from
   contact sheets rendered *with EXIF applied*, not from raw thumbnails: most shots carry a
   correct `Orientation` tag that `ImageProcessor` honours. A rotation done in Windows File
   Explorer rewrites the tag only (same byte size, lossless) and imports correctly — but it also
   means the staged set can change after you copy or transfer it, so re-diff by hash before the
   real import if anything was touched.
3. **Transfer + import**:
   ```bash
   ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 'mkdir -p ~/photo-import/272'
   scp -i ~/.ssh/dreamhost_ldr -r storage/photo-import/272/* ldrusr@69.163.140.7:~/photo-import/272/

   # Verify the transfer — rollup hashes must match (raw byte totals won't; du counts dirs):
   find storage/photo-import/272 -name '*.jpg' | sort | xargs sha1sum | awk '{print $1}' | sha1sum
   ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 \
     'find ~/photo-import/272 -name "*.jpg" | sort | xargs sha1sum | awk "{print \$1}" | sha1sum'

   # Dry-run first, then real import (per pose directory, in order; --pose-duration optional):
   ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 'cd ~/lifedrawing.andresclements.com/randburg && \
     php tools/import-session-photos.php --session=272 --dir=~/photo-import/272/2 --pose-duration="20 min" --dry-run'
   ```
   Under `--dry-run` the reported `pose_index` restarts at 1 for every directory, because nothing
   is written between invocations. On the real run the indexes continue from `MAX+1` as intended —
   import the pose directories in order and they come out chronological.
4. **Process + clean up**:
   ```bash
   ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 'cd ~/lifedrawing.andresclements.com/randburg && \
     php tools/process_images.php --limit=200 && rm -rf ~/photo-import/272'
   ```
5. **Clear the phone** (optional, after verifying the import) — build `storage/photo-import/phone-delete-list.txt` from prod `artwork.upload` provenance (`orig` filenames for the imported sessions), then:
   ```powershell
   pwsh tools/unstage-phone-photos.ps1            # dry-run: shows what matches, deletes nothing
   pwsh tools/unstage-phone-photos.ps1 -Execute   # permanent MTP delete; one confirm dialog per file
   ```
   Only filenames on the list are ever touched; anything not confirmed on prod stays on the phone.

   **If you need to clear the phone first** (the common case — the facilitator wants to unplug
   before the import has run), the delete list can't come from prod provenance yet, so replace
   that safety basis with a verified second copy:
   ```bash
   mkdir -p storage/photo-import/_backup/282
   cp storage/photo-import/282/*.jpg storage/photo-import/_backup/282/
   # verify file-by-file with sha1_file() — not count, not total bytes — then build the list:
   ls storage/photo-import/282/*.jpg | xargs -n1 basename > storage/photo-import/phone-delete-list.txt
   ```
   Run the dry-run and confirm `Listed but NOT on phone: 0` before `-Execute`. Keep the backup
   until the session is verified live on the site.

The importer is rerun-safe and orphan-safe; derivatives are generated by `process_images.php`. To find sessions missing images, query for those with no `ld_artworks` rows where `session_date <= CURDATE()` (future scheduled sessions sort first under a plain date-DESC and aren't relevant).

## Post-Deploy Verification

1. Visit `https://lifedrawing.andresclements.com/randburg/_health` — should return JSON with `status: ok`
2. Check sessions page loads with correct data
3. Test login with facilitator account
4. If migrations ran, verify with `php tools/migrate.php status`
5. Check `storage/logs/` for any errors

## Key Gotchas

- **APP_BASE_PATH**: Must be set to `/randburg` — `Request::capture()` uses this to strip the URL prefix since `.htaccess` hides `/public`
- **Composer path**: Dreamhost doesn't have Composer globally; use `~/bin/composer`
- **CLI tools**: Must load `.env` themselves — they don't go through `public/index.php`
- **flock**: The image processing cron uses `flock` to prevent overlapping runs
