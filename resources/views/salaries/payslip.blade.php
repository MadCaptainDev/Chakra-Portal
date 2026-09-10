@php
    /**
     * Standalone PDF document -- rendered via Pdf::loadHTML(), never served
     * as a normal page, so it carries its own <html>/<head> rather than
     * extending x-app-layout. Fonts are embedded as data URIs (see
     * App\Support\Fonts) so dompdf renders the same faces as the browser
     * preview, with no network fetch at render time.
     */
    $poppinsRegular = \App\Support\Fonts::dataUri('Poppins-Regular.ttf');
    $poppinsSemiBold = \App\Support\Fonts::dataUri('Poppins-SemiBold.ttf');
    $poppinsBold = \App\Support\Fonts::dataUri('Poppins-Bold.ttf');

    $basic = (float) $employee->amount;
    // Not $basic when unpaid -- a payslip for a month nothing was recorded
    // for should say 0 paid, matching the "Pending" status right above it,
    // not silently claim the full salary went out.
    $paid = $payment ? (float) $payment->amount_paid : 0.0;
    $short = max($basic - $paid, 0);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip - {{ $employee->name }} - {{ $month->format('F Y') }}</title>
<style>
    @font-face { font-family: 'Poppins'; font-weight: 400; src: url({{ $poppinsRegular }}) format('truetype'); }
    @font-face { font-family: 'Poppins'; font-weight: 600; src: url({{ $poppinsSemiBold }}) format('truetype'); }
    @font-face { font-family: 'Poppins'; font-weight: 700; src: url({{ $poppinsBold }}) format('truetype'); }

    * { box-sizing: border-box; }
    body { font-family: 'Poppins', sans-serif; color: #1f2430; font-size: 12px; margin: 0; padding: 32px 40px; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1f2430; padding-bottom: 16px; margin-bottom: 20px; }
    .company-name { font-size: 18px; font-weight: 700; margin: 0; }
    .company-address { font-size: 11px; color: #555; margin-top: 4px; max-width: 260px; }
    .doc-title { text-align: right; }
    .doc-title h2 { margin: 0; font-size: 16px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
    .doc-title p { margin: 4px 0 0; color: #555; }
    .meta { display: flex; justify-content: space-between; margin-bottom: 20px; }
    .meta div { width: 48%; }
    .meta h4 { margin: 0 0 6px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; color: #888; }
    .meta table td { padding: 2px 0; font-size: 12px; }
    .meta table td:first-child { color: #666; width: 40%; }
    table.pay { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.pay th { text-align: left; background: #f3f4f6; padding: 8px 10px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: #555; border-bottom: 1px solid #ddd; }
    table.pay td { padding: 10px; border-bottom: 1px solid #eee; font-size: 13px; }
    table.pay tr.total td { font-weight: 700; font-size: 14px; border-top: 2px solid #1f2430; border-bottom: none; }
    .short-note { margin-top: 6px; font-size: 11px; color: #b45309; }
    .signature { margin-top: 60px; display: flex; justify-content: flex-end; }
    .signature .block { text-align: center; }
    .signature .name { font-weight: 700; border-top: 1px solid #999; padding-top: 6px; min-width: 180px; }
    .signature .title { font-size: 10px; color: #666; margin-top: 2px; }
    .footer { margin-top: 40px; text-align: center; font-size: 10px; color: #999; }
</style>
</head>
<body>
    <div class="header">
        <div>
            <p class="company-name">{{ $settings->company_name }}</p>
            @if ($settings->address)
                <p class="company-address">{{ $settings->address }}</p>
            @endif
        </div>
        <div class="doc-title">
            <h2>Payslip</h2>
            <p>{{ $month->format('F Y') }}</p>
        </div>
    </div>

    <div class="meta">
        <div>
            <h4>Employee</h4>
            <table>
                <tr><td>Name</td><td>{{ $employee->name }}</td></tr>
                <tr><td>Role</td><td>{{ $employee->role ?: '—' }}</td></tr>
                <tr><td>Employee ID</td><td>EMP-{{ str_pad((string) $employee->id, 4, '0', STR_PAD_LEFT) }}</td></tr>
                <tr><td>Joined</td><td>{{ $employee->joined_on?->format('d M Y') ?: '—' }}</td></tr>
            </table>
        </div>
        <div>
            <h4>Pay Period</h4>
            <table>
                <tr><td>Month</td><td>{{ $month->format('F Y') }}</td></tr>
                <tr><td>Paid On</td><td>{{ $payment?->paid_on?->format('d M Y') ?: 'Not yet recorded' }}</td></tr>
                <tr><td>Status</td><td>{{ $payment && $payment->isPaid() ? ($short > 0.01 ? 'Partially Paid' : 'Paid') : 'Pending' }}</td></tr>
            </table>
        </div>
    </div>

    <table class="pay">
        <thead>
            <tr><th>Description</th><th style="text-align:right;">Amount</th></tr>
        </thead>
        <tbody>
            <tr><td>Basic Monthly Salary</td><td style="text-align:right;">{{ number_format($basic, 2) }}</td></tr>
            <tr class="total"><td>Net Paid — {{ $month->format('F Y') }}</td><td style="text-align:right;">{{ number_format($paid, 2) }}</td></tr>
        </tbody>
    </table>

    @if ($short > 0.01)
        <p class="short-note">Short by {{ number_format($short, 2) }} against the standard salary for this month.</p>
    @endif

    <div class="signature">
        <div class="block">
            <p class="name">{{ $settings->signature_name }}</p>
            <p class="title">{{ $settings->signature_title }}</p>
        </div>
    </div>

    <div class="footer">
        <p>{{ $settings->footer_text }}</p>
        <p>This is a system-generated payslip and does not require a physical signature.</p>
    </div>
</body>
</html>
