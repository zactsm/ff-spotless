<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminSessionController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistToggleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Middleware\EnsureMasterAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('home');

// Cleaner access is intentionally anonymous. Write safety remains enforced by
// CSRF protection, request validation, throttling, and server-side date checks.
Route::get('/checklist', [ChecklistController::class, 'index'])->name('checklist.index');
Route::post('/tasks/toggle', [ChecklistToggleController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('tasks.toggle');

Route::post('/admin/login', [AdminSessionController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('admin.login');

Route::middleware(EnsureMasterAdmin::class)->prefix('admin')->name('admin.')->group(function (): void {
    Route::post('/logout', [AdminSessionController::class, 'destroy'])->name('logout');
    Route::get('/', [AdminDashboardController::class, 'index'])->name('index');
    Route::post('/templates', [TaskTemplateController::class, 'store'])->name('templates.store');
    Route::patch('/templates/{taskTemplate}', [TaskTemplateController::class, 'update'])->name('templates.update');
    Route::delete('/templates/{taskTemplate}', [TaskTemplateController::class, 'destroy'])->name('templates.destroy');
});
