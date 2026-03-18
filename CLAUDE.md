# async-ops

Laravel 11 async task processing demo with two task types: user CSV export (`GenerateReportJob`) and file format conversion (`ConvertFileJob` via `Bus::batch()`). Auth via Laravel Sanctum.

---

## Dev environment

Start infrastructure before running the app or tests:

```bash
docker start asyncops-mysql asyncops-redis
```

| Container | Details |
|---|---|
| `asyncops-mysql` | `127.0.0.1:3307`, db `async_ops`, root/secret |
| `asyncops-redis` | Queue backend |

No docker-compose — containers are started manually.

---

## Running tests

```bash
composer run test          # clears config then runs full suite
php artisan test           # run directly
php artisan test <path>    # run single file
```

- Test DB: `async_ops_test` on same MySQL container (port 3307)
- Migrate before first run: `php artisan migrate --env=testing`
- Queue is `sync` in tests — no worker needed
- Always use `Storage::fake('local')` and `Bus::fake()` in feature/unit tests

---

## Architecture — key rules

These decisions are intentional. Do not change them without discussion.

- **No new migrations** for file conversion — `payload` JSON column carries `files`, `batch_id`, `output_files`
- **Always `Bus::batch()`** even for single-file conversions
- **`TaskService` owns all orchestration** — controllers must be thin HTTP adapters only
- **No job-level DB writes for progress** — progress is derived live from `Bus::findBatch()` in `TaskService::withLiveProgress()`
- **Compensating logic, not DB transactions** — on failure, explicitly delete files + task record
- **UUID-based output filenames** — prevents path traversal by design (`FileConversionService::outputPath()`)
- **`clone $task`** before mutating for response — never mutate the route-bound Eloquent instance

---

## Key files

| Responsibility | File |
|---|---|
| Task orchestration | `app/Services/TaskService.php` |
| File conversion logic | `app/Services/FileConversionService.php` |
| Batch job (per file) | `app/Jobs/ConvertFileJob.php` |
| Report job | `app/Jobs/GenerateReportJob.php` |
| HTTP — tasks | `app/Http/Controllers/TaskController.php` |
| HTTP — conversions | `app/Http/Controllers/ConversionController.php` |
| Auth | `app/Http/Controllers/AuthController.php` |
| Access control | `app/Policies/TaskPolicy.php` |
| Task model | `app/Models/Task.php` |
| Enums | `app/Enums/` (TaskType, TaskStatus, ConversionFormat) |

---

## API routes

All routes require `auth:sanctum` except login.

```
POST   /api/login
POST   /api/logout
POST   /api/tasks
GET    /api/tasks/{task}             ← route key is UUID
GET    /api/tasks/{task}/download
POST   /api/conversions
```

---

## Storage layout

```
storage/app/private/
  uploads/{task_uuid}/{original_filename}
  conversions/{task_uuid}/output_{uuid}.ext
  conversions/{task_uuid}/result.zip    ← multi-file only
  reports/{task_uuid}.csv               ← user export
```

---

## Code conventions

- **No magic strings** — always use enums (`TaskType`, `TaskStatus`, `ConversionFormat`)
- **Named arguments** for all multi-param service calls
- PHP 8.2+: backed enums, constructor promotion, match expressions, named args
- FormRequests handle all validation — controllers never validate manually
- `Rule::enum()` for enum validation in requests

---

## Testing conventions

- `RefreshDatabase` on all tests that touch the DB
- `Bus::fake()` + `Bus::assertBatched()` for batch assertions
- `Storage::fake('local')` for all file I/O
- `actingAs($user, 'sanctum')` for authenticated requests
- Mock `Bus::shouldReceive('findBatch')` for `withLiveProgress` tests
- `Mockery::mock(Batch::class)` for in-flight batch simulation

---

## What to avoid

- Do not put orchestration logic in controllers
- Do not use `DB::transaction` for operations spanning filesystem + queue
- Do not mutate the route-bound `$task` instance directly — use `clone`
- Do not add new migrations for conversion payload data
- Do not write per-job progress to DB — derive it from the batch
