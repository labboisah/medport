# MediPass

MediPass is currently a local development scaffold: a Laravel backend in `backend/` and a React/TypeScript/Vite frontend in `frontend/`. No patient workflows or partner integrations are implemented. Use synthetic data only.

## Local setup

Prerequisites: PHP 8.3 with `pdo_mysql`, Composer 2, Node.js 22.12 or newer, npm, and MySQL 8.4. The checked environment used PHP 8.3.16, Composer 2.8.4, Node 24.19.0, npm 11.17.0, and MySQL 8.4.3. On Windows with Laragon, add its PHP and Composer binaries to the current terminal's `PATH`, or invoke them by their full paths.

Install locked dependencies from the repository root:

```powershell
composer install --working-dir=backend
npm.cmd ci --prefix frontend
```

Create a local MySQL schema, then initialize Laravel once. Copy the example only when a local `.env` does not already exist:

```powershell
mysql -u root -p -e "CREATE DATABASE medipass_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if (-not (Test-Path backend\.env)) { Copy-Item backend\.env.example backend\.env }
php backend\artisan key:generate
php backend\artisan migrate
```

Keep `backend/.env` local and untracked. Set its database credentials for your local MySQL account; never commit credentials or real patient data. The local defaults use MySQL, database-backed cache/queue/session, and Laravel's private local filesystem disk. Redis and external object storage are not configured.

Run checks:

```powershell
php backend\artisan test
npm.cmd --prefix frontend run test
npm.cmd --prefix frontend run lint
npm.cmd --prefix frontend run build
```

Start the applications in separate terminals:

```powershell
php backend\artisan serve --host=127.0.0.1
npm.cmd --prefix frontend run dev -- --host 127.0.0.1
```

The backend is available at `http://127.0.0.1:8000` and the frontend at `http://127.0.0.1:5173`. They are separate starter applications at this stage.

See [Implementation Progress](docs/Implementation_Progress.md) for verified status, unresolved approvals, and phase boundaries.
