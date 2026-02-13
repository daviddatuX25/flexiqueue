# BD-001: First-time setup

After cloning or pulling the FlexiQueue repo with Laravel + Inertia + Svelte + DaisyUI in place, choose one of the setup options below.

---

## Option A: Laravel Sail (Recommended)

Laravel Sail provides a consistent Docker-based development environment with PHP, MariaDB, and all dependencies pre-configured.

### Prerequisites

- **Docker Desktop** (Windows/Mac) or **Docker + Docker Compose** (Linux)
- **Git** (for cloning the repo)

### One-time setup

1. **Install PHP dependencies** (requires PHP locally for this step only):

```bash
composer install
```

If you don't have PHP installed locally, you can use a temporary Docker container:

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php82-composer:latest \
    composer install --ignore-platform-reqs
```

2. **Set up environment file**:

```bash
cp .env.example .env
```

3. **Install Sail with MariaDB**:

```bash
php artisan sail:install
```

When prompted, select **mariadb** (and optionally **redis** if you want caching). This generates `docker-compose.yml`.

4. **Start Sail containers**:

```bash
./vendor/bin/sail up -d
```

**Tip**: Add `alias sail='./vendor/bin/sail'` to your shell profile for shorter commands.

5. **Generate application key**:

```bash
./vendor/bin/sail artisan key:generate
```

6. **Install Node dependencies**:

```bash
npm install --legacy-peer-deps
```

Use `--legacy-peer-deps` because `@sveltejs/vite-plugin-svelte` currently expects Vite 6 while Laravel 12 ships with Vite 7.

7. **Run database migrations** (when available):

```bash
./vendor/bin/sail artisan migrate
```

### Daily workflow

1. **Start containers**:

```bash
./vendor/bin/sail up -d
```

2. **Run Vite dev server** (choose one):

**Option 1 - On host** (recommended for WSL/Windows to avoid file watch issues):

```bash
npm run dev
```

**Option 2 - In Sail container** (requires Vite config update):

Add to `vite.config.js`:

```javascript
server: {
    host: '0.0.0.0',
    hmr: {
        host: 'localhost'
    },
    watch: {
        ignored: ['**/storage/framework/views/**'],
    },
}
```

And ensure port 5173 is exposed in `docker-compose.yml` (Sail does this by default).

Then run:

```bash
./vendor/bin/sail npm run dev
```

3. **Access the app**: Open http://localhost — you should see the FlexiQueue welcome page (Svelte + DaisyUI theme).

4. **Stop containers** (when done):

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
```

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
npm install --legacy-peer-deps
npm run build
```

Use `--legacy-peer-deps` because `@sveltejs/vite-plugin-svelte` currently expects Vite 6 while Laravel 12 ships with Vite 7.

**Note**: If you see **"Could not resolve entry module index.html"**, the build is likely running from a bad cwd (e.g. Windows when the project is under WSL). Run `npm run build` from a WSL bash prompt or from a non-UNC project path.

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

## Next steps

- See [docs/plans/backlog/PHASE-1-TASKS.md](../plans/backlog/PHASE-1-TASKS.md) for the full Phase 1 task list.
- Check [.cursor/rules/environment.mdc](../.cursor/rules/environment.mdc) for development environment conventions.
