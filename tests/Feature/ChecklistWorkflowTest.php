<?php

namespace Tests\Feature;

use App\Exceptions\ChecklistDateOutsideMaterializationWindow;
use App\Models\DailyChecklist;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ChecklistMaterializer;
use App\Services\OperationalDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('checklist.timezone', 'Asia/Kuala_Lumpur');
        config()->set('checklist.past_materialization_days', 365);
        config()->set('checklist.future_materialization_days', 365);
        config()->set('checklist.admin_password', 'test-master-password');
        config()->set('app.locale', 'ms');
        config()->set('app.fallback_locale', 'ms');
        app()->setLocale('ms');

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-07-15 09:00:00.123456', 'Asia/Kuala_Lumpur'),
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_date_is_materialized_once_and_is_not_regenerated(): void
    {
        $firstTemplate = TaskTemplate::query()->create([
            'task_name' => 'Sanitize front counter',
            'session' => 'morning',
            'is_active' => true,
        ]);

        $materializer = app(ChecklistMaterializer::class);
        $date = app(OperationalDate::class)->today();

        $firstChecklist = $materializer->forDate($date);

        TaskTemplate::query()->create([
            'task_name' => 'Restock cleaning supplies',
            'session' => 'afternoon',
            'is_active' => true,
        ]);

        $secondChecklist = $materializer->forDate($date);

        $this->assertCount(1, $firstChecklist);
        $this->assertCount(1, $secondChecklist);
        $this->assertSame($firstTemplate->id, $secondChecklist->sole()->task_template_id);
        $this->assertDatabaseCount('daily_checklists', 1);
    }

    public function test_missing_dates_outside_the_materialization_window_are_rejected(): void
    {
        $date = app(OperationalDate::class)->today()->subDays(366);

        $this->expectException(ChecklistDateOutsideMaterializationWindow::class);

        app(ChecklistMaterializer::class)->forDate($date);
    }

    public function test_future_dates_outside_the_materialization_window_are_rejected(): void
    {
        $date = app(OperationalDate::class)->today()->addDays(366);

        $this->expectException(ChecklistDateOutsideMaterializationWindow::class);

        app(ChecklistMaterializer::class)->forDate($date);
    }

    public function test_first_materialization_accepts_both_365_day_boundaries(): void
    {
        TaskTemplate::query()->create([
            'task_name' => 'Boundary task',
            'session' => 'morning',
            'is_active' => true,
        ]);

        $dates = app(OperationalDate::class);
        $materializer = app(ChecklistMaterializer::class);

        $this->assertCount(1, $materializer->forDate($dates->today()->subDays(365)));
        $this->assertCount(1, $materializer->forDate($dates->today()->addDays(365)));
    }

    public function test_anonymous_cleaner_can_toggle_todays_row_and_a_retick_receives_a_new_timestamp(): void
    {
        $today = app(OperationalDate::class)->today()->toDateString();
        $task = $this->dailyTask($today);

        $this->post('/tasks/toggle', [
            'task_id' => $task->id,
            'date' => $today,
            'is_completed' => true,
        ])
            ->assertRedirect(route('checklist.index', ['date' => $today]));

        $task->refresh();
        $firstCompletion = $task->completed_at;

        $this->assertTrue($task->is_completed);
        $this->assertNull($task->completed_by_user_id);
        $this->assertNotNull($firstCompletion);
        $this->assertSame('123456', $firstCompletion?->format('u'));

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-07-15 09:00:30', 'Asia/Kuala_Lumpur'),
        );

        $this->post('/tasks/toggle', [
            'task_id' => $task->id,
            'date' => $today,
            'is_completed' => true,
        ])
            ->assertRedirect(route('checklist.index', ['date' => $today]));

        $task->refresh();
        $this->assertSame($firstCompletion?->format('U.u'), $task->completed_at?->format('U.u'));

        $this->post('/tasks/toggle', [
            'task_id' => $task->id,
            'date' => $today,
            'is_completed' => false,
        ])
            ->assertRedirect(route('checklist.index', ['date' => $today]));

        $task->refresh();
        $this->assertFalse($task->is_completed);
        $this->assertNull($task->completed_at);
        $this->assertNull($task->completed_by_user_id);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-07-15 09:01:00', 'Asia/Kuala_Lumpur'),
        );

        $this->post('/tasks/toggle', [
            'task_id' => $task->id,
            'date' => $today,
            'is_completed' => true,
        ]);

        $task->refresh();
        $this->assertTrue($task->is_completed);
        $this->assertNotSame($firstCompletion?->format('U.u'), $task->completed_at?->format('U.u'));
    }

    public function test_non_current_checklists_are_read_only_at_the_write_endpoint(): void
    {
        $pastDate = app(OperationalDate::class)->today()->subDay()->toDateString();
        $task = $this->dailyTask($pastDate);

        $this->post('/tasks/toggle', [
            'task_id' => $task->id,
            'date' => $pastDate,
            'is_completed' => true,
        ])
            ->assertForbidden();

        $task->refresh();
        $this->assertFalse($task->is_completed);
        $this->assertNull($task->completed_at);
    }

    public function test_toggle_rejects_a_client_supplied_date_that_does_not_match_the_row(): void
    {
        $today = app(OperationalDate::class)->today()->toDateString();
        $task = $this->dailyTask($today);

        $this->from('/checklist?date='.$today)
            ->post('/tasks/toggle', [
                'task_id' => $task->id,
                'date' => app(OperationalDate::class)->today()->subDay()->toDateString(),
                'is_completed' => true,
            ])
            ->assertRedirect('/checklist?date='.$today)
            ->assertSessionHasErrors('date');

        $task->refresh();
        $this->assertFalse($task->is_completed);
    }

    public function test_past_and_future_checklist_pages_are_read_only(): void
    {
        $dates = app(OperationalDate::class);
        $materializer = app(ChecklistMaterializer::class);

        TaskTemplate::query()->create([
            'task_name' => 'Read-only task',
            'session' => 'morning',
            'is_active' => true,
        ]);

        config()->set('app.asset_url', 'https://assets.test');

        foreach ([$dates->today()->subDay(), $dates->today()->addDay()] as $date) {
            $materializer->forDate($date);

            $this->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => hash('xxh128', 'https://assets.test'),
            ])
                ->get('/checklist?date='.$date->toDateString())
                ->assertOk()
                ->assertJsonPath('component', 'Dashboard')
                ->assertJsonPath('props.currentDate', $date->toDateString())
                ->assertJsonPath('props.isReadOnly', true);
        }
    }

    public function test_cleaner_checklist_is_available_without_an_account(): void
    {
        TaskTemplate::query()->create([
            'task_name' => 'Public cleaner task',
            'session' => 'morning',
            'is_active' => false,
        ]);

        $this->get('/checklist')->assertOk();
    }

    public function test_application_shell_uses_the_malay_locale(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<html lang="ms">', false);

        $manifest = json_decode(
            file_get_contents(public_path('manifest.webmanifest')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('ms', $manifest['lang']);
        $this->assertSame('Operasi senarai semak mudah alih untuk kakitangan FF Spotless.', $manifest['description']);
    }

    public function test_template_validation_uses_malay_messages_and_attributes(): void
    {
        $this->post('/admin/login', ['password' => 'test-master-password'])
            ->assertRedirect(route('admin.index'));

        $this->from(route('admin.index'))
            ->post(route('admin.templates.store'), [
                'task_name' => '',
                'session' => 'overnight',
            ])
            ->assertRedirect(route('admin.index'))
            ->assertSessionHasErrors([
                'task_name' => 'Nama tugasan diperlukan.',
                'session' => 'Sesi tugasan tidak sah.',
            ]);
    }

    public function test_date_validation_uses_the_malay_message_catalogue(): void
    {
        $this->from(route('home'))
            ->get('/checklist?date=15-07-2026')
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors([
                'date' => 'Tarikh mesti menggunakan format YYYY-MM-DD.',
            ]);
    }

    public function test_a_new_template_is_added_only_to_existing_current_and_future_sheets(): void
    {
        $initialTemplate = TaskTemplate::query()->create([
            'task_name' => 'Initial task',
            'session' => 'morning',
            'is_active' => true,
        ]);
        $dates = app(OperationalDate::class);
        $materializer = app(ChecklistMaterializer::class);
        $past = $dates->today()->subDay();
        $today = $dates->today();
        $future = $dates->today()->addDay();

        $materializer->forDate($past);
        $materializer->forDate($today);
        $materializer->forDate($future);

        $this->post('/admin/login', ['password' => 'test-master-password'])
            ->assertRedirect(route('admin.index'));

        $this->post('/admin/templates', [
            'task_name' => 'New future task',
            'session' => 'evening',
        ])->assertRedirect(route('admin.index'));

        $newTemplate = TaskTemplate::query()
            ->where('task_name', 'New future task')
            ->sole();

        $this->assertDatabaseMissing('daily_checklists', [
            'date' => $past->toDateString(),
            'task_template_id' => $newTemplate->id,
        ]);
        $this->assertDatabaseHas('daily_checklists', [
            'date' => $today->toDateString(),
            'task_template_id' => $newTemplate->id,
            'is_completed' => false,
        ]);
        $this->assertDatabaseHas('daily_checklists', [
            'date' => $future->toDateString(),
            'task_template_id' => $newTemplate->id,
            'is_completed' => false,
        ]);

        $this->assertDatabaseCount('daily_checklists', 5);
        $this->assertSame($initialTemplate->id, $materializer->forDate($past)->sole()->task_template_id);
    }

    public function test_an_empty_materialized_sheet_remains_immutable_after_a_template_is_added(): void
    {
        $dates = app(OperationalDate::class);
        $materializer = app(ChecklistMaterializer::class);
        $past = $dates->today()->subDay();
        $today = $dates->today();
        $future = $dates->today()->addDay();

        $this->assertCount(0, $materializer->forDate($past));
        $this->assertCount(0, $materializer->forDate($today));
        $this->assertCount(0, $materializer->forDate($future));
        $this->assertDatabaseHas('checklist_materializations', ['date' => $past->toDateString()]);

        $this->post('/admin/login', ['password' => 'test-master-password'])
            ->assertRedirect(route('admin.index'));

        $this->post('/admin/templates', [
            'task_name' => 'First operational task',
            'session' => 'morning',
        ])->assertRedirect(route('admin.index'));

        $template = TaskTemplate::query()->where('task_name', 'First operational task')->sole();

        $this->assertDatabaseMissing('daily_checklists', [
            'date' => $past->toDateString(),
            'task_template_id' => $template->id,
        ]);
        $this->assertDatabaseHas('daily_checklists', [
            'date' => $today->toDateString(),
            'task_template_id' => $template->id,
        ]);
        $this->assertDatabaseHas('daily_checklists', [
            'date' => $future->toDateString(),
            'task_template_id' => $template->id,
        ]);
        $this->assertCount(0, $materializer->forDate($past));
    }

    public function test_admin_can_update_a_template_and_only_current_and_future_incomplete_snapshots(): void
    {
        $template = TaskTemplate::query()->create([
            'task_name' => 'Initial template name',
            'session' => 'morning',
            'is_active' => true,
        ]);
        $dates = app(OperationalDate::class);
        $materializer = app(ChecklistMaterializer::class);
        $past = $dates->today()->subDay();
        $today = $dates->today();
        $completedFuture = $dates->today()->addDay();
        $incompleteFuture = $dates->today()->addDays(2);

        foreach ([$past, $today, $completedFuture, $incompleteFuture] as $date) {
            $materializer->forDate($date);
        }

        $completedSnapshot = DailyChecklist::query()
            ->whereDate('date', $completedFuture->toDateString())
            ->where('task_template_id', $template->id)
            ->sole();
        $completedAt = CarbonImmutable::parse('2026-07-15 04:30:00', 'UTC');
        $completedSnapshot->forceFill([
            'is_completed' => true,
            'completed_at' => $completedAt,
        ])->save();

        $this->post('/admin/login', ['password' => 'test-master-password'])
            ->assertRedirect(route('admin.index'));

        $this->patch(route('admin.templates.update', $template), [
            'task_name' => 'Updated template name',
            'session' => 'evening',
        ])->assertRedirect(route('admin.index'));

        $template->refresh();
        $this->assertSame('Updated template name', $template->task_name);
        $this->assertSame('evening', $template->session->value);

        $this->assertDatabaseHas('daily_checklists', [
            'date' => $past->toDateString(),
            'task_template_id' => $template->id,
            'task_name' => 'Initial template name',
            'session' => 'morning',
            'is_completed' => false,
        ]);
        $this->assertDatabaseHas('daily_checklists', [
            'date' => $today->toDateString(),
            'task_template_id' => $template->id,
            'task_name' => 'Updated template name',
            'session' => 'evening',
            'is_completed' => false,
        ]);
        $this->assertDatabaseHas('daily_checklists', [
            'date' => $incompleteFuture->toDateString(),
            'task_template_id' => $template->id,
            'task_name' => 'Updated template name',
            'session' => 'evening',
            'is_completed' => false,
        ]);

        $completedSnapshot->refresh();
        $this->assertTrue($completedSnapshot->is_completed);
        $this->assertSame('Initial template name', $completedSnapshot->task_name);
        $this->assertSame('morning', $completedSnapshot->session->value);
        $this->assertSame(
            $completedAt->format('Y-m-d H:i:s.u'),
            $completedSnapshot->completed_at?->setTimezone('UTC')->format('Y-m-d H:i:s.u'),
        );

        $newFutureSheet = $materializer->forDate($dates->today()->addDays(3));
        $this->assertSame('Updated template name', $newFutureSheet->sole()->task_name);
        $this->assertSame('evening', $newFutureSheet->sole()->session->value);
    }

    public function test_admin_can_deactivate_a_template_and_remove_only_current_and_future_incomplete_snapshots(): void
    {
        $template = TaskTemplate::query()->create([
            'task_name' => 'Template to retire',
            'session' => 'afternoon',
            'is_active' => true,
        ]);
        $dates = app(OperationalDate::class);
        $materializer = app(ChecklistMaterializer::class);
        $past = $dates->today()->subDay();
        $today = $dates->today();
        $completedFuture = $dates->today()->addDay();
        $incompleteFuture = $dates->today()->addDays(2);

        foreach ([$past, $today, $completedFuture, $incompleteFuture] as $date) {
            $materializer->forDate($date);
        }

        $completedSnapshot = DailyChecklist::query()
            ->whereDate('date', $completedFuture->toDateString())
            ->where('task_template_id', $template->id)
            ->sole();
        $completedAt = CarbonImmutable::parse('2026-07-15 05:30:00', 'UTC');
        $completedSnapshot->forceFill([
            'is_completed' => true,
            'completed_at' => $completedAt,
        ])->save();

        $this->post('/admin/login', ['password' => 'test-master-password'])
            ->assertRedirect(route('admin.index'));

        $this->delete(route('admin.templates.destroy', $template))
            ->assertRedirect(route('admin.index'));

        $this->assertFalse((bool) $template->refresh()->is_active);
        $this->assertDatabaseHas('daily_checklists', [
            'date' => $past->toDateString(),
            'task_template_id' => $template->id,
            'task_name' => 'Template to retire',
            'is_completed' => false,
        ]);
        $this->assertDatabaseMissing('daily_checklists', [
            'date' => $today->toDateString(),
            'task_template_id' => $template->id,
        ]);
        $this->assertDatabaseMissing('daily_checklists', [
            'date' => $incompleteFuture->toDateString(),
            'task_template_id' => $template->id,
        ]);

        $completedSnapshot->refresh();
        $this->assertTrue($completedSnapshot->is_completed);
        $this->assertSame('Template to retire', $completedSnapshot->task_name);
        $this->assertSame(
            $completedAt->format('Y-m-d H:i:s.u'),
            $completedSnapshot->completed_at?->setTimezone('UTC')->format('Y-m-d H:i:s.u'),
        );

        $this->assertCount(0, $materializer->forDate($dates->today()->addDays(3)));

        config()->set('app.asset_url', 'https://assets.test');

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash('xxh128', 'https://assets.test'),
        ])
            ->get(route('admin.index'))
            ->assertOk()
            ->assertJsonCount(0, 'props.templates');
    }

    public function test_archived_templates_cannot_be_updated_and_repeated_deletion_is_idempotent(): void
    {
        $template = TaskTemplate::query()->create([
            'task_name' => 'Archive test template',
            'session' => 'morning',
            'is_active' => true,
        ]);
        $today = app(OperationalDate::class)->today();
        app(ChecklistMaterializer::class)->forDate($today);

        $this->post('/admin/login', ['password' => 'test-master-password'])
            ->assertRedirect(route('admin.index'));

        $this->delete(route('admin.templates.destroy', $template))
            ->assertRedirect(route('admin.index'));

        $this->patch(route('admin.templates.update', $template), [
            'task_name' => 'Should not be applied',
            'session' => 'evening',
        ])->assertNotFound();

        $this->delete(route('admin.templates.destroy', $template))
            ->assertRedirect(route('admin.index'));

        $this->assertDatabaseHas('task_templates', [
            'id' => $template->id,
            'task_name' => 'Archive test template',
            'session' => 'morning',
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('daily_checklists', [
            'date' => $today->toDateString(),
            'task_template_id' => $template->id,
        ]);
    }

    public function test_template_update_and_deletion_require_a_master_admin_session(): void
    {
        $template = TaskTemplate::query()->create([
            'task_name' => 'Protected template',
            'session' => 'morning',
            'is_active' => true,
        ]);
        $today = app(OperationalDate::class)->today();
        app(ChecklistMaterializer::class)->forDate($today);

        $this->patch(route('admin.templates.update', $template), [
            'task_name' => 'Unauthorised update',
            'session' => 'evening',
        ])
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors([
                'password' => 'Akses pentadbir diperlukan.',
            ]);

        $this->delete(route('admin.templates.destroy', $template))
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors([
                'password' => 'Akses pentadbir diperlukan.',
            ]);

        $this->assertDatabaseHas('task_templates', [
            'id' => $template->id,
            'task_name' => 'Protected template',
            'session' => 'morning',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('daily_checklists', [
            'date' => $today->toDateString(),
            'task_template_id' => $template->id,
            'task_name' => 'Protected template',
            'is_completed' => false,
        ]);
    }

    public function test_staff_management_endpoints_have_been_removed(): void
    {
        $this->post('/admin/login', ['password' => 'test-master-password'])
            ->assertRedirect(route('admin.index'));

        $this->post('/admin/staff', [
            'name' => 'Legacy Staff',
            'username' => 'legacy.staff',
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ])->assertNotFound();

        $this->patch('/admin/staff/1/password', [
            'password' => 'ChangedPass2!',
            'password_confirmation' => 'ChangedPass2!',
        ])->assertNotFound();

        $this->patch('/admin/staff/1/status', [
            'is_active' => false,
        ])->assertNotFound();
    }

    public function test_admin_history_includes_the_completion_timestamp_and_staff_member(): void
    {
        $staff = User::factory()->create(['name' => 'Nora Cleaner', 'username' => 'nora.cleaner']);
        $today = app(OperationalDate::class)->today()->toDateString();
        $task = $this->dailyTask($today);
        $completedAt = CarbonImmutable::parse('2026-07-15 04:30:00', 'UTC');

        $task->forceFill([
            'is_completed' => true,
            'completed_at' => $completedAt,
            'completed_by_user_id' => $staff->id,
        ])->save();

        $otherTask = $this->dailyTask(app(OperationalDate::class)->today()->subDay()->toDateString());
        $otherTask->forceFill([
            'is_completed' => true,
            'completed_at' => $completedAt,
            'completed_by_user_id' => $staff->id,
        ])->save();

        $this->post('/admin/login', ['password' => 'test-master-password'])
            ->assertRedirect(route('admin.index'));

        config()->set('app.asset_url', 'https://assets.test');

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash('xxh128', 'https://assets.test'),
        ])
            ->get('/admin?date='.$today);

        $response
            ->assertOk()
            ->assertJsonPath('component', 'Dashboard')
            ->assertJsonMissingPath('props.staff')
            ->assertJsonPath('props.completedTasks.0.id', $task->id)
            ->assertJsonPath('props.completedTasks.0.completedBy.username', 'nora.cleaner')
            ->assertJsonPath('props.completedTasks.0.completedAt', '2026-07-15T12:30:00.000000+08:00')
            ->assertJsonCount(1, 'props.completedTasks');
    }

    public function test_master_session_is_invalidated_when_the_environment_password_changes(): void
    {
        $this->post('/admin/login', ['password' => 'test-master-password'])
            ->assertRedirect(route('admin.index'));

        config()->set('checklist.admin_password', 'a-new-master-password');

        $this->get('/admin')
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('password');
    }

    public function test_failed_master_admin_logins_are_throttled(): void
    {
        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->post('/admin/login', ['password' => 'incorrect-master-password'])
                ->assertSessionHasErrors('password');
        }

        $this->post('/admin/login', ['password' => 'incorrect-master-password'])
            ->assertTooManyRequests();
    }

    public function test_throttled_inertia_login_shows_an_inline_error_instead_of_an_html_error_page(): void
    {
        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->post('/admin/login', ['password' => 'incorrect-master-password'])
                ->assertSessionHasErrors('password');
        }

        $this->from('/')
            ->withHeader('X-Inertia', 'true')
            ->post('/admin/login', ['password' => 'incorrect-master-password'])
            ->assertRedirect('/')
            ->assertHeader('Retry-After')
            ->assertSessionHasErrors([
                'rate_limit' => 'Terlalu banyak percubaan. Sila tunggu satu minit sebelum mencuba semula.',
            ]);
    }

    private function dailyTask(string $date): DailyChecklist
    {
        $template = TaskTemplate::query()->create([
            'task_name' => 'Clean entrance glass',
            'session' => 'morning',
            'is_active' => true,
        ]);

        return DailyChecklist::query()->create([
            'date' => $date,
            'task_template_id' => $template->id,
            'task_name' => $template->task_name,
            'session' => $template->session,
            'is_completed' => false,
        ]);
    }
}
