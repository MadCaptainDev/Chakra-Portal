<?php

namespace App\Tools\Proposals;

use App\Models\Proposal;
use App\Models\User;
use App\Support\ProposalBlocks;
use App\Tools\Tool;

/**
 * The manual a model reads before it writes a proposal: how the document is
 * put together, every block's exact shape with an example, and the house
 * style. Kept out of the other tools' descriptions so they stay short -- a
 * model calls this once, when it needs it.
 *
 * EXAMPLES is the reference, and tests/Feature/ProposalMcpTest checks that
 * every one of them goes through ProposalToolSupport::prepare() with no
 * problems, so this page cannot drift from what the code accepts.
 */
class ProposalGuide extends Tool
{
    public const EXAMPLES = [
        'paragraph' => ['type' => 'paragraph', 'text' => "First paragraph with **bold**, [ok]good news[/ok] and [warn]a caution[/warn].\n\nA blank line starts a second paragraph.", 'tone' => 'default'],
        'subheading' => ['type' => 'subheading', 'text' => 'Phase 1 — Launch'],
        'list' => ['type' => 'list', 'items' => ['Product catalogue with **dynamic pricing**', 'Artwork upload'], 'ordered' => false, 'boxed' => false],
        'table' => ['type' => 'table', 'header' => ['Module', 'Scope'], 'rows' => [['Catalogue', 'Categories, products, variants'], ['Checkout', 'Cart, GST, Razorpay']], 'first_col_bold' => true, 'last_col_right' => false, 'last_row_bold' => false, 'first_col_width' => 30],
        'callout' => ['type' => 'callout', 'text' => 'Each phase delivers usable value on its own.', 'tone' => 'brand'],
        'cards' => ['type' => 'cards', 'variant' => 'labelled', 'columns' => 2, 'items' => [['label' => 'Goal', 'text' => 'A mobile-first store'], ['label' => 'Scale', 'text' => '10,000+ products']]],
        'flow' => ['type' => 'flow', 'steps' => ['Order', 'Design', 'Approval', 'Print', 'Dispatch'], 'tone' => 'light', 'numbered' => true, 'columns' => 0],
        'chips' => ['type' => 'chips', 'items' => ['Visiting cards', 'Banners', 'Packaging']],
        'note' => ['type' => 'note', 'tag' => 'recommendation', 'text' => 'We suggest launching Android first.'],
        'legend' => ['type' => 'legend', 'title' => 'How to read this proposal', 'items' => [
            ['tag' => 'requirement', 'label' => 'Client Requirement', 'note' => 'requested by the client'],
            ['tag' => 'recommendation', 'label' => 'Proposed Recommendation', 'note' => 'our implementation view'],
            ['tag' => 'optional', 'label' => 'Optional / Future', 'note' => 'not in current scope'],
        ]],
        'total' => ['type' => 'total', 'label' => 'Total Development Investment', 'value' => '₹4,50,000 + GST'],
        'stack' => ['type' => 'stack', 'items' => [['category' => 'Backend', 'name' => 'Laravel', 'logo' => 'laravel'], ['category' => 'Payments', 'name' => 'Razorpay', 'logo' => 'razorpay']]],
        'architecture' => ['type' => 'architecture', 'layers' => [
            ['label' => 'Customers', 'style' => 'light', 'columns' => 3, 'items' => [['text' => 'Website', 'dashed' => false], ['text' => 'Android app', 'dashed' => false], ['text' => 'iOS app', 'dashed' => true]]],
            ['label' => 'Platform', 'style' => 'dark', 'columns' => 2, 'items' => [['text' => 'Laravel API', 'dashed' => false], ['text' => 'MySQL', 'dashed' => false]]],
        ]],
        'swimlane' => ['type' => 'swimlane', 'lanes' => ['Customer', 'Platform', 'Team'], 'legend' => true, 'rows' => [
            [['text' => 'Places order', 'loop' => false], ['text' => 'Creates job card', 'loop' => false], ['text' => '', 'loop' => false]],
            [['text' => '', 'loop' => false], ['text' => '', 'loop' => false], ['text' => 'Prepares proof', 'loop' => false]],
            [['text' => 'Requests changes', 'loop' => true], ['text' => '', 'loop' => false], ['text' => '', 'loop' => false]],
        ]],
    ];

    private const NOTES = [
        'paragraph' => 'tone: default | muted (grey) | fine (small print). Up to 5000 characters.',
        'subheading' => 'A smaller heading inside a section.',
        'list' => 'ordered: numbered list. boxed: the list sits in an outlined box.',
        'table' => 'header is optional ([] for none). Every row is a list of cells. first_col_bold, last_col_right (prices), last_row_bold (a subtotal row), first_col_width 0-60 (% of the table; 0 = automatic). Cells take the inline marks.',
        'callout' => 'tone: brand (blue tint) | gray | outline. A single highlighted statement.',
        'cards' => 'variant: labelled (small label over text) | plain (plain boxes) | stat (big figure in label, text under). columns 1-4.',
        'flow' => 'Steps shown left to right. tone: light | dark | line (one line joined by arrows). numbered adds 01, 02… columns: steps per row (0 = all in one row).',
        'chips' => 'Rounded pills -- a list of short names.',
        'note' => 'A statement marked with whose it is. tag: requirement | recommendation | optional.',
        'legend' => 'The "How to read this proposal" key explaining the three tags. Put it alone in a headless section (no number, no title) right after the cover.',
        'total' => 'The dark bar with the grand total.',
        'stack' => 'Technology cards. logo: '.'%LOGOS%'.' or "" for a placeholder.',
        'architecture' => 'Layers of boxes, top to bottom. style: light | dark | outline. columns 1-6. dashed marks a future / optional box.',
        'swimlane' => 'Who does what, step by step. Exactly three lanes; every row has exactly three cells, one per lane. An empty text leaves that lane as a connector line. loop marks a review / correction step (amber, dashed). legend shows the colour key.',
    ];

