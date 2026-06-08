<?php

namespace Qscmf\Chat2Viz\Model;

use Illuminate\Database\Eloquent\Model;

class Dashboard extends Model
{
    protected $table = 'qs_chat2viz_dashboards';

    protected $fillable = [
        'uid',
        'title',
        'current_schema',
        'published_version_id',
        'conversation_id',
        'status',
        'created_by',
    ];

    protected $casts = [
        'current_schema' => 'array',
        'published_version_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function versions()
    {
        return $this->hasMany(DashboardVersion::class, 'dashboard_id');
    }
}
