# BD-001: First-time setup

After cloning or pulling the FlexiQueue repo with Laravel + Inertia + Svelte + DaisyUI in place, choose one of the setup options below.

---

## Option A: Laravel Sail (Recommended)

Laravel Sail provides a consistent Docker-based development environment with PHP, MariaDB, and all dependencies pre-configured.

### Prerequisites

- **Docker Desktop** (Windows/Mac) or **Docker + Docker Compose** (Linux)
- **Git** (for cloning the repo)

**No local PHP, Composer, or Node.js required** — everything runs in Docker.

### One-time setup (Docker-only)

From the project root, run:

```bash
./scripts/sail-setup.sh
```

This script:

1. Installs PHP dependencies via `composer install` (Docker)
2. Creates `.env` from `.env.example`
3. Runs `sail:install` with MariaDB + Redis (Docker)
4. Starts Sail containers
5. Generates the application key
6. Installs Node dependencies and builds assets inside the container

**On Windows:** Use WSL or Git Bash to run the script (requires bash).

**Port conflicts:** If another Sail or web app is using ports 80, 5173, or 3306, stop it first (`docker compose down` in that project, or `./vendor/bin/sail down`).

### Manual setup (if you prefer step-by-step)

If you'd rather run commands manually or the script fails:

1. **Composer install** (no local PHP needed):

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php82-composer:latest \
    composer install --ignore-platform-reqs
```

2. **Environment file**:

```bash
cp .env.example .env
```

3. **Sail install with MariaDB + Redis** (non-interactive):

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php82-composer:latest \
    php artisan sail:install --with=mariadb,redis --no-interaction
```

4. **Start Sail**:

```bash
./vendor/bin/sail up -d
```

5. **Application key**:

```bash
./vendor/bin/sail artisan key:generate
```

6. **Node dependencies and build**:

```bash
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

7. **Run migrations** (when available):

```bash
./vendor/bin/sail artisan migrate
```

**Tip**: Add `alias sail='./vendor/bin/sail'` to your shell profile for shorter commands.

### Daily workflow

1. **Start containers**:

```bash
./vendor/bin/sail up -d
```

2. **Run Vite dev server** (choose one):

**Option 1 - In Sail container** (recommended for consistency):

```bash
./vendor/bin/sail npm run dev
```

**Option 2 - On host** (for WSL/Windows if you have file watch issues in the container):

```bash
npm run dev
```

(Requires Node.js on the host. Vite config may need `server.host: '0.0.0.0'` if accessing from another device.)

3. **Access the app**: Open http://localhost — you should see the FlexiQueue welcome page (Svelte + DaisyUI theme).

4. **Real-time (optional):** If `BROADCAST_CONNECTION=reverb` in `.env`, the app will try to send events to Reverb. You must run the Reverb server in a **separate terminal** or you will see *"cURL error 7: Failed to connect to localhost port 6001"* when any broadcast is triggered:
   ```bash
   ./vendor/bin/sail artisan reverb:start
   ```
   Keep this running while using real-time features. To avoid the error when not using Reverb, set `BROADCAST_CONNECTION=null` in `.env`.

5. **Stop containers** (when done):

```bash
./vendor/bin/sail down
```

### Common Sail commands

```bash
# Artisan
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed
./vendor/bin/sail artisan tinker

# Composer
./vendor/bin/sail composer require package/name
./vendor/bin/sail composer update

# NPM
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

# Database
./vendor/bin/sail mysql  # Access MariaDB CLI

# Logs
./vendor/bin/sail logs

# Real-time WebSocket (BD-002: Laravel Reverb on port 6001)
./vendor/bin/sail artisan reverb:start
```

**Reverb:** For real-time features (e.g. live queue updates), run `./vendor/bin/sail artisan reverb:start` in a separate terminal. Port 6001 is published in `compose.yaml` so the browser (on the host) can connect to Reverb. Test at http://localhost/broadcast-test (fire a broadcast and confirm the Svelte page receives it). If you changed compose ports, run `./vendor/bin/sail down` then `./vendor/bin/sail up -d` so the new port is exposed.

---

## Option B: Bare metal

If you prefer to run PHP, Node, and MariaDB directly on your machine without Docker, follow these steps.

### Prerequisites

- **PHP 8.2+** with required extensions (see Laravel 12 docs)
- **Composer**
- **Node.js 20+** and npm
- **MariaDB 10.6+** (running locally or accessible)

### Setup

1. **Install PHP dependencies**:

```bash
composer install
```

2. **Set up environment**:

```bash
cp .env.example .env
php artisan key:generate
```

Update `.env` with your MariaDB connection details:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flexiqueue
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

3. **Install Node dependencies**:

```bash
npm install
npm run build
```

**Note**: If you see **"Could not resolve entry module index.html"** or path errors, the build may be running from a bad cwd (e.g. Windows when the project is under WSL). Run from a WSL bash prompt or use Sail: `./vendor/bin/sail npm run build`.

4. **Run migrations** (when available):

```bash
php artisan migrate
```

### Daily workflow

1. **Start the app**:

```bash
php artisan serve
```

2. **In another terminal** (optional, for dev):

```bash
npm run dev
```

3. **Access the app**: Open http://localhost:8000 — you should see the FlexiQueue welcome page (Svelte + DaisyUI theme).

---

## E2E tests (Playwright)

Browser tests use Playwright and run against the live app. **Use Sail for all npm and E2E** (per [.cursor/rules/environment.mdc](../.cursor/rules/environment.mdc)): install deps with `./vendor/bin/sail npm install`; do not run bare `npm install`. **The app must be running** (Sail and, for Inertia pages, built assets or `./vendor/bin/sail npm run dev`).

1. Start Sail: `./vendor/bin/sail up -d`
2. Install npm deps (including Playwright): `./vendor/bin/sail npm install`
3. (Optional) First-time only — install Playwright browser binaries: `./vendor/bin/sail npx playwright install`
4. (Optional) Start Vite for dev assets: `./vendor/bin/sail npm run dev` or build once: `./vendor/bin/sail npm run build`
5. Run E2E: `./vendor/bin/sail npx playwright test` (or `./vendor/bin/sail npm run test:e2e`)

Tests live in `e2e/`; config is `playwright.config.js`. See [docs/plans/QUALITY-GATES.md](plans/QUALITY-GATES.md) Section 6 for PHPUnit and Playwright commands.

---

## Next steps

- See [docs/plans/backlog/PHASE-1-TASKS.md](../plans/backlog/PHASE-1-TASKS.md) for the full Phase 1 task list.
- Check [.cursor/rules/environment.mdc](../.cursor/rules/environment.mdc) for development environment conventions.
