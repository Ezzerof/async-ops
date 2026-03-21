<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmailRequest;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;

class EmailController extends Controller
{
    public function __construct(private readonly TaskService $taskService) {}

    public function store(StoreEmailRequest $request): JsonResponse
    {
        $task = $this->taskService->createBulkEmailTask(
            user:       $request->user(),
            recipients: $request->validated('recipients'),
            subject:    $request->validated('subject'),
            body:       $request->validated('body'),
            attachment: $request->file('attachment'),
        );

        return response()->json($task, 201);
    }
}
