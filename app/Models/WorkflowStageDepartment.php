<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (stage, department) pairing — see the migration's docblock
 * for why this is a real table instead of a single column on WorkflowStage.
 */
class WorkflowStageDepartment extends Model
{
    protected $fillable = ['stage_id', 'department'];

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'stage_id', 'stage_id');
    }
}
