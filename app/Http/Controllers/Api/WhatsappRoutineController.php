<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\WhatsappSetting;
use App\Services\WhatsappSender;
use App\Support\WhatsappServiceWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

class WhatsappRoutineController extends Controller
{
    /**
     * Send a WhatsApp message to every admin via Claude Routines.
     *
     * Reuses the same admin lookup as whatsapp:morning-brief (role=admin,
     * phone set) rather than a single hardcoded number, so it stays correct
     * as admins are added or their numbers change. Text messages are only
     * attempted inside Meta's 24-hour window; use type=template to reach an
     * admin outside it.
     *
     * POST /api/routines/whatsapp/send
     *
     * Request body:
     * {
     *   "message": "text message body",
     *   "type": "text" (default) | "template",
     *   "template": "template_name" (required if type=template),
     *   "params": ["param1", "param2"] (optional template params)
     * }
     *
     * Response:
     * {
     *   "success": true,
     *   "sent": [{"admin": "Name", "wamid": "..."}],
     *   "skipped": [{"admin": "Name", "reason": "outside 24-hour window"}],
     *   "failed": [{"admin": "Name", "error": "..."}]
     * }
     */
    public function sendToAdmin(Request $request): JsonResponse
    {
        $settings = WhatsappSetting::current();

        if (!$settings->canSend()) {
            return response()->json([
                'success' => false,
                'error' => 'WhatsApp not configured. Set access token and phone number ID in Settings.',
            ], 503);
        }

        $type = $request->input('type', 'text');
        $message = $request->input('message');
        $template = $request->input('template');
        $params = $request->input('params', []);

        if (empty($message) && $type === 'text') {
            return response()->json([
                'success' => false,
                'error' => 'message field is required for text type',
            ], 400);
        }

        if ($type === 'template' && empty($template)) {
            return response()->json([
                'success' => false,
                'error' => 'template field is required for template type',
            ], 400);
        }

        $admins = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get();

        if ($admins->isEmpty()) {
            return response()->json([
                'success' => false,
                'error' => 'No admin has a phone number on file.',
            ], 503);
        }

        $sender = WhatsappSender::make();
        $sent = [];
        $skipped = [];
        $failed = [];

        foreach ($admins as $admin) {
            $to = WhatsappSender::normalise((string) $admin->phone);

            if ($type === 'text' && !WhatsappServiceWindow::isOpen($to)) {
                $skipped[] = ['admin' => $admin->name, 'reason' => 'outside 24-hour window'];

                continue;
            }

            try {
                $result = $type === 'template'
                    ? $sender->sendTemplate(
                        to: $to,
                        template: $template,
                        language: 'en_US',
                        bodyParameters: (array) $params
                    )
                    : $sender->sendText($to, $message);

                $sent[] = ['admin' => $admin->name, 'wamid' => $result['wamid']];
            } catch (Throwable $e) {
                $failed[] = ['admin' => $admin->name, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'success' => count($sent) > 0,
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    /**
     * Send a WhatsApp message to a specific recipient via Claude Routines.
     *
     * POST /api/routines/whatsapp/send-to
     *
     * Request body:
     * {
     *   "to": "+919876543210" or "9876543210",
     *   "message": "text message body",
     *   "type": "text" (default) | "template",
     *   "template": "template_name" (required if type=template),
     *   "params": ["param1", "param2"] (optional template params)
     * }
     */
    public function sendToNumber(Request $request): JsonResponse
    {
        $settings = WhatsappSetting::current();

        if (!$settings->canSend()) {
            return response()->json([
                'success' => false,
                'error' => 'WhatsApp not configured',
            ], 503);
        }

        $to = $request->input('to');
        $type = $request->input('type', 'text');
        $message = $request->input('message');
        $template = $request->input('template');
        $params = $request->input('params', []);

        if (empty($to)) {
            return response()->json([
                'success' => false,
                'error' => '"to" field is required',
            ], 400);
        }

        if (empty($message) && $type === 'text') {
            return response()->json([
                'success' => false,
                'error' => 'message field is required for text type',
            ], 400);
        }

        if ($type === 'template' && empty($template)) {
            return response()->json([
                'success' => false,
                'error' => 'template field is required for template type',
            ], 400);
        }

        try {
            $sender = WhatsappSender::make();

            if ($type === 'template') {
                $result = $sender->sendTemplate(
                    to: $to,
                    template: $template,
                    language: 'en_US',
                    bodyParameters: (array) $params
                );
            } else {
                $result = $sender->sendText($to, $message);
            }

            return response()->json([
                'success' => true,
                'wamid' => $result['wamid'],
                'message' => 'Message sent',
                'to' => $to,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
