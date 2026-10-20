# MedCheck Diagnostics Lekki — Test Catalog & Clinical Preparation Rules

All prices are in Nigerian Naira (NGN). Every test requires pre-payment via Paystack to lock a slot and issue a Fast-Track Lab Pass (`MCK-PASS-XXXX`).

| Test Code | Test Name | Modality | Price (NGN) | Morning Only (<11:00) | Mandatory Patient Preparation | Turnaround Time |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `LIPID-FAST` | Fasting Lipid Profile (Total Cholesterol, HDL, LDL, Triglycerides) | `pathology` | ₦28,500 | **YES** | **10–12 hours overnight fasting.** Plain water only. No tea, coffee, milk, gum, or food. Blood must be drawn before 11:00 AM. | Same day (6 hours) |
| `FBS-HBA1C` | Fasting Blood Sugar (FBS) + HbA1c Diabetes Panel | `pathology` | ₦22,000 | **YES** | **8–10 hours overnight fasting.** Plain water only. Hold morning insulin or oral diabetes tablets until after sample collection. | Same day (4 hours) |
| `FBC-MALARIA` | Full Blood Count (FBC) + Malaria Parasite Microscopy | `pathology` | ₦14,500 | No | No fasting required. Drink water to stay well hydrated before phlebotomy. | 2 hours |
| `US-PELVIC` | Pelvic / Lower Abdomen Ultrasound Scan | `ultrasound` | ₦25,000 | No | **FULL BLADDER MANDATORY:** Drink 4 glasses (~1 liter) of water 1 hour before your slot and **do not urinate** before the scan. | Verbal report + PDF in 45 mins |
| `US-ABDOMEN` | Upper Abdominal Ultrasound (Liver, Gallbladder, Kidneys) | `ultrasound` | ₦30,000 | **YES** | **6–8 hours fasting** so the gallbladder remains distended and bowel gas is minimized. Small sips of water are permitted. | Signed report in 45 mins |
| `MRI-BRAIN` | Brain MRI (Non-Contrast 1.5T) | `radiology_mri` | ₦165,000 | No | **MRI METAL SAFETY SCREENING:** Cannot scan patients with cardiac pacemakers, cochlear implants, aneurysm clips, or metallic eye fragments. Remove all jewelry, wigs with metal clips, watches, and bank cards. | Consultant Radiologist report in 24 hours |
| `MRI-LUMBOSACRAL` | Lumbosacral Spine MRI (Lower Back 1.5T) | `radiology_mri` | ₦155,000 | No | **MRI METAL SAFETY SCREENING:** Confirm no cardiac pacemaker, neurostimulator, or ferromagnetic implants. Metal-free gown provided at clinic. | Consultant Radiologist report in 24 hours |
| `ECHO-ECG` | 12-Lead Resting ECG + Transthoracic Echocardiogram | `cardiology` | ₦65,000 | No | No fasting required. Wear a two-piece outfit (separate top) and avoid chest lotion/oil on the morning of the exam. | Cardiologist report in 4 hours |

## Why We Enforce Morning-Only Cutoffs (`morning_only = true`)
- For `LIPID-FAST`, `FBS-HBA1C`, and `US-ABDOMEN`, afternoon bookings are clinically unsafe and produce inaccurate lab values (prolonged daytime fasting triggers glycogenolysis and false blood sugar shifts, or patients accidentally break their fast with snacks/drinks).
- If a patient asks for an afternoon slot (e.g., 2:00 PM) for a fasting test, politely explain this rule and offer morning slots (`07:30`, `08:30`, `09:30`, `10:30`).
