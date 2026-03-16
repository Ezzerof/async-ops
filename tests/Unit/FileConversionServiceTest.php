<?php

namespace Tests\Unit;

use App\Enums\ConversionFormat;
use App\Services\FileConversionService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FileConversionServiceTest extends TestCase
{
    private FileConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = new FileConversionService();
    }

    // -------------------------------------------------------------------------
    // Group A — CSV → JSON
    // -------------------------------------------------------------------------

    public function test_csv_to_json_produces_array_of_objects(): void
    {
        Storage::put('conversions/uuid-1/input.csv', "name,age,city\nAlice,30,London\nBob,25,Paris\n");

        $outputPath = $this->service->convert('conversions/uuid-1/input.csv', ConversionFormat::Json);

        $result = json_decode(Storage::get($outputPath), true);
        $this->assertCount(2, $result);
        $this->assertSame(['name' => 'Alice', 'age' => '30', 'city' => 'London'], $result[0]);
        $this->assertSame(['name' => 'Bob',   'age' => '25', 'city' => 'Paris'],  $result[1]);
    }

    public function test_csv_to_json_output_file_has_json_extension(): void
    {
        Storage::put('conversions/uuid-1/input.csv', "name,age\nAlice,30\n");

        $outputPath = $this->service->convert('conversions/uuid-1/input.csv', ConversionFormat::Json);

        $this->assertStringEndsWith('.json', $outputPath);
    }

    public function test_csv_to_json_output_is_written_to_same_directory(): void
    {
        Storage::put('conversions/uuid-1/input.csv', "name,age\nAlice,30\n");

        $outputPath = $this->service->convert('conversions/uuid-1/input.csv', ConversionFormat::Json);

        $this->assertStringStartsWith('conversions/uuid-1/', $outputPath);
        Storage::disk('local')->assertExists($outputPath);
    }

    public function test_csv_to_json_throws_on_malformed_row(): void
    {
        // Row 2 has 2 values but header has 3 columns
        Storage::put('conversions/uuid-1/input.csv', "name,age,city\nAlice,30\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/columns, expected/');

        $this->service->convert('conversions/uuid-1/input.csv', ConversionFormat::Json);
    }

    public function test_csv_to_json_throws_on_row_with_too_many_columns(): void
    {
        // Row has more values than headers
        Storage::put('conversions/uuid-1/input.csv', "name,age\nAlice,30,London\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/columns, expected/');

        $this->service->convert('conversions/uuid-1/input.csv', ConversionFormat::Json);
    }

    public function test_csv_to_json_header_only_produces_empty_array(): void
    {
        Storage::put('conversions/uuid-1/input.csv', "name,age,city\n");

        $outputPath = $this->service->convert('conversions/uuid-1/input.csv', ConversionFormat::Json);

        $result = json_decode(Storage::get($outputPath), true);
        $this->assertSame([], $result);
    }

    public function test_csv_to_json_handles_windows_line_endings(): void
    {
        Storage::put('conversions/uuid-1/input.csv', "name,age,city\r\nAlice,30,London\r\nBob,25,Paris\r\n");

        $outputPath = $this->service->convert('conversions/uuid-1/input.csv', ConversionFormat::Json);

        $result = json_decode(Storage::get($outputPath), true);
        $this->assertCount(2, $result);
        $this->assertSame(['name' => 'Alice', 'age' => '30', 'city' => 'London'], $result[0]);
        $this->assertSame(['name' => 'Bob',   'age' => '25', 'city' => 'Paris'],  $result[1]);
    }

    public function test_csv_to_json_handles_quoted_values_containing_commas(): void
    {
        Storage::put('conversions/uuid-1/input.csv', "name,city\n\"Smith, John\",\"New York\"\n");

        $outputPath = $this->service->convert('conversions/uuid-1/input.csv', ConversionFormat::Json);

        $result = json_decode(Storage::get($outputPath), true);
        $this->assertSame('Smith, John', $result[0]['name']);
        $this->assertSame('New York',    $result[0]['city']);
    }

    // -------------------------------------------------------------------------
    // Group B — JSON → CSV
    // -------------------------------------------------------------------------

    public function test_json_to_csv_produces_correct_header_row(): void
    {
        Storage::put('conversions/uuid-2/input.json', json_encode([
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob',   'age' => 25],
        ]));

        $outputPath = $this->service->convert('conversions/uuid-2/input.json', ConversionFormat::Csv);

        $lines  = explode("\n", trim(Storage::get($outputPath)));
        $header = str_getcsv($lines[0]);
        $this->assertSame(['name', 'age'], $header);
    }

    public function test_json_to_csv_produces_correct_data_rows(): void
    {
        Storage::put('conversions/uuid-2/input.json', json_encode([
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob',   'age' => 25],
        ]));

        $outputPath = $this->service->convert('conversions/uuid-2/input.json', ConversionFormat::Csv);

        $lines = explode("\n", trim(Storage::get($outputPath)));
        $this->assertSame(['Alice', '30'], str_getcsv($lines[1]));
        $this->assertSame(['Bob',   '25'], str_getcsv($lines[2]));
    }

    public function test_json_to_csv_output_file_has_csv_extension(): void
    {
        Storage::put('conversions/uuid-2/input.json', json_encode([
            ['name' => 'Alice'],
        ]));

        $outputPath = $this->service->convert('conversions/uuid-2/input.json', ConversionFormat::Csv);

        $this->assertStringEndsWith('.csv', $outputPath);
    }

    public function test_json_to_csv_throws_on_empty_array(): void
    {
        Storage::put('conversions/uuid-2/input.json', '[]');

        $this->expectException(RuntimeException::class);

        $this->service->convert('conversions/uuid-2/input.json', ConversionFormat::Csv);
    }

    public function test_json_to_csv_throws_on_array_of_scalars(): void
    {
        Storage::put('conversions/uuid-2/input.json', json_encode([1, 2, 3]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/array of objects/');

        $this->service->convert('conversions/uuid-2/input.json', ConversionFormat::Csv);
    }

    public function test_json_to_csv_throws_on_invalid_json_string(): void
    {
        Storage::put('conversions/uuid-2/input.json', 'this is not json {{{');

        $this->expectException(RuntimeException::class);

        $this->service->convert('conversions/uuid-2/input.json', ConversionFormat::Csv);
    }

    public function test_json_to_csv_throws_on_root_object_instead_of_array(): void
    {
        // A JSON object at root is not a valid input — must be an array of objects
        Storage::put('conversions/uuid-2/input.json', json_encode(['name' => 'Alice', 'age' => 30]));

        $this->expectException(RuntimeException::class);

        $this->service->convert('conversions/uuid-2/input.json', ConversionFormat::Csv);
    }

    // -------------------------------------------------------------------------
    // Group C — XML → JSON
    // -------------------------------------------------------------------------

    public function test_xml_to_json_produces_correct_structure(): void
    {
        $xml = <<<XML
        <?xml version="1.0"?>
        <users>
            <user><name>Alice</name><age>30</age></user>
            <user><name>Bob</name><age>25</age></user>
        </users>
        XML;

        Storage::put('conversions/uuid-3/input.xml', $xml);

        $outputPath = $this->service->convert('conversions/uuid-3/input.xml', ConversionFormat::Json);

        $result = json_decode(Storage::get($outputPath), true);
        $this->assertArrayHasKey('user', $result);
        $this->assertCount(2, $result['user']);
        $this->assertSame('Alice', $result['user'][0]['name']);
    }

    public function test_xml_to_json_output_file_has_json_extension(): void
    {
        Storage::put('conversions/uuid-3/input.xml', '<?xml version="1.0"?><root><item>1</item></root>');

        $outputPath = $this->service->convert('conversions/uuid-3/input.xml', ConversionFormat::Json);

        $this->assertStringEndsWith('.json', $outputPath);
    }

    public function test_xml_to_json_single_child_element_is_object_not_array(): void
    {
        // Known simplexml behaviour: a single child element is encoded as an object,
        // not a single-element array. This test documents the actual output shape
        // so regressions are caught if the parsing strategy changes.
        $xml = '<?xml version="1.0"?><users><user><name>Alice</name></user></users>';
        Storage::put('conversions/uuid-3/input.xml', $xml);

        $outputPath = $this->service->convert('conversions/uuid-3/input.xml', ConversionFormat::Json);

        $result = json_decode(Storage::get($outputPath), true);
        $this->assertArrayHasKey('user', $result);
        // Single child: simplexml gives an object, not a one-element array
        $this->assertIsArray($result['user']);
        $this->assertArrayHasKey('name', $result['user']);
        $this->assertSame('Alice', $result['user']['name']);
    }

    public function test_xml_to_json_throws_on_invalid_xml(): void
    {
        Storage::put('conversions/uuid-3/input.xml', 'this is not xml');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse XML file.');

        $this->service->convert('conversions/uuid-3/input.xml', ConversionFormat::Json);
    }

    // -------------------------------------------------------------------------
    // Group D — Stub conversion
    // -------------------------------------------------------------------------

    public function test_stub_copies_file_content_unchanged(): void
    {
        $originalContent = 'some binary-ish content';
        Storage::put('conversions/uuid-4/input.pdf', $originalContent);

        $outputPath = $this->service->convert('conversions/uuid-4/input.pdf', ConversionFormat::Txt);

        $this->assertSame($originalContent, Storage::get($outputPath));
    }

    public function test_stub_output_has_target_extension(): void
    {
        Storage::put('conversions/uuid-4/input.pdf', 'content');

        $outputPath = $this->service->convert('conversions/uuid-4/input.pdf', ConversionFormat::Txt);

        $this->assertStringEndsWith('.txt', $outputPath);
    }

    public function test_stub_output_is_written_to_same_directory(): void
    {
        Storage::put('conversions/uuid-4/input.docx', 'content');

        $outputPath = $this->service->convert('conversions/uuid-4/input.docx', ConversionFormat::Pdf);

        $this->assertStringStartsWith('conversions/uuid-4/', $outputPath);
        Storage::disk('local')->assertExists($outputPath);
    }

    // -------------------------------------------------------------------------
    // Group E — Guards
    // -------------------------------------------------------------------------

    public function test_convert_throws_when_source_file_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source file not found');

        $this->service->convert('conversions/uuid-5/missing.csv', ConversionFormat::Json);
    }
}
