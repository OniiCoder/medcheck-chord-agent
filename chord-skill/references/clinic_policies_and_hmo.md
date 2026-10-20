# MedCheck Diagnostics Lekki — Clinic Policies, HMO Rules & Escalation Boundaries

## 1. Branch Location & Operating Hours
- **Address**: 14 Admiralty Way, Lekki Phase 1, Lagos (opposite Zenith Bank, 2nd Floor MedCheck Plaza).
- **Operating Hours**:
  - Monday – Saturday: `07:30 AM – 06:00 PM` (Phlebotomy fasting draws close strictly at `11:00 AM`).
  - Sunday: Closed for routine outpatient bookings.

## 2. Pre-Payment & 30-Minute Slot Hold Policy
- Ultrasound, MRI, Cardiology, and Phlebotomy slots are high-demand.
- Unpaid slots are held for **30 minutes** when booked on WhatsApp.
- Only **Paystack checkout links** (`checkout.paystack.com`) are accepted on WhatsApp.
- **No manual bank transfer screenshots**: Do not accept pasted text or screenshots claiming a transfer was made. Every payment must be verified through the `verify_payment_and_issue_pass` tool against Paystack's API before a `MCK-PASS-XXXX` code is issued.

## 3. Deliberately Out-of-Scope Services (Escalate or Inform Patient)
1. **HMO / Health Insurance Bookings (Hygeia, AXA Mansard, Reliance HMO, Leadway)**:
   - WhatsApp self-service booking is for **out-of-pocket Paystack payments only**.
   - Patients using an HMO must present their physical/digital HMO ID and a valid hospital referral letter at the HMO Desk on the ground floor so our HMO officers can request a pre-authorization code from the insurer.
2. **Home Sample Collection**:
   - Currently paused on WhatsApp self-serve; available only by calling the front desk directly (`0700-MEDCHECK`) for Victoria Island, Ikoyi, and Lekki Phase 1 addresses.
3. **Contrast MRI / CT Studies**:
   - Any MRI or CT requiring intravenous contrast (Gadolinium/Iodine) requires a recent Serum Creatinine/eGFR kidney function result and a physical doctor's request form reviewed by our resident radiologist.
4. **Refunds on Paid Bookings**:
   - Unpaid holds can be cancelled automatically via `lookup_or_cancel_booking` (`action: "cancel_hold"`).
   - Once a booking is `CONFIRMED` (paid), refunds or date changes within 4 hours of the slot require human billing supervisor sign-off.
