<?php

namespace Qscmf\Chat2Viz\Model;

use Illuminate\Database\Eloquent\Model;

class ConversationMessage extends Model
{
    // Bare table name. The host's framework grammar adds the project-level
    // DB_PREFIX exactly once at query-wrap time. See src/Support/Table.php.
    protected $table = 'chat2viz_conversation_messages';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'dashboard_uid',
        'role',
        'content',
        'metadata',
        'reasoning_content',
        'tool_calls',
        'message_status',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'tool_calls' => 'array',
    ];
}
