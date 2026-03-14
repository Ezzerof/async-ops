# AsyncOps

A Laravel API demonstrating async background task processing — from HTTP request to queued job to file download.

## Tech Stack

- PHP 8.x / Laravel 12
- MySQL (port 3307 in local dev)
- Redis (queue driver, AOF persistence)
- Laravel Sanctum (token-based auth)
- Docker (MySQL + Redis containers)

## What It Does

A user authenticates, requests a report via the API, and the system creates a `Task` record and dispatches a background job. The job generates a CSV of all users, tracking progress incrementally. Once complete, the file is available for download through a dedicated endpoint.

## API Endpoints

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/api/login` | public | Obtain a Sanctum token |
| POST | `/api/logout` | required | Revoke current token |
| POST | `/api/tasks` | required | Request a new report task |
| GET | `/api/tasks/{uuid}` | required | Poll task status and progress |
| GET | `/api/tasks/{uuid}/download` | required | Download the completed CSV |

Routes use UUID identifiers, not integer IDs.

## Local Setup

**Prerequisites:** Docker, PHP 8.x, Composer

```bash
# Start infrastructure
docker start asyncops-mysql asyncops-redis

# Install dependencies
composer install

# Run migrations
php artisan migrate --env=local

# Start queue worker
php artisan queue:work
```

## Running Tests

```bash
php artisan test --env=testing
```

The test environment uses `QUEUE_CONNECTION=sync`, so no queue worker is needed when running tests.

Test suite: 49 tests, 102 assertions across 4 files.
