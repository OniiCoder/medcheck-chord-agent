<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedCheck Diagnostics Lekki — Chord MCP & Paystack Live Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen font-sans antialiased">
    <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-6 py-4 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="inline-flex h-3 w-3 rounded-full bg-emerald-400 animate-pulse"></span>
                <div>
                    <h1 class="text-lg font-bold tracking-tight text-white">MedCheck Diagnostics Lekki — Live Operations Console</h1>
                    <p class="text-xs text-slate-400">14 Admiralty Way, Lekki Phase 1, Lagos &middot; Chord Streamable HTTP MCP Server (<code class="text-emerald-400">POST /mcp</code>)</p>
                </div>
            </div>
            <div class="flex items-center gap-3 text-xs">
                <span class="px-2.5 py-1 rounded-full border {{ $hasLivePaystackKey ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300' : 'border-amber-500/40 bg-amber-500/10 text-amber-300' }}">
                    Paystack Mode: {{ $hasLivePaystackKey ? 'Live API (sk_test_...)' : 'Interactive Test Sandbox' }}
                </span>
                <a href="{{ route('dashboard') }}" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium transition">
                    ↻ Refresh Live State
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8 space-y-8">
        @if (session('status'))
            <div class="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                {{ session('status') }}
            </div>
        @endif

        <!-- Supervisor Escalation & Human Handoff Queue -->
        <section class="bg-slate-900 border border-rose-500/30 rounded-xl overflow-hidden shadow-lg">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between bg-rose-950/20">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-rose-400"></span>
                    <div>
                        <h2 class="text-base font-semibold text-white">Supervisor Escalation &amp; Human Handoff Queue</h2>
                        <p class="text-xs text-slate-400">Logged automatically when Ada escalates emergencies, HMO pre-authorizations, contrast MRI reviews, or paid booking refunds (<code class="text-rose-300">ESC-...</code>)</p>
                    </div>
                </div>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-mono bg-rose-500/20 text-rose-300 border border-rose-500/30">
                    {{ $escalations->count() }} escalated
                </span>
            </div>
            @if ($escalations->isNotEmpty())
                <div class="divide-y divide-slate-800/70">
                    @foreach ($escalations as $esc)
                        @php $data = $esc->response_payload ?? []; @endphp
                        <div class="px-6 py-3.5 flex flex-wrap items-center justify-between gap-4 text-xs">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/40 font-mono font-semibold">
                                        {{ $data['escalation_ticket_id'] ?? 'ESC-TICKET' }}
                                    </span>
                                    <span class="px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 font-mono uppercase">
                                        {{ $data['category'] ?? 'general' }} ({{ $data['priority'] ?? 'HIGH' }})
                                    </span>
                                    <span class="text-slate-300 font-medium">
                                        Routed to: <strong class="text-white">{{ $data['routed_to_desk'] ?? 'Clinic Supervisor' }}</strong>
                                    </span>
                                </div>
                                <p class="text-slate-300">
                                    <span class="text-slate-400">Patient / Ref:</span> <code class="text-slate-200">{{ $data['query_or_reference'] ?? 'N/A' }}</code>
                                    &mdash; {{ $data['reason'] ?? '' }}
                                </p>
                            </div>
                            <div class="text-right font-mono text-slate-400">
                                <div>SLA: {{ $data['target_response_sla'] ?? '15 mins' }}</div>
                                <div>{{ $esc->created_at->format('Y-m-d H:i:s') }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="px-6 py-4 text-xs text-slate-400">
                    No active supervisor escalations yet. Ask Ada on WhatsApp to cancel paid booking <code class="text-slate-200">MCK-20261019-1001</code>, book with an HMO, or report an emergency symptom to trigger a live <code class="text-rose-300">ESC-...</code> handoff ticket.
                </div>
            @endif
        </section>

        <!-- Live Appointments & Paystack Status -->
        <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold text-white">Patient Bookings &amp; Paystack Pre-Payments</h2>
                    <p class="text-xs text-slate-400">Real-time state modified by the Chord WhatsApp Teammate via <code class="text-emerald-400">book_and_initialize_payment</code> &amp; <code class="text-emerald-400">verify_payment_and_issue_pass</code></p>
                </div>
                <span class="text-xs text-slate-400">{{ $appointments->count() }} records</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-950/60 text-xs uppercase text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="px-4 py-3">Booking Ref</th>
                            <th class="px-4 py-3">Patient</th>
                            <th class="px-4 py-3">Diagnostic Test &amp; Slot</th>
                            <th class="px-4 py-3">Amount</th>
                            <th class="px-4 py-3">Paystack Status</th>
                            <th class="px-4 py-3">Booking Status</th>
                            <th class="px-4 py-3">Lab Pass Code</th>
                            <th class="px-4 py-3 text-right">Demo Payment Controls</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/70">
                        @forelse ($appointments as $apt)
                            <tr class="hover:bg-slate-800/40">
                                <td class="px-4 py-3 font-mono text-xs text-emerald-300 font-semibold">
                                    {{ $apt->booking_reference }}
                                    <div class="text-[11px] text-slate-500">{{ $apt->paystack_reference }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-white">{{ $apt->patient_name }}</div>
                                    <div class="text-xs text-slate-400">{{ $apt->patient_phone }} &middot; {{ $apt->patient_email }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-200">{{ $apt->diagnosticTest->code }} — {{ $apt->diagnosticTest->name }}</div>
                                    <div class="text-xs text-slate-400 font-mono">
                                        {{ $apt->clinicSlot->slot_code }} ({{ $apt->clinicSlot->slot_date }} {{ $apt->clinicSlot->start_time }}-{{ $apt->clinicSlot->end_time }})
                                    </div>
                                </td>
                                <td class="px-4 py-3 font-mono text-slate-200">
                                    ₦{{ number_format($apt->amount_ngn) }}
                                    <div class="text-[11px] text-slate-500">{{ number_format($apt->amount_kobo) }} kobo</div>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($apt->paystack_payment_status === 'success')
                                        <span class="px-2 py-0.5 text-xs rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-mono">success</span>
                                    @elseif ($apt->paystack_payment_status === 'failed')
                                        <span class="px-2 py-0.5 text-xs rounded bg-rose-500/20 text-rose-300 border border-rose-500/30 font-mono">failed</span>
                                    @else
                                        <span class="px-2 py-0.5 text-xs rounded bg-amber-500/20 text-amber-300 border border-amber-500/30 font-mono">{{ $apt->paystack_payment_status }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($apt->status === 'CONFIRMED')
                                        <span class="px-2 py-0.5 text-xs rounded bg-emerald-500/20 text-emerald-300 font-semibold">CONFIRMED</span>
                                    @elseif ($apt->status === 'PAYMENT_FAILED')
                                        <span class="px-2 py-0.5 text-xs rounded bg-rose-500/20 text-rose-300 font-semibold">PAYMENT_FAILED</span>
                                    @elseif ($apt->status === 'CANCELLED')
                                        <span class="px-2 py-0.5 text-xs rounded bg-slate-700 text-slate-300">CANCELLED</span>
                                    @else
                                        <span class="px-2 py-0.5 text-xs rounded bg-amber-500/20 text-amber-300 font-semibold">PENDING_PAYMENT</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    @if ($apt->lab_pass_code)
                                        <span class="px-2.5 py-1 rounded bg-emerald-500 text-slate-950 font-bold">{{ $apt->lab_pass_code }}</span>
                                    @else
                                        <span class="text-slate-500">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <form method="POST" action="{{ route('paystack.simulate', $apt->booking_reference) }}">
                                            @csrf
                                            <input type="hidden" name="outcome" value="success">
                                            <button type="submit" class="px-2.5 py-1 rounded bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-medium">
                                                Mark Paid
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('paystack.simulate', $apt->booking_reference) }}">
                                            @csrf
                                            <input type="hidden" name="outcome" value="failed">
                                            <button type="submit" class="px-2.5 py-1 rounded bg-rose-600 hover:bg-rose-500 text-white text-xs font-medium">
                                                Simulate Decline
                                            </button>
                                        </form>
                                        <a href="{{ route('paystack.checkout', $apt->booking_reference) }}" target="_blank" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs">
                                            Checkout UI ↗
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-slate-500">No appointments yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Two Column: MCP Audit Log + Tomorrow's Slot Inventory -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- MCP Audit Log -->
            <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
                <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-white">Live Chord MCP JSON-RPC Stream</h2>
                        <p class="text-xs text-slate-400">Every <code class="text-emerald-400">initialize</code>, <code class="text-emerald-400">tools/list</code>, and <code class="text-emerald-400">tools/call</code> from Chord</p>
                    </div>
                    <span class="text-xs text-slate-400">Last {{ $auditLogs->count() }} calls</span>
                </div>
                <div class="divide-y divide-slate-800/70 max-h-[520px] overflow-y-auto">
                    @forelse ($auditLogs as $log)
                        <div class="p-4 space-y-2 text-xs">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-0.5 rounded bg-slate-800 font-mono text-emerald-300 font-semibold">{{ $log->rpc_method }}</span>
                                    @if ($log->tool_name)
                                        <span class="px-2 py-0.5 rounded bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 font-mono">{{ $log->tool_name }}</span>
                                    @endif
                                </div>
                                <span class="text-slate-400 font-mono">HTTP {{ $log->http_status }} &middot; {{ $log->duration_ms }}ms &middot; {{ $log->created_at->format('H:i:s') }}</span>
                            </div>
                            <details class="bg-slate-950/80 rounded p-2 border border-slate-800/80">
                                <summary class="cursor-pointer text-slate-400 hover:text-slate-200 font-mono">Inspect JSON-RPC Request &amp; Response</summary>
                                <div class="mt-2 space-y-2">
                                    <div>
                                        <div class="text-[10px] uppercase text-slate-500 mb-1">Request</div>
                                        <pre class="text-[11px] text-slate-300 overflow-x-auto">{{ json_encode($log->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </div>
                                    <div>
                                        <div class="text-[10px] uppercase text-slate-500 mb-1">Response</div>
                                        <pre class="text-[11px] text-emerald-300 overflow-x-auto">{{ json_encode($log->response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </div>
                                </div>
                            </details>
                        </div>
                    @empty
                        <div class="p-8 text-center text-slate-500 text-sm">
                            No MCP calls logged yet. Run a tool probe or talk to the agent in the WhatsApp simulator!
                        </div>
                    @endforelse
                </div>
            </section>

            <!-- Tomorrow's Clinic Slot Inventory -->
            <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
                <div class="px-6 py-4 border-b border-slate-800">
                    <h2 class="text-base font-semibold text-white">Clinic Slot Inventory (Tomorrow: {{ $tomorrowDate }})</h2>
                    <p class="text-xs text-slate-400">Notice how <code class="text-amber-300">SLOT-{{ str_replace('-', '', $tomorrowDate) }}-0830-ULTR</code> is pre-seeded as FULL to test conflict handling</p>
                </div>
                <div class="overflow-y-auto max-h-[520px]">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950/60 uppercase text-slate-400 border-b border-slate-800">
                            <tr>
                                <th class="px-4 py-2.5">Slot Code</th>
                                <th class="px-4 py-2.5">Modality</th>
                                <th class="px-4 py-2.5">Time</th>
                                <th class="px-4 py-2.5">Booked / Cap</th>
                                <th class="px-4 py-2.5">State</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            @foreach ($slotsTomorrow as $slot)
                                <tr>
                                    <td class="px-4 py-2.5 font-mono text-slate-200">{{ $slot->slot_code }}</td>
                                    <td class="px-4 py-2.5 text-slate-300">{{ $slot->modality }}</td>
                                    <td class="px-4 py-2.5 font-mono text-slate-300">{{ $slot->start_time }} - {{ $slot->end_time }}</td>
                                    <td class="px-4 py-2.5 font-mono">{{ $slot->booked_count }} / {{ $slot->capacity }}</td>
                                    <td class="px-4 py-2.5">
                                        @if ($slot->availableSpots() > 0)
                                            <span class="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 font-mono">AVAILABLE ({{ $slot->availableSpots() }})</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded bg-rose-500/20 text-rose-300 font-mono">FULL</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
