<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyTaskTemplateRequest;
use App\Http\Requests\StoreTaskTemplateRequest;
use App\Http\Requests\UpdateTaskTemplateRequest;
use App\Models\TaskTemplate;
use App\Services\ChecklistMaterializer;
use Illuminate\Support\Facades\DB;

class TaskTemplateController extends Controller
{
    public function store(StoreTaskTemplateRequest $request, ChecklistMaterializer $materializer)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $materializer): void {
            $template = TaskTemplate::query()->create([
                'task_name' => $data['task_name'],
                'session' => $data['session'],
                'is_active' => true,
            ]);

            $materializer->appendTemplateToCurrentAndFutureSheets($template);
        }, 3);

        return to_route('admin.index');
    }

    public function update(
        UpdateTaskTemplateRequest $request,
        TaskTemplate $taskTemplate,
        ChecklistMaterializer $materializer,
    ) {
        $data = $request->validated();

        $updated = $materializer->updateTemplateAndCurrentAndFutureIncompleteSnapshots(
            $taskTemplate,
            $data['task_name'],
            $data['session'],
        );

        if (! $updated) {
            abort(404, 'Templat tugasan tidak ditemui atau telah diarkibkan.');
        }

        return to_route('admin.index');
    }

    public function destroy(
        DestroyTaskTemplateRequest $request,
        TaskTemplate $taskTemplate,
        ChecklistMaterializer $materializer,
    ) {
        $materializer->deactivateTemplateAndRemoveCurrentAndFutureIncompleteSnapshots($taskTemplate);

        return to_route('admin.index');
    }
}
