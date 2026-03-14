<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $taskService) {}

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $task = $this->taskService->createAndDispatch(
            $request->user(),
            $request->validated('type'),
        );

        return response()->json($task, 201);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json($task);
    }

    public function download(Request $request, Task $task): StreamedResponse
    {
        $this->authorize('download', $task);

        return Storage::disk('local')->download(
            $task->result_path,
            'report-' . $task->uuid . '.csv',
            ['Content-Type' => 'text/csv'],
        );
    }
}
