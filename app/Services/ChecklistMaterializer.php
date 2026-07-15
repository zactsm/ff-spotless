<?php

namespace App\Services;

use App\Enums\TaskSession;
use App\Exceptions\ChecklistDateOutsideMaterializationWindow;
use App\Models\DailyChecklist;
use App\Models\TaskTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class ChecklistMaterializer
{
    public function __construct(
        private readonly OperationalDate $dates,
    ) {}

    /**
     * Return the immutable task snapshot for a date, creating it once when needed.
     *
     * @return Collection<int, DailyChecklist>
     *
     * @throws ChecklistDateOutsideMaterializationWindow
     */
    public function forDate(CarbonImmutable $date): Collection
    {
        $dateString = $date->toDateString();

        if (! $this->isMaterialized($dateString)) {
            DB::transaction(function () use ($date, $dateString): void {
                // The same durable row is locked while a template is created
                // and while a new day is cloned. This closes the race where a
                // new template could otherwise miss a concurrently-created sheet.
                $this->acquireTemplateSynchronizationLock();

                if ($this->isMaterialized($dateString)) {
                    return;
                }

                if (! $this->dates->isWithinMaterializationWindow($date)) {
                    throw new ChecklistDateOutsideMaterializationWindow;
                }

                // This ledger makes an empty sheet an immutable snapshot too.
                DB::table('checklist_materializations')->insert([
                    'date' => $dateString,
                ]);

                $templates = TaskTemplate::query()
                    ->active()
                    ->orderBy('id')
                    ->get(['id', 'task_name', 'session']);

                if ($templates->isEmpty()) {
                    return;
                }

                $rows = $templates->map(function (TaskTemplate $template) use ($dateString): array {
                    $session = $template->session;

                    return [
                        'date' => $dateString,
                        'task_template_id' => $template->id,
                        'task_name' => $template->task_name,
                        'session' => $session instanceof TaskSession ? $session->value : $session,
                        'is_completed' => false,
                        'completed_at' => null,
                        'completed_by_user_id' => null,
                    ];
                })->all();

                // The unique index remains a final guard for database-level
                // integrity if data is ever imported outside this service.
                DailyChecklist::query()->insertOrIgnore($rows);
            }, 3);
        }

        return DailyChecklist::query()
            ->whereDate('date', $dateString)
            ->orderBy('id')
            ->get();
    }

    /**
     * Add a newly-created template to every existing current/future daily sheet.
     * Historical snapshots are intentionally not changed.
     */
    public function appendTemplateToCurrentAndFutureSheets(TaskTemplate $template): void
    {
        $today = $this->dates->today()->toDateString();

        DB::transaction(function () use ($template, $today): void {
            $this->acquireTemplateSynchronizationLock();

            $dateStrings = DB::table('checklist_materializations')
                ->whereDate('date', '>=', $today)
                ->orderBy('date')
                ->pluck('date');

            if ($dateStrings->isEmpty()) {
                return;
            }

            $session = $template->session;
            $sessionValue = $session instanceof TaskSession ? $session->value : $session;

            $dateStrings->chunk(500)->each(function ($dates) use ($template, $sessionValue): void {
                $rows = $dates->map(fn (string $date): array => [
                    'date' => $date,
                    'task_template_id' => $template->id,
                    'task_name' => $template->task_name,
                    'session' => $sessionValue,
                    'is_completed' => false,
                    'completed_at' => null,
                    'completed_by_user_id' => null,
                ])->all();

                DailyChecklist::query()->insertOrIgnore($rows);
            });
        }, 3);
    }

    /**
     * Update the master template and only the incomplete operational rows that
     * have not become historical yet.
     */
    public function updateTemplateAndCurrentAndFutureIncompleteSnapshots(
        TaskTemplate $template,
        string $taskName,
        string $session,
    ): bool {
        $today = $this->dates->today()->toDateString();

        return DB::transaction(function () use ($template, $taskName, $session, $today): bool {
            $this->acquireTemplateSynchronizationLock();

            $lockedTemplate = TaskTemplate::query()
                ->lockForUpdate()
                ->findOrFail($template->getKey());

            if (! $lockedTemplate->is_active) {
                return false;
            }

            $lockedTemplate->forceFill([
                'task_name' => $taskName,
                'session' => $session,
            ])->save();

            DailyChecklist::query()
                ->where('task_template_id', $lockedTemplate->getKey())
                ->whereDate('date', '>=', $today)
                ->where('is_completed', false)
                ->update([
                    'task_name' => $taskName,
                    'session' => $session,
                ]);

            return true;
        }, 3);
    }

    /**
     * Retire a template without deleting historical or completed snapshots.
     */
    public function deactivateTemplateAndRemoveCurrentAndFutureIncompleteSnapshots(TaskTemplate $template): void
    {
        $today = $this->dates->today()->toDateString();

        DB::transaction(function () use ($template, $today): void {
            $this->acquireTemplateSynchronizationLock();

            $lockedTemplate = TaskTemplate::query()
                ->lockForUpdate()
                ->findOrFail($template->getKey());

            $lockedTemplate->forceFill([
                'is_active' => false,
            ])->save();

            DailyChecklist::query()
                ->where('task_template_id', $lockedTemplate->getKey())
                ->whereDate('date', '>=', $today)
                ->where('is_completed', false)
                ->delete();
        }, 3);
    }

    private function isMaterialized(string $date): bool
    {
        return DB::table('checklist_materializations')
            ->whereDate('date', $date)
            ->exists();
    }

    private function acquireTemplateSynchronizationLock(): void
    {
        $lock = DB::table('checklist_sync_locks')
            ->where('name', 'template-synchronization')
            ->lockForUpdate()
            ->first();

        if ($lock === null) {
            throw new LogicException('The checklist template synchronization lock is missing.');
        }
    }
}
