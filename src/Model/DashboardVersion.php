<?php

namespace Qscmf\Chat2Viz\Model;

use Illuminate\Database\Eloquent\Model;

class DashboardVersion extends Model
{
    protected $table = 'qs_chat2viz_dashboard_versions';

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
