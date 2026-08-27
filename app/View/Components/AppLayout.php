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
     * @param  bool  $dark  Put the content column on the brand-900 ground.
     *                      Now the default rather than an opt-in: the whole
     *                      signed-in product runs on the dark plane the
     *                      Dashboard established, and every shared component's
     *                      default tone was moved to match. Pass :dark="false"
     *                      only for a screen that genuinely has to be light --
     *                      nothing does today (the printed invoice is a
     *                      standalone dompdf document, not this layout).
     */
    public function __construct(
        public ?string $title = null,
        public bool $dark = true,
    ) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
