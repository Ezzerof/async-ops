<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    public function __construct(private readonly TaskService $taskService) {}

    public function __invoke(StoreInvoiceRequest $request): JsonResponse
    {
        $task = $this->taskService->createInvoiceTask(
            user: $request->user(),
            file: $request->file('file'),
        );

        return response()->json($task, 201);
    }
}
