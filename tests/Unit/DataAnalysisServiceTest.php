<?php

namespace Tests\Unit;

use App\Services\DataAnalysisService;
use Tests\TestCase;

class DataAnalysisServiceTest extends TestCase
{
    private DataAnalysisService $service;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DataAnalysisService();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function writeTempCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    // -------------------------------------------------------------------------
    // Group A — Numeric columns
    // -------------------------------------------------------------------------

    public function test_numeric_stats_are_correct_for_known_values(): void
    {
        $path = $this->writeTempCsv("age\n10\n20\n30\n");

        $result = $this->service->analyse($path);

        $col = $result['columns']['age'];
        $this->assertSame(10.0, $col['min']);
        $this->assertSame(30.0, $col['max']);
        $this->assertSame(60.0, $col['sum']);
        $this->assertSame(20.0, $col['average']);
    }

    public function test_null_count_counts_empty_string_cells(): void
    {
        $path = $this->writeTempCsv("age\n10\n\n30\n\n");

        $result = $this->service->analyse($path);

        $this->assertSame(2, $result['columns']['age']['null_count']);
    }

    public function test_column_with_all_valid_numbers_is_typed_numeric(): void
    {
        $path = $this->writeTempCsv("score\n1\n2\n3\n");

        $result = $this->service->analyse($path);

        $this->assertSame('numeric', $result['columns']['score']['type']);
    }

    // -------------------------------------------------------------------------
    // Group B — String columns
    // -------------------------------------------------------------------------

    public function test_value_counts_frequency_map_is_correct(): void
    {
        $path = $this->writeTempCsv("country\nGermany\nFrance\nGermany\nGermany\nFrance\n");

        $result = $this->service->analyse($path);

        $counts = $result['columns']['country']['value_counts'];
        $this->assertSame(3, $counts['Germany']);
        $this->assertSame(2, $counts['France']);
    }

    public function test_unique_count_and_null_count_are_accurate(): void
    {
        $path = $this->writeTempCsv("city\nBerlin\nParis\nBerlin\n\nParis\n\n");

        $result = $this->service->analyse($path);

        $col = $result['columns']['city'];
        $this->assertSame(2, $col['unique_count']);
        $this->assertSame(2, $col['null_count']);
    }

    public function test_column_with_any_non_numeric_value_is_typed_string(): void
    {
        $path = $this->writeTempCsv("mixed\n1\n2\nthree\n4\n");

        $result = $this->service->analyse($path);

        $this->assertSame('string', $result['columns']['mixed']['type']);
    }

    // -------------------------------------------------------------------------
    // Group C — Edge cases
    // -------------------------------------------------------------------------

    public function test_empty_file_returns_row_count_zero_and_empty_columns(): void
    {
        $path = $this->writeTempCsv('');

        $result = $this->service->analyse($path);

        $this->assertSame(0, $result['row_count']);
        $this->assertSame([], $result['columns']);
    }

    public function test_header_only_csv_returns_row_count_zero_with_zeroed_column_stats(): void
    {
        $path = $this->writeTempCsv("name,age\n");

        $result = $this->service->analyse($path);

        $this->assertSame(0, $result['row_count']);
        $this->assertArrayHasKey('name', $result['columns']);
        $this->assertArrayHasKey('age', $result['columns']);
        $this->assertSame(0, $result['columns']['name']['null_count']);
        $this->assertSame(0, $result['columns']['age']['null_count']);
    }

    public function test_all_null_column_defaults_to_string_type(): void
    {
        $path = $this->writeTempCsv("notes\n\n\n\n");

        $result = $this->service->analyse($path);

        $col = $result['columns']['notes'];
        $this->assertSame('string', $col['type']);
        $this->assertSame(3, $col['null_count']);
    }

    public function test_row_count_reflects_data_rows_only_not_the_header(): void
    {
        $path = $this->writeTempCsv("name\nAlice\nBob\nCharlie\n");

        $result = $this->service->analyse($path);

        $this->assertSame(3, $result['row_count']);
    }
}
