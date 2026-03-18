# AsyncOps

A Laravel API demonstrating async background task processing — from HTTP request to queued job to file download.

## Tech Stack

- PHP 8.x / Laravel 12
- MySQL (port 3307 in local dev)
- Redis (queue driver, AOF persistence)
- Laravel Sanctum (token-based auth)
- Docker (MySQL + Redis containers)

## What It Does

A user authenticates, submits a task via the API, and the system creates a `Task` record and dispatches a background job. Three task types are supported:

- **Report generation** — generates a demo sales report as a CSV
- **File conversion** — converts uploaded files between formats (CSV, JSON, XML, etc.) using a batch job
- **Data analysis** — accepts a CSV upload, computes column-level statistics (min/max/average/counts), and stores results as JSON

Once complete, the result file is available for download through a dedicated endpoint — CSV for reports, JSON for analyses, and the converted file (or zip) for conversions.

## API Endpoints

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/api/login` | public | Obtain a Sanctum token |
| POST | `/api/logout` | required | Revoke current token |
| POST | `/api/tasks` | required | Request a report generation task |
| GET | `/api/tasks/{uuid}` | required | Poll task status and progress |
| GET | `/api/tasks/{uuid}/download` | required | Download the completed result |
| POST | `/api/conversions` | required | Submit files for format conversion |
| POST | `/api/analyses` | required | Upload a CSV for async analysis |

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

Test suite: 194 tests, 353 assertions.
