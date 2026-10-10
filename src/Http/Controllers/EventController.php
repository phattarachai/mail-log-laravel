<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;

class EventController
{
    public function show(Request $request, MailLogGroup $group, MailLog $event): JsonResponse
    {
        abort_unless($event->group_id === $group->id, 404);

        return response()->json([
            'id' => $event->id,
            'group_id' => $event->group_id,
            'status' => $event->status->value,
            'to' => $event->to,
            'cc' => $event->cc,
            'bcc' => $event->bcc,
            'error_message' => $event->error_message,
            'seconds' => $event->seconds,
            'sent_at' => $event->sent_at?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
        ]);
    }
}
