# MedCheck Diagnostics Lekki — Chord WhatsApp Agent & MCP Server

A complete **Chord Teammate Skill Package** and **Laravel Streamable HTTP MCP Server** integrated with **Paystack Test Mode** for **MedCheck Diagnostics Lekki** (Lagos, Nigeria).

Built for the **TSG Labs Build Exercise** (**Role: Forward-Deployed Engineering**).

---

## Key Submission Documents

- **[`DEPLOYMENT_FIELD_NOTES.md`](DEPLOYMENT_FIELD_NOTES.md)** — Detailed engineering field notes on MCP `streamable_http` probes, teammate scoping defaults, Skill CAS versioning, and `tsglabs.com` SSR findings.
- **[`GO_LIVE_NOTE.md`](GO_LIVE_NOTE.md)** — Half-page non-technical go-live handover note for the clinic owner.
- **[`chord-skill/`](chord-skill/)** & **[`chord-skill.zip`](chord-skill.zip)** — The Chord Skill package (`SKILL.md` + `references/test_catalog_and_prep.md` + `references/clinic_policies_and_hmo.md`).

---

## Architecture Overview

1. **Chord Skill (`chord-skill/SKILL.md` + `references/*.md`)**:
   - Configures **Ada**, the Patient Booking & Clinical Preparation Coordinator at MedCheck Diagnostics (14 Admiralty Way, Lekki Phase 1).
   - Enforces clinical preparation confirmation (10–12hr overnight fast for Lipid Profile, 1L water/full bladder for Pelvic Ultrasound, MRI metal safety screening), morning-only phlebotomy cutoffs (`< 11:00 AM`), and emergency red-flag triage.
2. **Custom MCP Server (`POST /mcp` in `app/Http/Controllers/McpServerController.php`)**:
   - Implements the **MCP Streamable HTTP** (`2024-11-05` / `2025-03-26`) JSON-RPC 2.0 specification.
   - Exposes 4 tools to Chord:
     - `check_clinic_slots` — Queries MySQL slot inventory and enforces morning-only fasting cutoffs.
     - `book_and_initialize_payment` — Verifies prep acknowledgement, locks the slot for 30 minutes, and initializes a **Paystack Test Mode** transaction in **kobo** (`POST https://api.paystack.co/transaction/initialize`).
     - `verify_payment_and_issue_pass` — Calls Paystack's Verify API (`GET https://api.paystack.co/transaction/verify/{ref}`), validates `status === "success"` and kobo amount, and issues a `MCK-PASS-XXXX` code (or returns structured failure details and a retry link when a payment is `abandoned` or `failed`).
     - `lookup_or_cancel_booking` — Inspects booking status or cancels an unpaid hold and restores slot capacity.
3. **Live Operations Console (`GET /`)**:
   - Real-time web dashboard showing patient bookings, Paystack payment status, tomorrow's slot inventory, one-click payment outcome simulators, and a live feed of incoming Chord MCP JSON-RPC calls.

---

## Quickstart: Run Locally & Connect to Chord

### 1. Install & Seed the Database
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
```

### 2. Run the Automated PHPUnit Test Suite
```bash
php artisan test --filter=McpServerTest
```

### 3. Start the Laravel Server & Expose `/mcp` via Public HTTPS
Chord's `streamable_http` MCP client requires a public HTTPS URL (loopback/private IPs are blocked):
```bash
# Terminal 1: Start Laravel on port 8000
php artisan serve --port=8000

# Terminal 2: Expose port 8000 via ngrok (or Laravel Herd: `herd share`)
ngrok http 8000
```

### 4. Configure `.env` & Deploy to Chord
Add your keys to `.env`:
```env
PAYSTACK_SECRET_KEY=sk_test_your_paystack_test_key
MCP_BEARER_TOKEN=medcheck-mcp-secret-token-2026 (optional)

CHORD_BASE_URL=https://api.chordhq.xyz
CHORD_API_KEY=your_chord_api_key
```

You can upload `chord-skill.zip` directly in the Chord Web UI under **Teammates → Skills** and register `https://<your-ngrok-domain>.ngrok-free.app/mcp` under **Admin → Channels & Tools → Tools → Add server (undeer Custom tools (MCP))**.)*

---

## How AI Tools Were Used
We used **Google Antigravity (Gemini)** as a pair-programming assistant to:
1. Review the Chord REST API documentation (`api.chordhq.xyz/docs`) and map out the exact contract for file-based Skills (`/v1/teammates/{id}/skills`) and Streamable HTTP MCP servers (`/v1/workspaces/{id}/mcp-servers`).
2. Scaffold the Laravel 13 MCP JSON-RPC controller, Paystack kobo initialization/verification service, and MySQL schema/seeders for MedCheck Diagnostics Lekki.
3. Write automated PHPUnit feature tests (`tests/Feature/McpServerTest.php`) covering happy paths and edge cases (full slots, morning-only fasting cutoffs, unacknowledged prep rules, and declined/abandoned Paystack payments).
