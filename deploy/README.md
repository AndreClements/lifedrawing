# Deployment: Life Drawing Randburg

Target: `https://lifedrawing.andresclements.com/randburg`
Host: Dreamhost shared hosting

## Steps

1. **Create subdomain** `lifedrawing.andresclements.com` in Dreamhost panel
2. **Create MySQL database** in Dreamhost panel (note host, db name, user, password)
3. **SSH in** and clone:
   ```bash
   cd ~/lifedrawing.andresclements.com
   git clone https://github.com/andresclements/lifedrawing.git randburg
   ```
4. **Install dependencies**:
   ```bash
   cd randburg
   composer install --no-dev --optimize-autoloader
   ```
5. **Configure environment**:
   ```bash
   cp .env.production .env
   nano .env  # fill DB_USERNAME, DB_PASSWORD
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

## Seed demo data (optional)

```bash
php tools/seed.php
```

## Updating

```bash
cd ~/lifedrawing.andresclements.com/randburg
git pull
composer install --no-dev --optimize-autoloader
php tools/migrate.php run
```
