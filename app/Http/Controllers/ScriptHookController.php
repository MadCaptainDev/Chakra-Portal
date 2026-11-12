<?php

namespace App\Http\Controllers;

use App\Models\Script;
use App\Models\ScriptHook;
use App\Models\Shoot;
use App\Services\Ai\GeminiBudget;
use App\Services\Ai\GeminiBudgetExceeded;
use App\Services\Ai\HookWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Hook ideas for a script: on the script page (anyone who can see scripts),
 * and on set from the shoot reader (the shoot's crew, for its client's
 * scripts). Putting one at the top of the script needs scripts.edit -- or,
 * on set, being on the crew.
 */
class ScriptHookController extends Controller
{
    public function write(Request $request, Script $script, HookWriter $writer): JsonResponse
    {
        return $this->generate($request, $script, $writer);
    }

    public function use(Request $request, Script $script): JsonResponse
    {
        abort_unless($request->user()->can('scripts.edit'), 403);

        return $this->place($request, $script);
    }

    // ——— On set ———

    public function writeOnSet(Request $request, Shoot $shoot, Script $script, HookWriter $writer): JsonResponse
    {
        $this->onSet($request, $shoot, $script);

        return $this->generate($request, $script, $writer);
    }

    public function useOnSet(Request $request, Shoot $shoot, Script $script): JsonResponse
    {
        $this->onSet($request, $shoot, $script);

        return $this->place($request, $script);
    }

    // ——— Shared ———

    private function generate(Request $request, Script $script, HookWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:600'],
            'language' => ['nullable', Rule::in(array_keys(HookWriter::LANGUAGES))],
            'fresh' => ['nullable', 'boolean'],
        ]);

        try {
            $set = $writer->write($script, $request->user(), $data['note'] ?? null, $data['language'] ?? 'script', (bool) ($data['fresh'] ?? false));
        } catch (GeminiBudgetExceeded $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'budget'], 429);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'failed'], 502);
        }

        $s = GeminiBudget::summary();

        return response()->json([
            'hooks' => $set->hooks,
            'reused' => ! $set->wasRecentlyCreated,
            'cost_usd' => $set->wasRecentlyCreated ? $set->cost_usd : 0,
            'budget' => ['spent' => round($s['spent'], 4), 'cap' => $s['cap'], 'blocked' => $s['blocked']],
        ]);
    }

    private function place(Request $request, Script $script): JsonResponse
    {
        $hook = $request->validate([
            'spoken' => ['required', 'string', 'max:300'],
            'on_screen' => ['nullable', 'string', 'max:100'],
            'first_shot' => ['nullable', 'string', 'max:200'],
        ]);

        HookWriter::useOnScript($script, $hook, $request->user());

        return response()->json(['message' => 'Added at the top of the script.']);
    }

    private function onSet(Request $request, Shoot $shoot, Script $script): void
    {
        $user = $request->user();
        $isCrew = $user->isAdmin() || $shoot->started_by_id === $user->id || $shoot->crew()->where('user_id', $user->id)->exists();
        abort_unless($isCrew && $shoot->client_id && $script->client_id === $shoot->client_id, 404);
    }

    /** The last hooks asked for this script, for showing without a new call. */
    public static function last(Script $script): ?array
    {
        return ScriptHook::query()->where('script_id', $script->id)->latest('id')->value('hooks');
    }
}
