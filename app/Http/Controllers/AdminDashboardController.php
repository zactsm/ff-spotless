<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminHistoryRequest;
use App\Models\DailyChecklist;
use App\Models\TaskTemplate;
use App\Services\DashboardPresenter;
use App\Services\OperationalDate;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    public function index(
        AdminHistoryRequest $request,
        DashboardPresenter $presenter,
        OperationalDate $dates,
    ) {
        $date = $request->selectedDate($dates);
        $dateString = $date->toDateString();

        $templates = TaskTemplate::query()
            ->active()
            ->orderBy('id')
            ->get();

        $completedTasks = DailyChecklist::query()
            ->whereDate('date', $dateString)
            ->with('completedBy:id,name,username')
            ->orderBy('id')
            ->get();

        return Inertia::render(
            'Dashboard',
            $presenter->admin($request, $date, $templates, $completedTasks),
        );
    }
}
