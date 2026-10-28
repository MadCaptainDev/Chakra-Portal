<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * "The crew are on location", on WhatsApp, to everyone who can see shoots --
 * the producers chasing the day -- except the person who tapped it, who
 * already knows.
 *
 * Template-only (Shoot::WHATSAPP_TEMPLATE_REACHED): a free-form message only
 * arrives inside the 24-hour window, and most staff never message the
 * portal number. Same rules as SendMissingContentAlerts: nobody without a
 * phone, and one bad number or an unapproved template is logged, never
 * thrown -- the crew member's tap must not fail because a producer's
 * number did.
 */
final class ShootReachedAlert
{
    public static function send(Shoot $shoot, User $reachedBy): int
    {
        $shoot->loadMissing('client');

        $recipients = User::canSee('shoots')
            ->whereKeyNot($reachedBy->id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get()
            // The tapper already knows, on whichever account shares their phone too.
            ->reject(fn (User $user) => filled($reachedBy->phone)
                && WhatsappSender::normalise((string) $user->phone) === WhatsappSender::normalise((string) $reachedBy->phone));

        $recipients = WhatsappSender::onePerPhone($recipients);

        $where = $shoot->location ?: ($shoot->client?->name ?? 'no location set');
        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                WhatsappSender::make()->sendTemplate(
                    to: $recipient->phone,
                    template: Shoot::WHATSAPP_TEMPLATE_REACHED,
                    bodyParameters: [
                        $reachedBy->name,
                        $shoot->title,
                        $where,
                        ($shoot->reached_at ?? now())->format('g:i A'),
                    ],
                );
                $sent++;
            } catch (RuntimeException $e) {
                Log::error('Shoot reached WhatsApp alert failed.', [
                    'shoot_id' => $shoot->id,
                    'user_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}
