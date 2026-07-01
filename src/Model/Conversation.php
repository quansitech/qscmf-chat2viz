<?php

namespace Qscmf\Chat2Viz\Model;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $table = 'chat2viz_conversations';

    public $timestamps = false;

    protected $fillable = [
        'dashboard_uid',
        'title',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id', 'id');
    }
}
