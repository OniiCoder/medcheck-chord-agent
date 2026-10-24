<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ClinicSlot;
use App\Models\DiagnosticTest;
use App\Models\McpAuditLog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        if (DiagnosticTest::count() === 0) {
            app(\Database\Seeders\DatabaseSeeder::class)->run();
        }

        $appointments = Appointment::with(['diagnosticTest', 'clinicSlot'])
            ->latest()
            ->take(20)
            ->get();

        $auditLogs = McpAuditLog::latest()->take(25)->get();
        $tests = DiagnosticTest::orderBy('category')->get();
        $tomorrowDate = Carbon::tomorrow('Africa/Lagos')->format('Y-m-d');
        $slotsTomorrow = ClinicSlot::where('slot_date', $tomorrowDate)
            ->orderBy('modality')
            ->orderBy('start_time')
            ->get();

        return view('dashboard', [
            'appointments' => $appointments,
            'auditLogs' => $auditLogs,
            'tests' => $tests,
            'slotsTomorrow' => $slotsTomorrow,
            'tomorrowDate' => $tomorrowDate,
            'hasLivePaystackKey' => ! empty(config('services.paystack.secret_key')),
        ]);
    }

    public function checkout(string $reference): View
    {
        $appointment = Appointment::with(['diagnosticTest', 'clinicSlot'])
            ->where('paystack_reference', $reference)
            ->orWhere('booking_reference', $reference)
            ->firstOrFail();

        return view('paystack-checkout', [
            'appointment' => $appointment,
        ]);
    }

    public function simulatePayment(Request $request, string $reference): RedirectResponse
    {
        $appointment = Appointment::where('paystack_reference', $reference)
            ->orWhere('booking_reference', $reference)
            ->firstOrFail();

        $outcome = $request->input('outcome', 'success');

        if ($outcome === 'success') {
            $appointment->paystack_payment_status = 'success';
            $appointment->notes = 'Paid via Paystack Test Card (408408******4081)';
            $appointment->save();

            return back()->with('status', "Payment for {$appointment->booking_reference} marked as SUCCESS in Paystack Test Mode. Now tell the agent on WhatsApp 'I have paid' so it calls verify_payment_and_issue_pass!");
        }

        if ($outcome === 'failed') {
            $appointment->paystack_payment_status = 'failed';
            $appointment->status = 'PAYMENT_FAILED';
            $appointment->notes = 'Card declined by issuer (Insufficient Funds in Test Mode)';
            $appointment->save();

            return back()->with('status', "Payment for {$appointment->booking_reference} marked as FAILED (Insufficient Funds). Ask the agent on WhatsApp to verify payment to see how it handles a failed payment!");
        }

        $appointment->paystack_payment_status = 'abandoned';
        $appointment->status = 'PENDING_PAYMENT';
        $appointment->lab_pass_code = null;
        $appointment->save();

        return back()->with('status', "Payment for {$appointment->booking_reference} reset to UNPAID/ABANDONED.");
    }

    public function paystackCallback(Request $request): RedirectResponse
    {
        $ref = $request->query('reference') ?? $request->query('trxref');
        if ($ref) {
            return redirect()->route('paystack.checkout', ['reference' => $ref])
                ->with('status', 'Returned from Paystack live test checkout.');
        }

        return redirect()->route('dashboard');
    }
}
