<?php

namespace Qscmf\Chat2Viz\Model;

use Illuminate\Database\Eloquent\Model;

class DashboardVersion extends Model
{
    // Bare table name. The host's framework grammar adds the project-level
    // DB_PREFIX exactly once at query-wrap time. See src/Support/Table.php.
    protected $table = 'chat2viz_dashboard_versions';

    protected $fillable = [
        'dashboard_id',
        'version',
        'schema',
        'published_at',
        'published_by',
    ];

    protected $casts = [
        'schema' => 'array',
        'dashboard_id' => 'integer',
        'version' => 'integer',
        'published_by' => 'integer',
    ];

    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class, 'dashboard_id');
    }
}