    public function name(): string
    {
        return 'proposal_guide';
    }

    public function description(): string
    {
        return 'How a proposal document is built: sections, pages, the cover, every block type with its '
            .'exact JSON shape and an example, inline formatting and the house style. Call this before '
            .'writing or rewriting proposal sections.';
    }

    public function permission(): ?string
    {
        return 'proposals.view';
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([]);
    }

    public function handle(array $arguments, User $user): array
    {
        $template = Proposal::where('title', \Database\Seeders\ProposalSeeder::TITLE)->value('id');

        return [
            'document' => [
                'A proposal is an ordered list of sections. The client reads it as A4 pages; the PDF is the same.',
                'Section types: "cover" (the dark title page, at most one, always first) and "section" (a numbered heading like "01 Executive Summary" followed by blocks).',
                'Pages: a section with new_page: true starts a new A4 page; otherwise it follows the previous section on the same page. An A4 page holds roughly 350-450 words, or a table of about 15 rows. Start each major part (summary, scope, phases, commercials, terms) on a new page.',
                'A section with an empty number and title has no heading -- use that for the "How to read" legend after the cover.',
                'Every section has a key (lowercase letters, digits and hyphens, e.g. "executive-summary"). Client comments are attached to the key, so when you rewrite a section keep its key. New sections may give a readable key, or leave it out and one is made.',
                'Section shape: {"key": "scope", "type": "section", "data": {"number": "02", "title": "Scope of Work", "new_page": true, "blocks": [ ...blocks ]}}. The flat form {"key", "type", "number", "title", "new_page", "blocks"} is accepted too.',
                'Cover shape: {"key": "cover", "type": "cover", "data": {"eyebrow": "Project Proposal", "brand": "app_studio | production", "prepared_for_label": "Prepared for", "title": "<client name, shown very large>", "subtitle": "<what it is, one line>", "prepared_by": "Chakra App Studio", "client_name": "<client name, footer and cover>", "date_label": "September 2026"}}. brand picks the studio logo: app_studio for software work, production for video / photo / marketing. The client logo cannot be set from here -- it is uploaded in the editor, or taken from the linked client. Leaving client_logo out keeps the current one.',
            ],
            'inline_formatting' => 'In paragraph, list, table-cell, callout, note, card-text and swimlane text: **bold**, [ok]green bold[/ok] for good news, [warn]amber bold[/warn] for a caution. Nothing else (no markdown headings, links or italics) -- use blocks for structure. Other fields are plain text.',
            'blocks' => collect(self::EXAMPLES)->map(fn ($example, $type) => [
                'type' => $type,
                'name' => ProposalBlocks::BLOCK_TYPES[$type],
                'notes' => str_replace('%LOGOS%', implode(', ', ProposalBlocks::TECH_LOGOS), self::NOTES[$type]),
                'example' => $example,
            ])->values()->all(),
            'tags' => ProposalBlocks::TAGS,
            'house_style' => [
                'Write in plain, confident British-Indian business English. Short paragraphs. Rupee amounts as ₹4,50,000 (Indian grouping), and say whether GST is extra.',
                'A typical proposal: cover; How to read legend; 01 Executive Summary; 02 About the Project; 03 Understanding of the client; 04 Scope of Work (tables per module); 05 Phases / Timeline (flow + tables); 06 Technology (stack, architecture); 07 Process (swimlane); 08 Commercials (cost tables + total); 09 Terms; 10 Next Steps.',
                'Mark what the client asked for as requirement, your suggestions as recommendation and extras as optional, with note blocks, so the legend means something.',
                'Never invent prices, dates or client facts. Ask the person, or leave a clear placeholder like ₹X,XX,XXX.',
            ],
            'workflow' => [
                'New proposal: create_proposal with the title, client and the whole document in sections (or duplicate_of an existing one and then rewrite sections).',
                'Changing an existing one: get_proposal (outline first, then the sections you need), then write_proposal_sections with just the sections you changed. arrange_proposal_sections reorders or removes.',
                'Every write returns "problems": anything that was dropped, cut short or replaced by a default. Fix and write again until it is empty.',
                'Show the person view_url or edit_url to check it. Only share_proposal / send_proposal_whatsapp when they ask -- that is what the client sees.',
            ],
            'template_proposal_id' => $template,
        ];
    }
}
