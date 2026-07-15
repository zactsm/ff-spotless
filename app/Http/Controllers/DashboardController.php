<?php

namespace App\Http\Controllers;

use App\Services\DashboardPresenter;
use App\Services\MasterAdminSession;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        DashboardPresenter $presenter,
        MasterAdminSession $adminSession,
    ) {
        if ($adminSession->isAuthenticated($request)) {
            return to_route('admin.index');
        }

        return Inertia::render('Dashboard', $presenter->welcome($request));
    }
}
