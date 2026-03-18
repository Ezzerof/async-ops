<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImportRequest;
use App\Models\CsvImport;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CsvImportController extends Controller
{
    public function __construct(private readonly TaskService $taskService) {}

    public function store(StoreImportRequest $request): JsonResponse
    {
        $task = $this->taskService->createCsvImportTask(
            user: $request->user(),
            file: $request->file('file'),
        );

        return response()->json($task, 201);
    }

    public function show(CsvImport $import): JsonResponse
    {
        $this->authorize('view', $import);

        return response()->json($import);
    }

    public function analyse(Request $request, CsvImport $import): JsonResponse
    {
        $this->authorize('view', $import);

        try {
            $task = $this->taskService->createAnalysisTaskFromImport(
                user:   $request->user(),
                import: $import,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($task, 201);
    }

    public function destroy(CsvImport $import): Response|JsonResponse
    {
        $this->authorize('delete', $import);

        if ($this->taskService->hasActiveAnalysisFor($import)) {
            return response()->json(
                ['message' => 'An analysis derived from this import is still running. Wait for it to complete before deleting.'],
                409
            );
        }

        $this->taskService->deleteImport($import);

        return response()->noContent();
    }
}
