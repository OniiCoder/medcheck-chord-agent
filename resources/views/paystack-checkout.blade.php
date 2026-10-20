<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paystack Test Checkout — {{ $appointment->booking_reference }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-6">
    <div class="max-w-md w-full bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden">
        <div class="bg-emerald-600 px-6 py-4 flex items-center justify-between">
            <div>
                <span class="text-[11px] uppercase tracking-wider font-bold text-emerald-100">Paystack Test Mode Checkout</span>
                <h1 class="text-lg font-bold text-white">MedCheck Diagnostics Lekki</h1>
            </div>
            <span class="px-2.5 py-1 rounded bg-slate-950/30 text-xs font-mono text-white">TEST</span>
        </div>

        <div class="p-6 space-y-5">
            @if (session('status'))
                <div class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 text-xs text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            <div class="flex items-baseline justify-between border-b border-slate-800 pb-4">
                <div>
                    <div class="text-xs text-slate-400">Patient Email</div>
                    <div class="text-sm font-medium text-white">{{ $appointment->patient_email }}</div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-slate-400">Amount Due</div>
                    <div class="text-xl font-bold text-emerald-400">₦{{ number_format($appointment->amount_ngn) }}</div>
                </div>
            </div>

            <div class="space-y-2 text-xs bg-slate-950/60 p-4 rounded-xl border border-slate-800">
                <div class="flex justify-between">
                    <span class="text-slate-400">Booking Reference:</span>
                    <span class="font-mono text-slate-200 font-semibold">{{ $appointment->booking_reference }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Paystack Reference:</span>
                    <span class="font-mono text-slate-200">{{ $appointment->paystack_reference }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Test:</span>
                    <span class="text-slate-200 text-right">{{ $appointment->diagnosticTest->name }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Appointment Slot:</span>
                    <span class="font-mono text-slate-200">{{ $appointment->clinicSlot->slot_date }} ({{ $appointment->clinicSlot->start_time }} - {{ $appointment->clinicSlot->end_time }})</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Current Payment State:</span>
                    <span class="font-mono font-bold {{ $appointment->paystack_payment_status === 'success' ? 'text-emerald-400' : ($appointment->paystack_payment_status === 'failed' ? 'text-rose-400' : 'text-amber-400') }}">
                        {{ strtoupper($appointment->paystack_payment_status) }}
                    </span>
                </div>
            </div>

            <div class="space-y-3 pt-2">
                <form method="POST" action="{{ route('paystack.simulate', $appointment->booking_reference) }}">
                    @csrf
                    <input type="hidden" name="outcome" value="success">
                    <button type="submit" class="w-full py-3 px-4 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-sm transition shadow">
                        ✓ Authorize Test Payment (₦{{ number_format($appointment->amount_ngn) }} — Success)
                    </button>
                </form>

                <form method="POST" action="{{ route('paystack.simulate', $appointment->booking_reference) }}">
                    @csrf
                    <input type="hidden" name="outcome" value="failed">
                    <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-rose-500/20 hover:bg-rose-500/30 border border-rose-500/40 text-rose-200 font-semibold text-xs transition">
                        ✕ Simulate Declined Card (Insufficient Funds Error)
                    </button>
                </form>
            </div>

            <div class="text-center pt-2">
                <a href="{{ route('dashboard') }}" class="text-xs text-slate-400 hover:text-white underline">
                    &larr; Return to MedCheck Live Operations Console
                </a>
            </div>
        </div>
    </div>
</body>
</html>
