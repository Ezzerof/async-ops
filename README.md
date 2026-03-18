# AsyncOps

A Laravel API demonstrating async background task processing — from HTTP request to queued job to file download.

## Tech Stack

- PHP 8.x / Laravel 12
- MySQL (port 3307 in local dev)
- Redis (queue driver, AOF persistence)
- Laravel Sanctum (token-based auth)
- Docker (MySQL + Redis containers)

---

## Features

### Report Generation
A user authenticates, requests a report via the API, and the system creates a `Task` record and dispatches a background job. The job generates a CSV of all users, tracking progress incrementally. Once complete, the file is available for download through a dedicated endpoint.

### File Conversion
Upload one or more files and convert them between formats (CSV ↔ JSON, XML → JSON). Each file is processed as an individual job inside a `Bus::batch()`. Progress is derived live from the batch rather than written per-job. Multi-file results are zipped automatically for download.

Supported formats: `csv`, `json`, `xml`

### Data Analysis
Upload a CSV for structural analysis — headers, row counts, and column statistics are computed asynchronously. An existing import can be re-analysed without re-uploading the file.

### CSV Import
Upload a CSV file for async validation and permanent storage. The job validates structure (headers, duplicates, column consistency), moves the file from the upload directory to permanent storage, and creates a `CsvImport` record. The import ID is written back into the task payload on completion so clients can navigate directly to the result after polling.

---

## API Endpoints

All routes except `/api/login` require a Sanctum token (`Authorization: Bearer <token>`).

### Auth

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/login` | Obtain a Sanctum token |
| POST | `/api/logout` | Revoke current token |

### Tasks

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/tasks` | Request a new report task |
| GET | `/api/tasks/{uuid}` | Poll task status and progress |
| GET | `/api/tasks/{uuid}/download` | Download the completed file |

### File Conversion

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/conversions` | Upload files for format conversion |

### Data Analysis

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/analyses` | Upload a CSV for analysis |

### CSV Import

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/imports` | Upload a CSV for async import (throttled: 10/min) |
| GET | `/api/imports/{id}` | Retrieve a completed import record |
| DELETE | `/api/imports/{id}` | Delete an import and its stored file |
| POST | `/api/imports/{id}/analyse` | Trigger analysis on an existing import (throttled: 10/min) |

Routes use UUID identifiers for tasks and integer IDs for imports.

---

## Local Setup

**Prerequisites:** Docker, PHP 8.x, Composer

```bash
# Start infrastructure
docker start asyncops-mysql asyncops-redis

# Install dependencies
composer install

# Run migrations
php artisan migrate

# Start queue worker
php artisan queue:work
```

---

## Running Tests

```bash
composer run test          # clears config then runs full suite
php artisan test           # run directly
php artisan test <path>    # run a single file
```

The test environment uses `QUEUE_CONNECTION=sync` — no queue worker needed.
`Storage::fake('local')` and `Bus::fake()` are used throughout; no real disk I/O or job dispatch occurs in tests.

---

## Storage Layout

```
storage/app/private/
  uploads/{task_uuid}/          ← temporary upload location (cleaned up after processing)
  conversions/{task_uuid}/      ← converted output files + result.zip (multi-file)
  reports/{task_uuid}.csv       ← generated user export
  imports/{task_uuid}/          ← validated and stored CSV imports
```
