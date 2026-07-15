<?php

namespace App\Models;

use App\Enums\TaskSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyChecklist extends Model
{
    public $timestamps = false;

    /**
     * Preserve the precision provided by the timestamp(6) database column.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'task_template_id',
        'task_name',
        'session',
        'is_completed',
        'completed_at',
        'completed_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'session' => TaskSession::class,
            'is_completed' => 'boolean',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TaskTemplate, $this>
     */
    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
