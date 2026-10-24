# TSG Labs Build Exercise — Submission Package

## 1. Role Preference & Problem Statement

**Target Role:** Forward-Deployed Engineer

**Problem Statement:**
MedCheck Diagnostics Lekki is a high-volume outpatient imaging and pathology clinic in Lagos where over 70% of patients message on WhatsApp to ask test prices, check fasting or full-bladder rules, and book scarce morning slots. Receptionists lose hours repeating clinical preparation instructions and chasing unverified bank transfer screenshots, while 35% of unpaid "hold" bookings no-show and unprepared patients (who ate before a fasting lipid panel or arrived with an empty bladder for a pelvic scan) have to be turned away at the front desk. This problem is directly worth paying for because turning away one unscreened MRI or ultrasound patient or losing a prime morning slot costs the clinic ₦25,000–₦165,000 in idle modality time and lost daily revenue. To keep the agent reliable and clinically safe, we deliberately left out HMO/insurance pre-authorization workflows, IV-contrast radiology bookings (which require a doctor's referral and kidney function review), home sample collection, and automated refunds on already-paid appointments.

---

## 2. Repository Contents

| Deliverable | Path | Description |
| :--- | :--- | :--- |
| **Chord Skill Package** | [`chord-skill/SKILL.md`](chord-skill/SKILL.md) & [`chord-skill/references/`](chord-skill/references/) (plus [`chord-skill.zip`](chord-skill.zip)) | Instructions & clinical reference files (`test_catalog_and_prep.md`, `clinic_policies_and_hmo.md`) |
| **Custom MCP Server (Laravel)** | [`app/Http/Controllers/McpServerController.php`](app/Http/Controllers/McpServerController.php) | Streamable HTTP (`POST /mcp`) JSON-RPC 2.0 server exposing 4 clinical & Paystack tools |
| **Paystack Test Mode Service** | [`app/Services/PaystackService.php`](app/Services/PaystackService.php) | Calls Paystack `POST /transaction/initialize` & `GET /transaction/verify/{ref}` in kobo |
| **Chord API Provisioning CLI** | [`app/Console/Commands/ChordDeployCommand.php`](app/Console/Commands/ChordDeployCommand.php) | `php artisan chord:deploy` — uploads/publishes Skill, registers MCP server, scopes teammate, runs `/health` & `/tools` probes |
| **Deployment Field Notes** | [`DEPLOYMENT_FIELD_NOTES.md`](DEPLOYMENT_FIELD_NOTES.md) | Detailed root-cause analysis & actionable Chord product/API/docs feedback |
| **Owner Go-Live Handover Note** | [`GO_LIVE_NOTE.md`](GO_LIVE_NOTE.md) | Half-page non-technical handover note for the MedCheck Diagnostics clinic owner |
| **Setup & Run Guide** | [`README.md`](README.md) | Local setup, HTTPS tunnel, Chord deployment, PHPUnit tests, and AI tool disclosure |

---

## 3. 3-Minute Screen Recording Script (WhatsApp Simulator + Live Console)

Put the **Chord WhatsApp Simulator (`https://chat.tsglabs.dev/`)** on the left half of your screen and the **MedCheck Live Operations Console (`http://127.0.0.1:8000`)** on the right half:

1. **0:00 – 0:35 | Clinical Prep & Morning Cutoff Enforcement (`check_clinic_slots`)**:
   - In WhatsApp Simulator, send: *"Hi, I want to book a Fasting Lipid Profile for tomorrow afternoon around 2pm."*
   - Show Ada reading `references/test_catalog_and_prep.md` and calling `check_clinic_slots` (`test_code: "LIPID-FAST"`, `preferred_date: "2026-10-20"`).
   - Point out how the tool blocks afternoon slots (`13:30` / `15:00`) as `RESTRICTED_MORNING_ONLY_TEST`, quotes **₦28,500**, explains the mandatory **10–12 hour overnight fast**, and offers morning slots (`07:30`, `08:30`, `09:30`, `10:30`).

2. **0:35 – 1:25 | Slot Hold & Paystack Test Checkout (`book_and_initialize_payment`)**:
   - Reply: *"Got it, I'll fast for 12 hours overnight. Please book the 08:30 AM slot tomorrow. My name is Tunde Bakare, phone +2348055556666, email tunde@example.com."*
   - Show the live MCP JSON-RPC stream on the right dashboard logging `tools/call` → `book_and_initialize_payment`, locking `SLOT-20261020-0830-PATH`, and returning booking reference `MCK-...` + Paystack checkout URL (`₦28,500` / `2,850,000 kobo`).

3. **1:25 – 2:10 | Error Handling: Unpaid / Declined Payment (`verify_payment_and_issue_pass`)**:
   - Before paying (or after clicking **Simulate Decline** on the dashboard), send on WhatsApp: *"I've made the payment for MCK-..., please send my lab pass."*
   - Show Ada calling `verify_payment_and_issue_pass`, catching that Paystack reports `abandoned` / `failed` (`Insufficient Funds`), **refusing to issue a Lab Pass**, and returning the retry link while keeping the 30-minute hold active.

4. **2:10 – 2:55 | Verified Payment & Fast-Track Lab Pass Issuance**:
   - Complete the test payment (via Paystack Test Card or clicking **Mark Paid** on the checkout page) and message on WhatsApp: *"Sorry, just completed the payment now! Please check again."*
   - Show Ada calling `verify_payment_and_issue_pass`, confirming `status: "success"` and `2,850,000 kobo`, transitioning the booking to `CONFIRMED` on the dashboard, and issuing the **Fast-Track Lab Pass (`MCK-PASS-XXXX`)**.
