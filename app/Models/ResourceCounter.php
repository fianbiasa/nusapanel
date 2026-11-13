<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The CPU counter of one of a hosting account's cgroups as last read. A
 * cgroup created again gets a new id, and its counter starts from zero.
 *
 * @property int $id
 * @property int $user_id
 * @property int $cgroup_id
 * @property int $cpu_usec
 */
#[Table('resource_counters', timestamps: false)]
#[Fillable(['user_id', 'cgroup_id', 'cpu_usec'])]
class ResourceCounter extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cgroup_id' => 'integer',
            'cpu_usec' => 'integer',
        ];
    }
}
