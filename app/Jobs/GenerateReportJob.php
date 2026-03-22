<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use Faker\Factory as Faker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 120;

    private const TOTAL_ROWS = 50;
    private const REGIONS    = ['North', 'South', 'East', 'West', 'Central'];
    private const CATEGORIES = ['Electronics', 'Clothing', 'Home & Garden', 'Sports', 'Books', 'Automotive'];
    private const PRODUCTS   = [
        'Wireless Headphones', 'Running Shoes', 'Coffee Maker', 'Yoga Mat',
        'Laptop Stand', 'Water Bottle', 'Desk Lamp', 'Bluetooth Speaker',
        'Backpack', 'Smart Watch',
    ];

    public function __construct(public readonly Task $task) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        if (! Task::find((int) $this->task->id)) {
            return;
        }

        $this->task->refresh();

        if ($this->task->status !== TaskStatus::Pending) {
            return;
        }

        $this->task->update([
            'status'   => TaskStatus::Processing,
            'progress' => 0,
        ]);

        $faker     = Faker::create();
        $threshold = max(1, (int) ceil(self::TOTAL_ROWS / 10));
        $nextUpdate = $threshold;
        $stream    = fopen('php://temp', 'r+');

        try {
            fputcsv($stream, [
                'order_id', 'sale_date', 'customer_name', 'customer_email',
                'product', 'category', 'quantity', 'unit_price', 'total', 'region', 'salesperson',
            ]);

            for ($i = 1; $i <= self::TOTAL_ROWS; $i++) {
                $quantity  = $faker->numberBetween(1, 10);
                $unitPrice = round($faker->randomFloat(2, 5, 500), 2);

                fputcsv($stream, [
                    'ORD-' . str_pad((string) $faker->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
                    $faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
                    $faker->name(),
                    $faker->safeEmail(),
                    self::PRODUCTS[array_rand(self::PRODUCTS)],
                    self::CATEGORIES[array_rand(self::CATEGORIES)],
                    $quantity,
                    number_format($unitPrice, 2, '.', ''),
                    number_format(round($quantity * $unitPrice, 2), 2, '.', ''),
                    self::REGIONS[array_rand(self::REGIONS)],
                    $faker->name(),
                ]);

                if ($i >= $nextUpdate) {
                    $this->task->update(['progress' => (int) min(99, round($i / self::TOTAL_ROWS * 100))]);
                    $nextUpdate += $threshold;
                }
            }

            rewind($stream);

            $path = 'reports/' . $this->task->uuid . '.csv';
            Storage::disk('local')->put($path, $stream);

            $this->task->update([
                'status'      => TaskStatus::Completed,
                'progress'    => 100,
                'result_path' => $path,
            ]);
        } finally {
            fclose($stream);
        }
    }

    public function failed(Throwable $e): void
    {
        if (! Task::find((int) $this->task->id)) {
            return;
        }

        $this->task->update([
            'status'        => TaskStatus::Failed,
            'error_message' => $e->getMessage(),
        ]);
    }
}
