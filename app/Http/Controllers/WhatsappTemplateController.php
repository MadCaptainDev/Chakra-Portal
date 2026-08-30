<?php

namespace App\Http\Controllers;

use App\Models\WhatsappSetting;
use App\Services\WhatsappTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The Meta-approved message templates this account can send.
 *
 * Templates live in Meta, not in this database -- index/refresh only read
 * and cache what Meta already has. create/store submit a new one *to* Meta;
 * there is still nothing to edit or delete here, because Meta does not allow
 * either on a template that has ever been reviewed -- a changed template is
 * a new template, submitted fresh.
 */
class WhatsappTemplateController extends Controller
{
    public function index(): View
    {
        $canSend = WhatsappSetting::current()->canSend();

        return view('whatsapp-crm.templates.index', [
            'canSend' => $canSend,
            'templates' => $canSend ? WhatsappTemplateService::make()->list() : [],
        ]);
    }

    public function refresh(): RedirectResponse
    {
        WhatsappTemplateService::make()->refresh();

        return redirect()->route('whatsapp-crm.templates.index')
            ->with('status', 'Templates refreshed from Meta.');
    }

    public function create(): View
    {
        return view('whatsapp-crm.templates.create');
    }

    public function store(Request $request): RedirectResponse
    {
        // How many {{1}}, {{2}}, ... the typed body actually uses -- decides
        // whether body_example is required at all and, if so, exactly how
        // many values it must carry.
        $placeholderCount = preg_match_all('/\{\{\d+\}\}/', (string) $request->input('body'));

        $validated = $request->validate([
            // Meta's own rule for template names: lowercase letters, digits
            // and underscores only, and immutable once submitted.
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'category' => ['required', 'in:MARKETING,UTILITY,AUTHENTICATION'],
            'language' => ['required', 'string', 'max:10'],
            'header' => ['nullable', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:1024'],
            'footer' => ['nullable', 'string', 'max:60'],
            // Meta will not even review a body with variables unless a
            // sample value is on file for each one -- required here rather
            // than left to fail on Meta's side, where the reason ("missing
            // expected field(s) (example)") never makes it back to this form.
            'body_example' => [
                $placeholderCount > 0 ? 'required' : 'nullable',
                'string',
                'max:1024',
            ],
        ], [
            'name.regex' => "Use lowercase letters, digits and underscores only, e.g. \"order_confirmation\" (Meta's naming rule).",
            'body_example.required' => 'Give one example value per {{n}} in the body -- Meta will not review a template it cannot render a sample of.',
        ]);

        if ($placeholderCount > 0) {
            $examples = array_map('trim', explode(',', $validated['body_example']));

            if (count($examples) !== $placeholderCount) {
                return back()->withInput()->withErrors([
                    'body_example' => "The body uses {$placeholderCount} placeholder(s), but ".count($examples).' example value(s) were given -- they must match, one per comma.',
                ]);
            }

            $validated['body_example'] = $examples;
        } else {
            unset($validated['body_example']);
        }

        try {
            WhatsappTemplateService::make()->create($validated);
        } catch (RuntimeException $e) {
            // Meta's own rejection reason (duplicate name, disallowed
            // wording, etc.) is the useful part -- surfaced on the form
            // rather than a generic "something went wrong".
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('whatsapp-crm.templates.index')
            ->with('status', 'Template submitted to Meta for approval. Review usually takes a few minutes to a day -- use "Refresh from Meta" to check status.');
    }
}
