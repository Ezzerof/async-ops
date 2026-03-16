<?php

namespace App\Http\Controllers;

use App\Enums\ConversionFormat;
use App\Http\Requests\StoreConversionRequest;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;

class ConversionController extends Controller
{
    public function __construct(private readonly TaskService $taskService) {}

    public function store(StoreConversionRequest $request): JsonResponse
    {
        $task = $this->taskService->createConversionTask(
            user:         $request->user(),
            files:        $request->file('files'),
            targetFormat: ConversionFormat::from($request->validated('target_format')),
        );

        return response()->json($task, 201);
    }
}
