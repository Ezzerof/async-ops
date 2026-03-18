<?php

namespace Tests\Unit;

use App\Enums\TaskType;
use App\Models\Task;
use App\Models\User;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private CsvImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = new CsvImportService();
    }

    private function writeCsv(string $path, string $content): string
    {
        Storage::disk('local')->put($path, $content);

        return Storage::disk('local')->path($path);
    }

    // -------------------------------------------------------------------------
    // Group A — validateAndCount happy path
    // -------------------------------------------------------------------------

    public function test_returns_headers_and_row_count_for_valid_csv(): void
    {
        $path = $this->writeCsv('test.csv', "name,age\nAlice,30\nBob,25\n");

        $result = $this->service->validateAndCount($path);

        $this->assertSame(['name', 'age'], $result['headers']);
        $this->assertSame(2, $result['row_count']);
    }

    public function test_header_only_csv_returns_zero_row_count(): void
    {
        $path = $this->writeCsv('test.csv', "name,age\n");

        $result = $this->service->validateAndCount($path);

        $this->assertSame(0, $result['row_count']);
    }

    public function test_headers_are_trimmed(): void
    {
        $path = $this->writeCsv('test.csv', " name , age \nAlice,30\n");

        $result = $this->service->validateAndCount($path);

        $this->assertSame(['name', 'age'], $result['headers']);
    }

    public function test_strips_utf8_bom_from_first_header(): void
    {
        $bom  = "\xEF\xBB\xBF";
        $path = $this->writeCsv('test.csv', $bom . "name,age\nAlice,30\n");

        $result = $this->service->validateAndCount($path);

        $this->assertSame(['name', 'age'], $result['headers']);
        $this->assertSame(1, $result['row_count']);
    }

    // -------------------------------------------------------------------------
    // Group B — validateAndCount sad path
    // -------------------------------------------------------------------------

    public function test_throws_when_file_does_not_exist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Uploaded file not found');

        $this->service->validateAndCount('/nonexistent/path/file.csv');
    }

    public function test_throws_for_empty_file(): void
    {
        $path = $this->writeCsv('test.csv', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV file is empty or has no header row');

        $this->service->validateAndCount($path);
    }

    public function test_throws_for_duplicate_column_names(): void
    {
        $path = $this->writeCsv('test.csv', "name,name\nAlice,Alice\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate column names');

        $this->service->validateAndCount($path);
    }

    public function test_throws_for_mismatched_column_count(): void
    {
        $path = $this->writeCsv('test.csv', "name,age\nAlice\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Row 2 has \d+ columns, expected \d+/');

        $this->service->validateAndCount($path);
    }

    public function test_throws_for_empty_column_name(): void
    {
        $path = $this->writeCsv('test.csv', "name,,age\nAlice,,30\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty column name');

        $this->service->validateAndCount($path);
    }

    // -------------------------------------------------------------------------
    // Group C — createRecord
    // -------------------------------------------------------------------------

    public function test_create_record_persists_csv_import_with_correct_fields(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->pending()->create([
            'user_id' => $user->id,
            'type'    => TaskType::CsvImport->value,
        ]);

        $this->service->createRecord(
            task:             $task,
            importPath:       'imports/' . $task->uuid . '/data.csv',
            originalFilename: 'data.csv',
            headers:          ['name', 'age'],
            rowCount:         5,
        );

        $this->assertDatabaseHas('csv_imports', [
            'task_id'           => $task->id,
            'user_id'           => $user->id,
            'original_filename' => 'data.csv',
            'file_path'         => 'imports/' . $task->uuid . '/data.csv',
            'row_count'         => 5,
        ]);
    }
}
