<?php

namespace App\Models;

use App\Enums\TaskSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplate extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_name',
        'session',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'session' => TaskSession::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<TaskTemplate>  $query
     * @return Builder<TaskTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return HasMany<DailyChecklist, $this>
     */
    public function dailyChecklists(): HasMany
    {
        return $this->hasMany(DailyChecklist::class);
    }
}
