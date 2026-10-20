# Go-Live Handover Note — MedCheck Diagnostics Lekki WhatsApp Desk ("Ada")

**To:** Managing Director & Front-Desk Operations Lead, MedCheck Diagnostics Lekki  
**From:** Forward-Deployed Engineering (TSG Labs)

---

### What Ada Does Today
1. **Screens Patients on Preparation Rules Before Booking:** Quotes official Naira prices and mandatory prep rules (e.g., 10–12hr overnight fast for Lipid Profile, 1L water/full bladder for Pelvic Ultrasound, pacemaker safety check for MRI) and requires the patient to confirm before holding a slot.
2. **Checks Live Slots & Enforces Morning Cutoffs:** Connects directly to your clinic schedule, blocks afternoon bookings for fasting blood tests (after 11:00 AM), and places a 30-minute hold on open slots.
3. **Collects & Verifies Paystack Pre-Payments:** Generates a unique Paystack payment link for every booking, checks Paystack's API to confirm the exact Naira/kobo amount landed, and issues a **Fast-Track Lab Pass (`MCK-PASS-XXXX`)**. If a card declines or isn't paid yet, Ada refuses to issue a pass and sends a retry link.

### What Ada Does NOT Do (And Hands Off to Your Staff)
- **Medical Emergencies:** Immediately directs patients reporting chest pain, stroke symptoms, or severe bleeding to the nearest hospital ER (`112` / `767`) and alerts your front desk.
- **HMO / Insurance & Contrast Scans:** Directs HMO patients (Hygeia, AXA Mansard, Reliance) and IV-contrast MRI/CT requests to your physical HMO/Radiology desk for pre-authorization and creatinine checks.
- **Refunds on Paid Bookings:** Cancels *unpaid* slot holds automatically, but escalates any refund or reschedule request on an already-paid booking to your billing supervisor.

### What Must Be True Before Going Live for Real Patients
1. **Live Paystack Keys & Webhook Backup:** Replace `PAYSTACK_SECRET_KEY=sk_test_...` with your live `sk_live_...` key and enable the Paystack `charge.success` webhook so payments confirm automatically even if a patient closes WhatsApp after paying.
2. **Production WhatsApp Business Number:** Connect your verified clinic WhatsApp Business number (`+234...`) in **Chord → Teammate Settings → Channels** (replacing the test simulator).
3. **Automated Hold Expiry Cron:** Enable the scheduled job that releases unpaid 30-minute slot holds back to clinic inventory so abandoned checkouts never block morning capacity.
4. **Escalation Alerts & Human Takeover:** Configure **Chord Escalation Notifications** to email/ping your Front-Desk Supervisor (`frontdesk@medchecklekki.com`) whenever Ada escalates an HMO inquiry, a repeated payment failure, or an emergency red flag.
