# Changelog

## 0.2.0 (2026-10-18)

- **New tab: Screening follow-up.** It lists a patient's latest PHQ-2, PHQ-9, GAD-7 or AUDIT-C total from the last 180 days when it's at or above the instrument's published cut-off, with nothing coded after it. It reads the questionnaire responses OpenEMR's own runtime saves.
  - **What clears an item:** a later response to the same instrument; for a PHQ-2, a PHQ-9 that day or later; a problem or visit diagnosis for the condition; for AUDIT-C, a billed brief intervention; or staff dismissing it with a reason.
  - **Each row shows its basis:** the score, the cut-off and what the paper says it means, the paper with its PubMed id, and when and by whom it was recorded. It says when the AUDIT-C cut-off for women stood in because the patient's sex isn't recorded.
  - **Priority:** totals in the published severe band (PHQ-9 20 or more, GAD-7 15 or more) are High.
  - **Visits:** a response filled in at a visit takes that visit's site and sensitivity.
- **Left out on purpose:**
  - suicide risk (PHQ-9 item 9, the ASQ), which needs action at the visit, not a worklist;
  - totals no item is coded for, such as LOINC's own imported PHQ-9;
  - responses whose encounter form was deleted, since OpenEMR keeps the response row.

  The README explains these, and how the tab meets the four criteria in FDA's January 2026 guidance on clinical decision support software.
- **Tested:**
  - **Isolated tests:** each instrument at, under and over its cut-off; AUDIT-C by sex; the latest response counting; each thing that clears an item; the window; uncoded totals. Two deliberate breaks of the rules each fail them.
  - **On OpenEMR 8.4.1** with `dev/worklist_screens_check.py` in the openhealthcare repository: synthetic responses in the runtime's shape, each cleared in turn, and a PHQ-9 filled in OpenEMR's own Questionnaires page (total 27, listed as High). The audit entry names the listed patients, and everything added is removed.
- No database change.

## 0.1.2 (2026-10-12)

- **Runs on OpenEMR 8.1.0 and 8.2.0.** The page failed there with "Call to undefined function getUserSetting()": OpenEMR loads `library/user.inc.php` for every page only from 8.3.0, so the page now loads it itself when it's missing. Checked on the official 8.1.0, 8.2.0, 8.3.0 and 8.4.0 images: installed through Module Manager, the page loads for an administrator with no PHP error.
- The README's compatibility table now covers those releases, and says that 8.0.0 and older can't run it.

## 0.1.1 (2026-10-10)

No code changes. The access checks are now verified end to end, on OpenEMR 8.4.1 and current master (8.5.0-dev), with synthetic data:
- a user without `patients/med` gets no menu entry, a 403 page, and refused actions
- a view-only user sees the list with no action controls, and posted actions are refused
- work from sensitive visits is masked and can only be routed to someone with access
- Restrict Users to Facilities limits what's listed, counted, acted on and assignable
- every view is audit-logged

The README's Access section lists them. Installing from Packagist is also verified.

## 0.1.0 (2026-10-09)

First release, as a standalone Composer package.

- Four queues across all patients: results to sign, orders with no result, referrals with no reply, and care gaps for visits in the next two days.
- Staff actions (assign, snooze, dismiss, reopen) recorded in `staff_worklist_log`; work items themselves are recomputed, never stored.
- Sites, following OpenEMR's Restrict Users to Facilities setting; masking of work from sensitive visits; overdue critical results; a portal-visibility notice on unsigned results; every view audit-logged.
- Care gaps from OpenEMR's rules engine, refreshed by a background service the module switches on when enabled.
- `FOOTPRINT.md`, generated from the source and checked in CI, and `tools/profile-counts.sql` for sizing a clinic without patient-level data.
