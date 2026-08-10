<?php

namespace App\View\Components;

use App\Models\PortfolioItem;
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
        // Nothing published means no Work tab: an empty portfolio is worse
        // than no portfolio, so the studio can simply not have one yet.
        return view('layouts.public', [
            'hasPortfolio' => PortfolioItem::published()->exists(),

            // The header's call to action follows the visitor to the enquiry
            // form, so it has to name the page they were actually reading --
            // otherwise every lead off a case study is filed under the landing
            // page and the whole measurement is quietly wrong.
            'enquirySource' => match (true) {
                request()->routeIs('portfolio.detail') => 'case-study',
                request()->routeIs('portfolio') => 'portfolio',
                default => 'landing',
            },
        ]);
    }
}
