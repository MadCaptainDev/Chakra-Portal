<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * @param  string|null  $title  Page name for the browser tab and the mobile
     *                              top bar. Optional, so every existing
     *                              <x-app-layout> call site keeps working.
     * @param  bool  $dark  Put the content column on the brand-900 ground
     *                      instead of the default light grey. Opt-in per page:
     *                      the rest of the admin is built out of light cards
     *                      and would be unreadable on the dark ground, so this
     *                      stays false everywhere it is not asked for.
     */
    public function __construct(
        public ?string $title = null,
        public bool $dark = false,
    ) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
