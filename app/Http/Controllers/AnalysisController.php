<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAnalysisRequest;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;

class AnalysisController extends Controller
{
    public function __construct(private readonly TaskService $taskService) {}

    public function store(StoreAnalysisRequest $request): JsonResponse
    {
        $task = $this->taskService->createAnalysisTask(
            user: $request->user(),
            file: $request->file('file'),
        );

        return response()->json($task, 201);
    }
}
