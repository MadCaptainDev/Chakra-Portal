<?php

namespace App\Tools\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\KitCustody;
use App\Services\ShootRun;
use App\Tools\Tool;
use App\Tools\ToolException;
use RuntimeException;

/**
 * The shoot day buttons from My Shoots → Run: on the way, reached, start
 * (the shoot goes live), wrap (it completes). The same ShootRun service, the
 * same rule: crew on the shoot, whoever started it, or an admin.
 */
class RunShoot extends Tool
{
    private const ACTIONS = [
        'pick_up_kit', 'on_the_way', 'reached', 'start', 'wrap',
        'next_shoot', 'return_to_office', 'back_at_office', 'file_footage', 'return_kit', 'cancel',
    ];

    public function name(): string
    {
        return 'run_shoot';
    }

    public function title(): string
    {
        return 'Shoot day: kit, on the way, reached, start, wrap, and after';
    }

    public function group(): string
    {
        return 'Shoots';
    }

    public function description(): string
    {
        return 'Move a shoot through its day, as the crew do on My Shoots: on_the_way (crew set off), '
            .'reached (on location; the team gets a staff alert), start (the shoot is live and its clock '
            .'runs) or wrap (it ends and becomes completed). For people on the shoot\'s crew, and admins. '
.'A second "start" on a live shoot changes nothing. '
            .'Kit comes first: on_the_way, reached and start are refused until every item on the shoot\'s kit list is picked up '
            .'(pick_up_kit ticks them all). After wrap: next_shoot (straight on to this person\'s next shoot today; the kit moves with them), '
            .'or return_to_office then back_at_office; file_footage (needs drive, one of '.implode(', ', (array) config('shoots.drives')).'; optional url; '
            .'confirms every video is copied); return_kit (everything back in good order). cancel calls the shoot off and needs a reason.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function availableTo(User $user): bool
    {
        return ! $user->isClient();
    }

    public function schema(): array
    {
        return $this->object([
            'shoot_id' => ['type' => 'integer'],
            'action' => ['type' => 'string', 'enum' => self::ACTIONS],
            'reason' => ['type' => 'string', 'description' => 'For cancel: why the shoot is called off.'],
            'drive' => ['type' => 'string', 'enum' => (array) config('shoots.drives'), 'description' => 'For file_footage: where the footage is.'],
            'url' => ['type' => 'string', 'description' => 'For file_footage: a folder link, if there is one.'],
        ], ['shoot_id', 'action']);
    }

    public function handle(array $arguments, User $user): array
    {
        $shoot = Shoot::find((int) ($arguments['shoot_id'] ?? 0))
            ?? throw new ToolException('There is no shoot with that id.');

        $mayRun = $user->isAdmin()
            || $shoot->started_by_id === $user->id
            || $shoot->crew()->where('user_id', $user->id)->exists();
        if (! $mayRun) {
            throw new ToolException('Only the shoot\'s crew or an admin can do that.');
        }

        $action = (string) ($arguments['action'] ?? '');
        try {
            match ($action) {
                'on_the_way' => ShootRun::depart($shoot, $user),
                'reached' => ShootRun::reached($shoot, $user),
                'start' => ShootRun::start($shoot, $user),
                'wrap' => ShootRun::finish($shoot, $user),
                'pick_up_kit' => KitCustody::pickUpAll($shoot, $user),
                'return_kit' => KitCustody::giveBackAll($shoot, $user),
                'next_shoot' => $shoot = ShootRun::headToNext($shoot, $user),
                'return_to_office' => ShootRun::returnToOffice($shoot, $user),
                'back_at_office' => ShootRun::backAtOffice($shoot, $user),
                'file_footage' => ShootRun::fileFootage(
                    $shoot, $user, (string) ($arguments['drive'] ?? ''), ($arguments['url'] ?? null) ?: null,
                    // Saying so through the tool is the confirmation for every video.
                    $shoot->videos()->pluck('id')->all(),
                ),
                'cancel' => ShootRun::cancel(
                    $shoot, $user,
                    trim((string) ($arguments['reason'] ?? '')) ?: throw new ToolException('Say why the shoot is cancelled (reason).'),
                ),
                default => throw new ToolException('action must be one of: '.implode(', ', self::ACTIONS).'.'),
            };
        } catch (RuntimeException $e) {
            throw new ToolException($e->getMessage());
        }

        return ['done' => $action, 'shoot' => UpdateShoot::shootDetails($shoot->refresh())];
    }
}
