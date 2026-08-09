<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Chrome for the public site -- the landing page and the portfolio screen.
 *
 * The title and description are constructor properties rather than loose
 * attributes: a class-based component funnels anything it does not declare into
 * $attributes, where the <head> could never read it.
 */
class PublicLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
    ) {}

    public function render(): View
    {
        return view('layouts.public');
    }
}
