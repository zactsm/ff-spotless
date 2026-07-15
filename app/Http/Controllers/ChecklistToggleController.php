<?php

namespace App\Http\Controllers;

use App\Http\Requests\ToggleTaskRequest;
use App\Models\DailyChecklist;
use App\Services\OperationalDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChecklistToggleController extends Controller
{
    public function store(ToggleTaskRequest $request, OperationalDate $dates)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $dates): void {
            $task = DailyChecklist::query()
                ->lockForUpdate()
                ->findOrFail($data['task_id']);

            $storedDate = $task->date->toDateString();

            if (! hash_equals($storedDate, $data['date'])) {
                throw ValidationException::withMessages([
                    'date' => 'Tugasan yang diminta tidak sepadan dengan tarikh senarai semak ini.',
                ]);
            }

            if (! $dates->isToday($storedDate)) {
                abort(403, 'Hanya senarai semak hari ini boleh dikemas kini.');
            }

            $isCompleted = in_array($data['is_completed'], [true, 1, '1'], true);

            // A duplicate POST caused by a mobile retry must not replace the
            // original operational timestamp or actor. A genuine re-tick
            // occurs only after the task has first been unchecked.
            if ($task->is_completed === $isCompleted) {
                return;
            }

            $task->forceFill([
                'is_completed' => $isCompleted,
                'completed_at' => $isCompleted ? $dates->nowUtc() : null,
                // Cleaner entries are deliberately anonymous.
                'completed_by_user_id' => null,
            ])->save();
        }, 3);

        return to_route('checklist.index', ['date' => $data['date']]);
    }
}
