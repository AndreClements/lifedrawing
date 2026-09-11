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

**Install the maintenance gate first.** It cannot ship in the deploy it is meant to protect.
Copy `deploy/maintenance.html` to the document root and merge the gate block from
`deploy/dreamhost-root.htaccess` into `~/lifedrawing.andresclements.com/.htaccess`, replacing
`CHANGE_ME` with a real token. Keep that token on the server only, never in git.

Note the three things that are easy to get wrong, all handled in the template:

- The flag lives at the **document root** (`~/lifedrawing.andresclements.com/maintenance.flag`),
  not under `randburg/public/`. The app sits one level below the document root.
- `R=503` alone does **not** serve the page — Apache discards the substitution for non-redirect
  status codes, so the page comes from `ErrorDocument`.
- The bypass is a GET, to `_health`, with the token. A bare token check would exempt every
  route and method including writes, which is what the gate exists to prevent.

Test it on its own before relying on it. An unchanged `storage/logs/` proves nothing, since a
successful request logs nothing anyway — add a temporary marker line at the top of
`public/index.php`, confirm the table below, then remove it.

| With the flag up | Expect |
|---|---|
| Public GET | 503, maintenance page, no marker line |
| Public POST | 503, no marker line |
| GET `_health` without the token | 503, no marker line |
| GET `_health` with the token | 200 JSON, marker line written |
| POST with the token | 503 — the bypass must not extend to writes |
| Flag removed | Normal routing on every route |

**Then deploy:**

```bash
# 1. Gate up
ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 'touch ~/lifedrawing.andresclements.com/maintenance.flag'

# 2. Pause both crons and WAIT for in-flight workers. Commenting out a cron line
#    does not stop a process already running mid-batch — storage/process_images.lock
#    and the notification lock are the signals.

# 3. Pull, install, migrate
ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 'cd ~/lifedrawing.andresclements.com/randburg && git pull && ~/bin/composer install --no-dev --optimize-autoloader && php tools/migrate.php run'

# 4. Verify, through the bypass token. Without it the check just returns the
#    maintenance page and proves nothing.
ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 'cd ~/lifedrawing.andresclements.com/randburg && php tools/migrate.php status'
curl -s 'https://lifedrawing.andresclements.com/randburg/_health?maint=YOUR_TOKEN'

# 5. Gate down, restore the crons
ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 'rm ~/lifedrawing.andresclements.com/maintenance.flag'
```

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
