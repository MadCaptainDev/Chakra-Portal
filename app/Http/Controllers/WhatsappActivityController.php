<?php

namespace App\Http\Controllers;

use App\Support\WhatsappActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * "What actually went out over WhatsApp today" -- the operational view the
 * raw webhook log under Settings -> WhatsApp was never meant to be. See
 * App\Support\WhatsappActivity's own doc block for the split.
 */
class WhatsappActivityController extends Controller
{
    public function index(Request $request): View
    {
        $day = $this->resolveDay($request);

        return view('whatsapp-crm.activity.index', [
            'day' => $day,
            'rows' => WhatsappActivity::forDay($day),
            'dailyTotals' => WhatsappActivity::dailyTotals(14),
            'schedule' => WhatsappActivity::dailySchedule(),
        ]);
    }

    private function resolveDay(Request $request): Carbon
    {
        $raw = (string) $request->query('day');

        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }
}
