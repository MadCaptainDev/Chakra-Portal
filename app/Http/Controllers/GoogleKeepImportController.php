<?php

namespace App\Http\Controllers;

use App\Services\GoogleKeepImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * One-shot bulk import: a Google Takeout export of Keep notes, matched to
 * this app's Notion-synced content items by title, becomes a Script per
 * match. See GoogleKeepImport's own doc block for why this reads a Takeout
 * export rather than talking to Keep directly -- there is no API a personal
 * account can grant this app for that.
 *
 * Same `scripts,create` ability as Scripts' own create/store -- this is
 * bulk script creation, not a separate capability to grant.
 */
class GoogleKeepImportController extends Controller
{
    public function create(): View
    {
        return view('scripts.import-keep');
    }

    public function store(Request $request, GoogleKeepImport $importer): RedirectResponse
    {
        $validated = $request->validate([
            // Takeout's own download is a .zip; 100MB comfortably covers a
            // studio's worth of years of Keep notes, which are plain text.
            'keep_export' => ['required', 'file', 'mimes:zip', 'max:102400'],
        ], [
            'keep_export.mimes' => 'That doesn\'t look like a zip file -- upload the .zip Google Takeout gives you for Keep.',
        ]);

        try {
            $result = $importer->importFromZip($validated['keep_export']->getRealPath(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['keep_export' => $e->getMessage()]);
        }

        $importedCount = count($result['imported']);
        $status = $importedCount > 0
            ? $importedCount.' script'.($importedCount === 1 ? '' : 's').' imported.'
            : 'Nothing new to import.';

        return redirect()->route('scripts.import-keep.create')
            ->with('status', $status)
            ->with('importResult', $result);
    }
}
