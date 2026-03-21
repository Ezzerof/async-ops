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

### File Conversion
Upload one or more files and convert them between formats (CSV ↔ JSON, XML → JSON). Each file is processed as an individual job inside a `Bus::batch()`. Progress is derived live from the batch rather than written per-job. Multi-file results are zipped automatically for download.

Supported formats: `csv`, `json`, `xml`

### Data Analysis
Upload a CSV for structural analysis — headers, row counts, and column statistics are computed asynchronously. An existing import can be re-analysed without re-uploading the file.

### CSV Import
Upload a CSV file for async validation and permanent storage. The job validates structure (headers, duplicates, column consistency), moves the file from the upload directory to permanent storage, and creates a `CsvImport` record. The import ID is written back into the task payload on completion so clients can navigate directly to the result after polling.

### Bulk Email Sending
Upload a CSV of client emails, then trigger a bulk send referencing that import. The system reads the `email` column from the stored CSV, fans out one job per recipient via `Bus::batch()` with `allowFailures()` — partial SMTP failures are tracked individually without cancelling the rest. The HTML body is sanitised server-side before storage using `symfony/html-sanitizer`. An optional PDF attachment can be included. On completion, a delivery report CSV is generated listing each recipient's outcome (`sent`/`failed`/`unknown`) and made available via the standard task download endpoint. The attachment is deleted automatically after the batch completes.

**Required CSV structure:**

```csv
email
alice@example.com
```

The `email` column is the only required column (case-insensitive). All other columns are ignored. Blank values and duplicates are skipped automatically.

Rate limited to **5 requests per minute**.

### PDF Invoice Generation
Upload a CSV of line items and receive a professionally formatted PDF invoice. The job validates each row, calculates line totals using rounded arithmetic, and renders a PDF with company branding, user billing details (from the user's profile), and a T&C footer. The CSV is deleted after processing regardless of outcome. The completed PDF is available via the standard task download endpoint.

**Required CSV structure:**

| Column | Type | Rules |
|---|---|---|
| `description` | string | Required, non-empty |
| `quantity` | integer | Required, positive integer (no decimals) |
| `unit_price` | decimal | Required, positive number |

**Example:**
```csv
description,quantity,unit_price
Widget A,2,9.99
Consulting (hr),3,75.00
Shipping,1,4.50
```

Constraints: maximum 500 line items per invoice, file size limit 5 MB.

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
| GET | `/api/imports/{uuid}` | Retrieve a completed import record |
| DELETE | `/api/imports/{uuid}` | Delete an import and its stored file |
| POST | `/api/imports/{uuid}/analyse` | Trigger analysis on an existing import (throttled: 10/min) |
| POST | `/api/imports/{uuid}/email` | Send bulk email to recipients in the imported CSV (throttled: 5/min) |

### PDF Invoice Generation

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/invoices` | Upload a CSV of line items to generate a PDF invoice |

Routes use UUID identifiers for all tasks and imports.

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
  analyses/{task_uuid}/         ← JSON analysis result
  imports/{task_uuid}/          ← validated and stored CSV imports
  invoices/{task_uuid}/         ← generated PDF invoice
  emails/{task_uuid}/attachment_{uuid}.pdf  ← uploaded PDF attachment (deleted after batch completes)
  emails/{task_uuid}/report.csv             ← delivery report CSV, survives for download
```
