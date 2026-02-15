# Life Drawing Randburg

A web application for the Life Drawing Randburg community — quiet, focused gatherings for artists of all levels to engage in the practice of drawing the human form from life. Hosted by [Andre S Clements](https://andresclements.com) since 2017.

The model is not an object but a co-participant. What emerges is not just skill, but relation — attunement, humility, and the soft but intense discipline of sustained observation.

## What This Is

A digital home for LDR that enables:

- **Session management** — schedule sessions, track participants and roles (artist, model, facilitator, observer), capacity tracking (X/7)
- **Artwork archive** — facilitator uploads batches of drawings per session, with automatic image processing (EXIF rotation, 10MP cap, WebP thumbnails)
- **Claim system** — artists and models claim their work/likeness after sessions, building personal portfolios
- **Comments** — conversation on individual artworks, with artist/model comments surfaced first
- **Consent system** — `pending → granted → withdrawn` state machine, enforced by middleware before identity-exposing operations
- **Strava-for-artistry** — personal dashboard with attendance streaks, session history, milestone tracking
- **Public profiles** — artists and models build visible portfolios through participation, with name privacy gating (real names only visible to fellow session participants)
- **FAQ** — community information page with accordion layout

Eventually, this becomes the first "table" in a modular **Artistry Caffe** platform — a template for community spaces where people can define and manage their own creative gatherings.

## Architecture

### What it borrows from Laravel

If you've worked with Laravel, you'll recognise some of the bones:

- **Service container** with singleton/factory bindings and `app()` global resolver
- **Middleware pipeline** — request flows through global middleware, then route-specific middleware, then the handler
- **Named routes** with `route('sessions.show', ['id' => 1])` URL generation
- **Blade-like helpers** — `e()` for escaping, `csrf_field()`, `asset()` with cache-busting, `active_if()` for nav state
- **Migration runner** — sequential SQL files, applied/pending tracking, CLI tool
- **Request/Response** value objects with factory methods (`Response::json()`, `Response::redirect()`)
- **Controller conventions** — `index`, `show`, `create`, `store` method naming

### What it doesn't borrow

- **No Eloquent/ORM** — a `QueryBuilder` provides fluent queries (`$this->table('ld_sessions')->where(...)->get()`), but there are no model classes with magic methods, no relationship loading, no attribute casting. SQL is readable and close to the metal.
- **No Blade** — PHP templates with `<?= ?>` and `<?php ?>`. Layouts work via `extend`/`section`/`yield` in the Template engine, but there's no compilation step, no directive syntax, no template caching.
- **No Artisan** — a handful of CLI tools (`migrate.php`, `seed.php`, `refresh-stats.php`, `process_images.php`) that do exactly what they say. No code generation, no queue workers, no scheduler abstraction.
- **No service providers** — services are wired directly in `Kernel::wireServices()`. Explicit, readable, ~30 lines.
- **No event system** — stats refresh and provenance logging are called inline. When there are 5 controllers, an event bus is a premature abstraction.
- **No package ecosystem** — one external dependency (HTMX, loaded via CDN). Composer provides the autoloader and nothing else.

### What it does differently

**Axiological architecture.** The [README methodology](https://github.com/andreclements/README) embeds ethical commitments directly into code structures:

| Concept | Implementation |
|---|---|
| **CARDS** (Competence, Autonomy, Relatedness, Dignity, Safety) | No quality rankings. Opt-in claims. Session-centric design. `DignityException` halts objectifying operations. CSRF + prepared statements + image validation. |
| **Consent state machine** | `pending → granted → withdrawn` on every user. `ConsentGate` middleware enforces before data operations. Withdrawal hides but doesn't delete (provenance preserved). |
| **Provenance logging** | Every significant action recorded in `provenance_log` with who/what/when/context JSON. Every artwork traces back to session → uploader → claimant. |
| **Parametric authorship** | "Govern via slope, not policing." Claiming is frictionless; uploading requires facilitator role. Default visibility is `public` — consent happens in the room, not in software gates. Stats reward attendance, not output volume. |
| **Try-catch-AND-YET** | `AppException` carries an `andYet` field — honest self-critique logged alongside the error. "This halts the claim but doesn't notify the claimant why." |

**Module system.** Each "table" (community space) is a self-contained module with its own controllers, views, migrations, and routes, registered via a `module.php` manifest. The life drawing module mounts at root `/` because it IS the site for now. Future modules (pottery circle, music jam) get their own URL prefix and follow the same contract.

### Directory structure

```
lifedrawing/
├── public/                     # Document root (Apache points here)
│   ├── index.php               # Front controller
│   ├── .htaccess               # URL rewriting
│   └── assets/                 # CSS, JS, favicon, uploads
│
├── app/                        # Kernel (~15 files)
│   ├── Kernel.php              # Bootstrap, DI, middleware pipeline, dispatch
│   ├── Router.php              # Pattern-matching with {param} extraction
│   ├── Container.php           # Minimal DI (PSR-11 compatible interface)
│   ├── Request.php             # HTTP request wrapper
│   ├── Response.php            # HTTP response with factory methods
│   ├── Database/               # Connection, Migration, QueryBuilder
│   ├── Middleware/              # CSRF, Auth, ConsentGate, RateLimiting
│   ├── Exceptions/             # AppException, DignityException, ConsentException
│   ├── Services/               # Auth, Upload, ImageProcessor, Provenance, Stats
│   └── View/                   # Template engine + helpers
│
├── modules/
│   └── lifedrawing/            # First "table"
│       ├── module.php          # Manifest (slug, routes, migrations, nav)
│       ├── Controllers/        # Auth, Session, Gallery, Claim, Profile, Dashboard, Page, Landing
│       ├── Models/             # Data models
│       ├── Repositories/       # Data access layer
│       ├── Views/              # PHP templates with layouts
│       └── migrations/         # Module-specific SQL (12 migrations)
│
├── config/                     # app.php, database.php, auth.php, axioms.php
├── database/                   # Core migrations (5) + seeds
├── deploy/                     # Deployment scripts
├── storage/                    # Logs, cache, sessions
└── tools/                      # CLI: migrate, seed, refresh-stats, process-images, import-csv
```

### Stack

- **PHP 8.2+** — typed properties, enums, readonly, named arguments, match expressions
- **MySQL/MariaDB** — InnoDB, utf8mb4, prepared statements only
- **Apache** with mod_rewrite (XAMPP for local dev)
- **HTMX** (14KB CDN) — claim buttons, session join, gallery filtering
- **Vanilla CSS** — custom properties, CSS Grid, `prefers-color-scheme` dark mode
- **Zero npm/webpack/build pipeline** — the art is the product, not the tooling

## Setup

```bash
# 1. Clone and install autoloader
composer install

# 2. Create database
mysql -u root -e "CREATE DATABASE IF NOT EXISTS lifedrawing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 3. Run migrations
php tools/migrate.php run

# 4. Seed demo data (optional)
php tools/seed.php --fresh

# 5. Configure Apache to serve public/ as document root
#    Or access via: http://localhost/lifedrawing/public/
```

### Configuration

Copy `.env.example` to `.env` and edit for your environment. Default assumes XAMPP local dev (root, no password, localhost).

### Demo accounts

All demo users have password `password123`:

| User | Role | Email |
|---|---|---|
| Andre Clements | facilitator | andre@example.com |
| Sarah Ndlovu | participant | sarah@example.com |
| James van Wyk | participant | james@example.com |
| Palesa Mokoena | participant | palesa@example.com |
| David Nkosi | participant | david@example.com |

## CLI Tools

```bash
php tools/migrate.php run              # Apply pending migrations
php tools/migrate.php status           # Show migration status
php tools/migrate.php create-db        # Create database if not exists
php tools/seed.php                     # Seed demo data
php tools/seed.php --fresh             # Truncate all tables, then seed
php tools/refresh-stats.php            # Recalculate all artist stats
php tools/process_images.php           # Process unprocessed artworks (EXIF, WebP, thumbnails)
php tools/process_images.php --limit=20    # Process at most 20 images
php tools/process_images.php --reprocess   # Reset all and reprocess
php tools/import-csv.php [path]        # Backfill sessions/participants from CSV
```

## Routes

### Public

| Method | Path | Description |
|---|---|---|
| GET | `/` | Landing page |
| GET | `/sessions` | Session list (upcoming ASC, past DESC) |
| GET | `/sessions/{id}` | Session detail + artworks |
| GET | `/gallery` | Browse all artworks |
| GET | `/artworks/{id}` | Individual artwork + claims + comments |
| GET | `/artists` | Artist directory |
| GET | `/sitters` | Model/sitter directory |
| GET | `/profile/{id}` | Artist/model profile (name privacy gated) |
| GET | `/faq` | FAQ page |
| GET | `/_health` | Health check (JSON) |

### Auth (rate-limited: 5 attempts / 15 min)

| Method | Path | Description |
|---|---|---|
| GET/POST | `/login` | Login |
| GET/POST | `/register` | Registration |
| GET | `/logout` | Logout |
| GET/POST | `/consent` | Consent disclosure |
| GET/POST | `/forgot-password` | Request password reset |
| GET/POST | `/reset-password` | Reset password (with token) |

### Authenticated

| Method | Path | Description | Notes |
|---|---|---|---|
| GET | `/profile/edit` | Edit own profile | |
| GET | `/dashboard` | Personal stats dashboard | |
| GET | `/sessions/create` | Create session form | Facilitator |
| POST | `/sessions` | Store new session | Facilitator |
| GET | `/sessions/{id}/upload` | Upload form | Facilitator |
| POST | `/sessions/{id}/upload` | Upload artworks | Facilitator, rate-limited (10/hr) |
| POST | `/artworks/{id}/delete` | Delete artwork + files | Facilitator |
| GET | `/claims/pending` | Pending claims list | Facilitator |
| POST | `/claims/{id}/resolve` | Approve/reject claim | Facilitator |

### Consent-gated (auth + consent middleware)

| Method | Path | Description |
|---|---|---|
| POST | `/sessions/{id}/join` | Join a session |
| POST | `/artworks/{id}/claim` | Claim an artwork |
| POST | `/artworks/{id}/comment` | Comment on artwork |
| POST | `/profile/edit` | Update profile |
| POST | `/dashboard/refresh` | HTMX stats refresh |

## Production

### Cron

```bash
# Process uploaded images (EXIF rotation, WebP conversion, thumbnails) — every 5 min with flock
*/5 * * * * flock -n /tmp/ldr-images.lock php /path/to/tools/process_images.php >> /path/to/storage/logs/cron.log 2>&1

# Refresh artist stats daily at 2am
0 2 * * * php /path/to/tools/refresh-stats.php >> /path/to/storage/logs/cron.log 2>&1
```

### Environment

For production, set in `.env`:

```
APP_ENV=production
APP_URL=https://lifedrawing.andresclements.com/randburg
APP_BASE_PATH=/randburg
```

`APP_BASE_PATH` tells `Request::capture()` how to strip the URL prefix — needed when the app lives in a subdirectory and `.htaccess` hides `/public` from URLs.

Ensure `storage/` directories are writable by the web server and HTTPS is enforced.

## Session continuity

This project follows the [methodology_CI](https://github.com/andreclements/README/blob/main/docs/methods/METHODOLOGY_CI.md) session continuity protocol. The plan file at `.claude/plans/` serves as the staging artifact. Memory files in `.claude/projects/` capture patterns and decisions across sessions.

## License

MIT
