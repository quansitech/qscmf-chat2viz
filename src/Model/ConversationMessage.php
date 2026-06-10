<?php

namespace Qscmf\Chat2Viz\Model;

use Illuminate\Database\Eloquent\Model;

class ConversationMessage extends Model
{
    protected $table = 'chat2viz_conversation_messages';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'dashboard_uid',
        'role',
        'content',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
