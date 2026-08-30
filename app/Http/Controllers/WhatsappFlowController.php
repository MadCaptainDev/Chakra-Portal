<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WhatsappFlow;
use App\Services\WhatsappFlow\DrawflowGraphTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Automations: the visual flows FlowEngine walks against inbound WhatsApp
 * messages (see App\Services\WhatsappFlow\FlowEngine).
 *
 * The `graph` column is FlowEngine's own format -- `{start_node_id, nodes:
 * {id: {type, ...}}}` (see WhatsappFlow's docblock) -- not Drawflow's own
 * export()/import() shape (`{drawflow: {Home: {data: {...}}}}`, confirmed
 * against node_modules/drawflow/README.md after installing it). Those two
 * shapes are genuinely different, so store()/update() below post the raw
 * Drawflow JSON (exactly what `editor.export()` produces -- see
 * resources/js/whatsapp-flow-builder.js, which does no shape translation of
 * its own) and this controller runs it through
 * App\Services\WhatsappFlow\DrawflowGraphTranslator -- the one place that
 * reconciles the two shapes -- before anything reaches the `graph` column.
 * edit()/create() do the reverse for the view, handing flows/edit.blade.php
 * something `editor.import()` can load straight back.
 */
class WhatsappFlowController extends Controller
{
    public function index(): View
    {
        return view('whatsapp-crm.flows.index', [
            'flows' => WhatsappFlow::withCount('sessions')->orderBy('name')->paginate(20),
            'portalClients' => \App\Models\Client::query()->whatsappPortalEnabled()->count(),
            'activePortalFlow' => WhatsappFlow::query()
                ->where('trigger_type', 'client_portal')
                ->where('is_active', true)
                ->exists(),
        ]);
    }

    public function create(): View
    {
        return view('whatsapp-crm.flows.edit', [
            'flow' => new WhatsappFlow(['trigger_type' => 'inbound_message', 'graph' => ['start_node_id' => null, 'nodes' => []]]),
            'assignableUsers' => User::canSee('whatsapp-crm')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by_id'] = $request->user()->id;

        $flow = WhatsappFlow::create($data);

        return redirect()->route('whatsapp-crm.flows.edit', $flow)
            ->with('status', "\"{$flow->name}\" created.");
    }

    public function edit(WhatsappFlow $flow): View
    {
        return view('whatsapp-crm.flows.edit', [
            'flow' => $flow,
            'assignableUsers' => User::canSee('whatsapp-crm')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, WhatsappFlow $flow): RedirectResponse
    {
        $data = $this->validated($request);
        $data['version'] = $flow->version + 1;

        $flow->update($data);

        return redirect()->route('whatsapp-crm.flows.edit', $flow)
            ->with('status', "\"{$flow->name}\" saved.");
    }

    public function destroy(WhatsappFlow $flow): RedirectResponse
    {
        $name = $flow->name;
        $flow->delete();

        return redirect()->route('whatsapp-crm.flows.index')
            ->with('status', "\"{$name}\" deleted.");
    }

    /**
     * Only one `inbound_message` catch-all may run at a time -- FlowEngine's
     * own matchFlow() checks `keyword` flows first and falls back to the
     * first active `inbound_message` flow it finds, so two simultaneously
     * active catch-alls would leave which one actually runs to query
     * ordering rather than anything an admin chose. Enforced here, not left
     * to admin judgment: activating one `inbound_message` flow deactivates
     * every other currently active `inbound_message` flow in the same
     * transaction.
     *
     * `keyword` flows are deliberately NOT covered by this -- FlowEngine
     * matches the first keyword flow whose keyword the message contains, so
     * any number of keyword flows on distinct keywords can run active at
     * once by design. Two active flows configured with the *same* keyword is
     * still possible and left to admin judgment (matchFlow() then picks
     * whichever query ordering returns first) -- the same ambiguity already
     * existed before this UI, it is just now easy to create by hand.
     */
    public function activate(WhatsappFlow $flow): RedirectResponse
    {
        $nodes = is_array($flow->graph['nodes'] ?? null) ? $flow->graph['nodes'] : [];

        if ($nodes === [] || blank($flow->graph['start_node_id'] ?? null)) {
            return redirect()->route('whatsapp-crm.flows.index')
                ->with('error', "\"{$flow->name}\" has no nodes to run yet -- add a start node before activating it.");
        }

        DB::transaction(function () use ($flow) {
            if ($flow->trigger_type === 'inbound_message') {
                WhatsappFlow::where('trigger_type', 'inbound_message')
                    ->where('id', '!=', $flow->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            if ($flow->trigger_type === 'client_portal') {
                WhatsappFlow::where('trigger_type', 'client_portal')
                    ->where('id', '!=', $flow->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            $flow->update(['is_active' => true]);
        });

        return redirect()->route('whatsapp-crm.flows.index')
            ->with('status', "\"{$flow->name}\" activated.");
    }

    public function deactivate(WhatsappFlow $flow): RedirectResponse
    {
        $flow->update(['is_active' => false]);

        return redirect()->route('whatsapp-crm.flows.index')
            ->with('status', "\"{$flow->name}\" deactivated.");
    }

    /**
     * `graph` arrives as a JSON string field holding Drawflow's own raw
     * export() shape -- see the class docblock. It must decode to an array
     * before DrawflowGraphTranslator ever sees it; that translator itself
     * throws (caught below) if a node's own field cannot be cast (currently
     * only make_request's `payload` needing to be valid JSON), and this
     * method adds the one check the translator cannot make on its own --
     * that a non-empty graph has exactly one node marked as its start.
     *
     * A flow with zero nodes is allowed to save (a draft still being built)
     * but can never be activated (see activate()).
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trigger_type' => ['required', Rule::in(['inbound_message', 'keyword', 'label_applied', 'client_portal'])],
            'trigger_config' => ['nullable', 'array'],
            'trigger_config.keyword' => ['required_if:trigger_type,keyword', 'nullable', 'string', 'max:255'],
            'graph' => ['required', 'string'],
        ]);

        $drawflowExport = json_decode($data['graph'], true);

        if (! is_array($drawflowExport)) {
            throw ValidationException::withMessages([
                'graph' => 'The flow graph could not be read. Try saving again.',
            ]);
        }

        try {
            $graph = DrawflowGraphTranslator::toEngineGraph($drawflowExport);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['graph' => $e->getMessage()]);
        }

        if ($graph['nodes'] !== [] && blank($graph['start_node_id'])) {
            throw ValidationException::withMessages([
                'graph' => 'Mark one node as the start of the flow before saving.',
            ]);
        }

        return [
            'name' => $data['name'],
            'trigger_type' => $data['trigger_type'],
            // Only kept for `keyword` -- the keyword field stays in the DOM
            // (merely hidden by CSS) for the other two trigger types, so it
            // still arrives in $data whenever it was left non-empty from an
            // earlier edit; FlowEngine's matchFlow() never reads it for
            // anything but a `keyword` flow, but there is no reason to keep
            // stale trigger_config sitting on a flow that has moved on.
            'trigger_config' => $data['trigger_type'] === 'keyword' ? ($data['trigger_config'] ?? null) : null,
            'graph' => $graph,
        ];
    }
}
