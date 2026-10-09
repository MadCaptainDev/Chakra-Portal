<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Proposal;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ClientBriefNudge;
use App\Services\InvoiceWhatsappSender;
use App\Support\TimesheetVenture;
use App\Support\WhatsappServiceWindow;
use App\Tools\Clients\SendBriefReminder;
use App\Tools\CreateTodo;
use App\Tools\Proposals\SendProposalWhatsapp;
use App\Tools\Shoots\AssignShootCrew;
use App\Tools\Shoots\CreateShoot;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * What the Studio Map can do, as JSON for its dialogs. Admin-only (see the
 * route group).
 *
 * Wherever one exists, an action runs the same App\Tools class the MCP
 * connector and the WhatsApp assistant use -- same validation, same refusal
 * wording, same notifications -- so doing a thing from the map can never
 * follow different rules from doing it any other way. Removing crew and the
 * invoice send have no tool, so they go through the same code as their own
 * screens (ShootCrewController::destroy's check, InvoiceWhatsappSender).
 *
 * Anything that messages a client is two calls: preview() says exactly who
 * it goes to and what they will read, and only send() sends. The map shows
 * the first and asks before making the second.
 */
class StudioMapActionController extends Controller
{
    public function assignCrew(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shoot_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
            'role' => ['nullable', 'string', 'max:80'],
            'call_time' => ['nullable', 'date_format:H:i'],
        ]);

        $member = User::query()->staff()->findOrFail($data['user_id']);

        return $this->run(new AssignShootCrew, [
            'shoot_id' => $data['shoot_id'],
            // Email, not name: two people can share a first name, never an email.
            'person' => $member->email,
            'role' => $data['role'] ?? null,
            'call_time' => $data['call_time'] ?? null,
        ], $request, fn (array $r) => "{$r['person']} added to {$r['shoot']}.");
    }

    public function removeCrew(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shoot_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
        ]);

        $shoot = Shoot::findOrFail($data['shoot_id']);
        $row = $shoot->crew()->where('user_id', $data['user_id'])->with('user')->first();

        if ($row === null) {
            return response()->json(['message' => 'They are not on that shoot.'], 422);
        }

        $row->delete();

        return response()->json(['message' => ($row->user?->name ?? 'They').' removed from '.$shoot->title.'.']);
    }

    public function createShoot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:160'],
            'starts_at' => ['required', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:'.Shoot::STATUS_PLANNED.','.Shoot::STATUS_CONFIRMED],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Dropping a person on a building with no shoot books one with
            // them on it: one dialog, two actions, both through their tools.
            'crew_user_id' => ['nullable', 'integer'],
            'crew_role' => ['nullable', 'string', 'max:80'],
        ]);

        $tool = new CreateShoot;
        $this->authoriseTool($tool, $request);

        try {
            $created = $tool->handle([
                'title' => $data['title'],
                'starts_at' => $data['starts_at'],
                'client' => (string) $data['client_id'],
                'location' => $data['location'] ?? null,
                'status' => $data['status'] ?? Shoot::STATUS_PLANNED,
                'notes' => $data['notes'] ?? null,
            ], $request->user());

            $message = "Booked {$created['title']} for {$created['starts_at']}.";

            if (! empty($data['crew_user_id'])) {
                $member = User::query()->staff()->findOrFail($data['crew_user_id']);
                $assigned = (new AssignShootCrew)->handle([
                    'shoot_id' => $created['shoot_id'],
                    'person' => $member->email,
                    'role' => $data['crew_role'] ?? null,
                ], $request->user());
                $message .= " {$assigned['person']} is on it.";
            }
        } catch (ToolException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $message, 'result' => $created]);
    }

    public function createTodo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'due_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $member = User::query()->staff()->findOrFail($data['user_id']);
        $client = isset($data['client_id']) ? Client::find($data['client_id']) : null;

        return $this->run(new CreateTodo, [
            'title' => $data['title'],
            'person' => $member->email,
            // To-dos are filed against a venture -- the same join timesheets use.
            'client' => $client ? TimesheetVenture::canonicalForClient($client) : null,
            'due_on' => $data['due_on'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $request, fn (array $r) => "To-do for {$r['for']}: {$r['title']}.");
    }

    /**
     * Exactly what a send would do, without doing it: who, which number, and
     * the words they will read -- which differ with WhatsApp's 24-hour window,
     * so the preview asks the same question the send will.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:invoice,proposal,brief'],
            'id' => ['required', 'integer'],
        ]);

        return match ($data['kind']) {
            'invoice' => $this->previewInvoice(Invoice::with('client')->findOrFail($data['id'])),
            'proposal' => $this->previewProposal(Proposal::with('client')->findOrFail($data['id'])),
            'brief' => $this->previewBrief(Client::findOrFail($data['id'])),
        };
    }

    public function send(Request $request, InvoiceWhatsappSender $invoices): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:invoice,proposal,brief'],
            'id' => ['required', 'integer'],
            'phone' => ['nullable', 'string', 'min:10', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
        ], ['phone.regex' => 'That doesn\'t look like a phone number.']);

        if ($data['kind'] === 'brief') {
            $client = Client::findOrFail($data['id']);

            return $this->run(new SendBriefReminder, ['client' => (string) $client->id], $request,
                fn () => "Brief reminder sent to {$client->name} on WhatsApp.");
        }

        if (blank($data['phone'] ?? null)) {
            return response()->json(['message' => 'Which number should it go to?'], 422);
        }

        if ($data['kind'] === 'proposal') {
            $proposal = Proposal::findOrFail($data['id']);

            return $this->run(new SendProposalWhatsapp, ['proposal' => (string) $proposal->id, 'phone' => $data['phone']], $request,
                fn () => "Proposal \"{$proposal->title}\" sent to {$data['phone']} on WhatsApp.");
        }

        $invoice = Invoice::with('client')->findOrFail($data['id']);
        abort_unless($request->user()->can('invoices.approve'), 403);

        try {
            $invoices->send($invoice, $data['phone'], $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Invoice {$invoice->invoice_number} sent to {$data['phone']} on WhatsApp."]);
    }

    private function previewInvoice(Invoice $invoice): JsonResponse
    {
        if (! $invoice->isSendableViaWhatsapp()) {
            return response()->json(['message' => 'This invoice is still awaiting approval and cannot be sent.'], 422);
        }

        return response()->json([
            'to' => $invoice->client?->name,
            'phone' => $invoice->client?->phone,
            'phone_editable' => true,
            'channel' => 'template',
            'message' => InvoiceWhatsappSender::previewText($invoice),
            'button' => 'View invoice',
        ]);
    }

    private function previewProposal(Proposal $proposal): JsonResponse
    {
        $phone = $proposal->client?->phone;
        $windowOpen = filled($phone) && WhatsappServiceWindow::isOpen($phone);

        return response()->json([
            'to' => $proposal->recipientName(),
            'phone' => $phone,
            'phone_editable' => true,
            'channel' => $windowOpen ? 'message' : 'template',
            'message' => $windowOpen
                // Before a link exists, the send mints one; say so rather than end on a blank.
                ? rtrim($proposal->whatsappMessage()).($proposal->publicUrl() ? '' : ' [a new link to the proposal]')
                : 'Hi '.$proposal->recipientName().', your proposal "'.$proposal->title.'" is ready. Tap below to read it, comment on any section or download the PDF.',
            'button' => $windowOpen ? null : 'Open proposal',
        ]);
    }

    private function previewBrief(Client $client): JsonResponse
    {
        if (blank($client->phone)) {
            return response()->json(['message' => "{$client->name} has no phone number on file. Add one on the client's page first."], 422);
        }

        $brief = $client->brief;

        if ($brief?->isSubmitted()) {
            return response()->json(['message' => 'Their brief has already been submitted.'], 422);
        }

        $windowOpen = WhatsappServiceWindow::isOpen($client->phone);

        return response()->json([
            'to' => $client->name,
            'phone' => $client->phone,
            'phone_editable' => false,
            'channel' => $windowOpen ? 'message' : 'template',
            'message' => $windowOpen
                ? ($brief?->public_token
                    ? app(ClientBriefNudge::class)->messageText($client, $brief)
                    : 'Hi '.$client->name.' — before we start writing, could you fill in your brand brief? It takes about ten minutes: [a new link to their brief]')
                : 'Hi '.$client->name.', before we start writing could you fill in your brand brief? It takes about ten minutes. Tap below to open it.',
            'button' => $windowOpen ? null : 'Open brief',
        ]);
    }

    /**
     * @param  \Closure(array): string  $summary  the toast for a success
     */
    private function run(Tool $tool, array $arguments, Request $request, \Closure $summary): JsonResponse
    {
        $this->authoriseTool($tool, $request);

        try {
            $result = $tool->handle($arguments, $request->user());
        } catch (ToolException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $result = is_array($result) ? $result : ['text' => $result];

        return response()->json([
            'message' => $summary($result),
            'warnings' => $result['warnings'] ?? [],
            'result' => $result,
        ]);
    }

    /** Admins pass anyway; this keeps the tool's own permission the rule if the route ever widens. */
    private function authoriseTool(Tool $tool, Request $request): void
    {
        $permission = $tool->permission();
        abort_if($permission !== null && ! $request->user()->can($permission), 403);
    }
}
