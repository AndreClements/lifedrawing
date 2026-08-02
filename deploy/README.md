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

If there are new migrations:

```bash
ssh -i ~/.ssh/dreamhost_ldr ldrusr@69.163.140.7 'cd ~/lifedrawing.andresclements.com/randburg && php tools/migrate.php run'
```

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
