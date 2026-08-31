<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * A dedicated full-bleed page: no sidebar, no max-width, canvas fills the
 * viewport. A sibling of AppLayout rather than a prop on it (following the
 * GuestLayout/PublicLayout precedent already in this directory) -- a
 * `fullBleed` flag on AppLayout would have to null out five things every
 * other page relies on (the 256px sidebar, the sticky header wrapper, the
 * max-w-7xl <main>, its padding, and the flash-message block that lives
 * inside it), which is a worse place for this screen's needs to live than
 * one small file of its own.
 *
 * So far this is only whatsapp-crm/flows/edit.blade.php, but nothing here
 * is specific to that page -- any screen that is inherently spatial (a
 * canvas, a builder) rather than a document can reach for this instead of
 * fighting AppLayout's width constraints.
 */
class EditorLayout extends Component
{
    public function __construct(public ?string $title = null) {}

    public function render(): View
    {
        return view('layouts.editor');
    }
}
