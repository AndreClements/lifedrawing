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
