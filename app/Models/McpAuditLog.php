<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpAuditLog extends Model
{
    protected $fillable = [
        'rpc_method',
        'tool_name',
        'request_payload',
        'response_payload',
        'http_status',
        'duration_ms',
        'caller_ip',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'http_status' => 'integer',
        'duration_ms' => 'integer',
    ];
}
