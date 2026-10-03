<?php

namespace App\Http\Controllers;

use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use Illuminate\Http\Response;

/**
 * /sitemap.xml: every public page a search engine should know about -- the
 * homepage, the portfolio, each category tab that has work in it, and each
 * published case study. Listed in public/robots.txt.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $published = PortfolioItem::published()->with('category')->ordered()->get();
        // Case studies only: a piece without one has no page the site links to.
        $items = $published->filter(fn (PortfolioItem $item) => $item->hasCaseStudy());
        $latest = $published->max('updated_at');

        $urls = [
            ['loc' => url('/'), 'lastmod' => $latest, 'priority' => '1.0'],
        ];

        if ($published->isNotEmpty()) {
            $urls[] = ['loc' => route('portfolio'), 'lastmod' => $latest, 'priority' => '0.9'];

            foreach (PortfolioCategory::visible()->ordered()->get() as $category) {
                $inCategory = $published->where('portfolio_category_id', $category->id);
                if ($inCategory->isNotEmpty()) {
                    $urls[] = ['loc' => route('portfolio', ['category' => $category->slug]), 'lastmod' => $inCategory->max('updated_at'), 'priority' => '0.8'];
                }
            }

            foreach ($items as $item) {
                $urls[] = ['loc' => route('portfolio.detail', $item), 'lastmod' => $item->updated_at, 'priority' => '0.7'];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url['loc']).'</loc>'
                .($url['lastmod'] ? '<lastmod>'.$url['lastmod']->toAtomString().'</lastmod>' : '')
                .'<priority>'.$url['priority'].'</priority></url>'."\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
