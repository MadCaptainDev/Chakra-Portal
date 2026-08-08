<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnquiryRequest;
use App\Models\CompanySetting;
use App\Models\Enquiry;
use App\Notifications\EnquiryReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Throwable;

/**
 * store() is public - it takes enquiries from the landing page. Everything
 * else is the staff-side inbox and is behind auth in routes/web.php.
 */
class EnquiryController extends Controller
{
    public function store(EnquiryRequest $request): RedirectResponse
    {
        $fields = $request->safe()->only(['name', 'email', 'phone', 'project', 'message']);

        // Saved before the mail is attempted, so the lead survives a dead SMTP
        // host. Production mails to the log at debug level with LOG_LEVEL=error,
        // which discards it entirely - the row is the only durable record.
        $enquiry = Enquiry::create($fields + ['ip_address' => $request->ip()]);

        $recipient = CompanySetting::current()->notification_email
            ?: config('mail.from.address');

        try {
            Notification::route('mail', $recipient)->notify(new EnquiryReceived($fields));
        } catch (Throwable $e) {
            // No enquiry content in the log: it is already safely in the table.
            Log::error('Enquiry email failed', [
                'enquiry_id' => $enquiry->id,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);
        }

        // The enquiry is recorded either way, so the sender always gets a
        // straight answer - a mail failure is our problem, not theirs.
        return redirect()
            ->route('home')
            ->withFragment('contact')
            ->with('status', "Thanks {$enquiry->name} - we have your message and will come back to you shortly.");
    }

    public function index(Request $request): View
    {
        $filter = $request->string('filter')->toString();

        $enquiries = Enquiry::query()
            ->when($filter === 'unread', fn ($query) => $query->unread())
            ->when($filter === 'open', fn ($query) => $query->open())
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('enquiries.index', [
            'enquiries' => $enquiries,
            'filter' => $filter,
            'unreadCount' => Enquiry::unreadCount(),
            'openCount' => Enquiry::open()->count(),
        ]);
    }

    public function show(Enquiry $enquiry): View
    {
        $enquiry->markRead();

        return view('enquiries.show', compact('enquiry'));
    }

    public function toggleHandled(Enquiry $enquiry): RedirectResponse
    {
        $enquiry->forceFill([
            'handled_at' => $enquiry->isHandled() ? null : now(),
            'read_at' => $enquiry->read_at ?? now(),
        ])->save();

        return redirect()->route('enquiries.show', $enquiry)->with(
            'status',
            $enquiry->isHandled() ? 'Marked as handled.' : 'Reopened.'
        );
    }

    public function destroy(Enquiry $enquiry): RedirectResponse
    {
        $enquiry->delete();

        return redirect()->route('enquiries.index')->with('status', 'Enquiry deleted.');
    }
}
