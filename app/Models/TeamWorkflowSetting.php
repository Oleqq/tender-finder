<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TeamWorkflowSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'review_sla_hours' => 'integer',
            'notify_assignments' => 'boolean',
            'notify_sla' => 'boolean',
            'digest_enabled' => 'boolean',
            'approval_enabled' => 'boolean',
            'approval_min_revenue' => 'decimal:2',
            'approval_max_margin_percent' => 'decimal:2',
            'required_approvals' => 'integer',
            'version' => 'integer',
        ];
    }
}
