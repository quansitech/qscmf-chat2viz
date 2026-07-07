<?php

namespace Qscmf\Chat2Viz\Model;

use Illuminate\Database\Eloquent\Model;

class Dashboard extends Model
{
    // Bare table name. The host's framework grammar adds the project-level
    // DB_PREFIX (Laravel database.connections.*.prefix in v15, ThinkPHP
    // DB_PREFIX in v13) exactly once at query-wrap time. See
    // src/Support/Table.php for the raw-SQL path and the migration contract.
    protected $table = 'chat2viz_dashboards';

    protected $fillable = [
        'uid',
        'title',
        'current_schema',
        'published_version_id',
        'status',
        'dashboard_status',
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
