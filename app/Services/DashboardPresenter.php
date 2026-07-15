<?php

namespace App\Services;

use App\Enums\TaskSession;
use App\Models\DailyChecklist;
use App\Models\TaskTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class DashboardPresenter
{
    public function __construct(
        private readonly MasterAdminSession $adminSession,
        private readonly OperationalDate $dates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function welcome(Request $request): array
    {
        return $this->base(
            request: $request,
            mode: 'welcome',
            date: $this->dates->today(),
            tasks: [],
        );
    }

    /**
     * @param  Collection<int, DailyChecklist>  $checklist
     * @return array<string, mixed>
     */
    public function checklist(Request $request, CarbonImmutable $date, Collection $checklist): array
    {
        $tasks = $checklist->map(function (DailyChecklist $task): array {
            $session = $task->session;

            return [
                'id' => $task->id,
                'text' => $task->task_name,
                'section' => $session instanceof TaskSession ? $session->value : $session,
                'completed' => $task->is_completed,
            ];
        })->values()->all();

        return $this->base(
            request: $request,
            mode: 'checklist',
            date: $date,
            tasks: $tasks,
        );
    }

    /**
     * @param  Collection<int, TaskTemplate>  $templates
     * @param  Collection<int, DailyChecklist>  $completedTasks
     * @return array<string, mixed>
     */
    public function admin(
        Request $request,
        CarbonImmutable $date,
        Collection $templates,
        Collection $completedTasks,
    ): array {
        $props = $this->base(
            request: $request,
            mode: 'admin',
            date: $date,
            tasks: [],
        );

        $props['templates'] = $templates->map(function (TaskTemplate $template): array {
            $session = $template->session;

            return [
                'id' => $template->id,
                'taskName' => $template->task_name,
                'session' => $session instanceof TaskSession ? $session->value : $session,
                'isActive' => $template->is_active,
            ];
        })->values()->all();

        $props['completedTasks'] = $completedTasks->map(function (DailyChecklist $task): array {
            $session = $task->session;
            $completedBy = $task->completedBy;

            return [
                'id' => $task->id,
                'date' => $task->date->toDateString(),
                'text' => $task->task_name,
                'section' => $session instanceof TaskSession ? $session->value : $session,
                'isCompleted' => $task->is_completed,
                'completedAt' => $task->completed_at?->setTimezone($this->dates->timezone())->format('Y-m-d\\TH:i:s.uP'),
                'completedBy' => $completedBy === null ? null : [
                    'id' => $completedBy->id,
                    'name' => $completedBy->name,
                    'username' => $completedBy->username,
                ],
            ];
        })->values()->all();

        return $props;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    private function base(Request $request, string $mode, CarbonImmutable $date, array $tasks): array
    {
        $user = $request->user();

        return [
            'mode' => $mode,
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                ] : null,
                'isAdmin' => $this->adminSession->isAuthenticated($request),
            ],
            'tasks' => $tasks,
            'currentDate' => $date->toDateString(),
            'isReadOnly' => ! $this->dates->isToday($date->toDateString()),
            'templates' => [],
            'completedTasks' => [],
        ];
    }
}
