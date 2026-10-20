# Changelog

## 0.6.0 (2026-10-20)

- **Screening follow-up reads OpenEMR's own PHQ-9 and GAD-7 encounter forms** (`form_phq9`, `form_gad7`), which keep each question's points in a column of their own. A clinic that screened with them got no rows before.
  - They're scored as questionnaires are, with the same cut-offs and the same rule for unfinished screens. The row says "From the PHQ-9 encounter form".
  - A deleted form isn't read. The difficulty question isn't scored.
  - Their work items are keyed by the form ("screen:<pid>:phq9-<id>"), since form ids and response ids are different tables'.
- **New tab: Prior authorizations,** from OpenEMR's own Prior Authorizations module when it's installed and enabled.
  - An authorization in force is listed when its units are used up (High), when it ends within 14 days, or when a tenth or less of its units are left.
  - It's for users with `patients/docs`, the check that module's menu entry makes.
- **Fixed:** a claims follow-up line read "the claim`s posting". OpenEMR's `xl()` turns an apostrophe into a backtick, so the text no longer has one, and a test now fails on any translated string with an apostrophe or quote mark.
- `tools/footprint.php` refuses web requests.

## 0.5.0 (2026-10-19)

- **Claims follow-up: lines written off with a denial reason.** A remittance that adjusted a line off ("Adjust code NN"), paid nothing for it, and gave a reason other than the contractual 45 or 59 is listed, High.
  - That's how OpenEMR before 8.4.1 posted a line denial inside a paid claim: as a write-off. Since openemr/openemr#11868 (in 8.4.1) it refuses to post one.
  - The row names the line, the reason with OpenEMR's description, the amount and when it was written off. It stays until dismissed, since closing the level doesn't undo the write-off.
- **Claims follow-up: a remittance that left the insurance open.** A claim with a payment or adjustment posted at its level more than 30 days ago, and the level still open, is listed. Normal.
  - Since 8.4.1, a line OpenEMR won't post (paid nothing, with a reason other than 45 or 59) stops the rest of that claim's posting and leaves the level open. Before this, the tab dropped such a claim as soon as anything posted.
- **A Sliding fee re-assessments tab**, when the [Sliding Fee Discount](https://github.com/anun333/oe-module-sliding-fee) module is installed and enabled.
  - It lists patients seen in the last 12 months whose assessment runs out within 30 days, has run out, or who were never assessed, by the main schedule's re-assessment period.
  - The button opens that module's assessment page. It needs `patients/demo`, as that module's own list does.
- `version.php` and `info.txt` say 0.5.0. They had said 0.3.0 and 0.1.0.
- **Tested:**
  - unit tests for each new kind and the memo parsing, and for the re-assessment rule and period;
  - on OpenEMR 8.4.1, a synthetic 835 with a paid line and a CO-50 line paid nothing: core posted the first, refused the second and left the level open, and the claim is listed once its posting is 40 days old;
  - an "Adjust code 50" write-off listed, High, and kept after the level closed;
  - the re-assessments tab against synthetic schedules and assessments, including a service schedule's period being ignored.

## 0.4.1 (2026-10-19)

- **Referral replies follows OpenEMR's transaction permission.** The tab, its counts and its rows are there only for users with `patients/trans`. From OpenEMR 8.5 (openemr/openemr#14627), the page that records a referral reply refuses anyone without it, so until now a user with `patients/med` and not `patients/trans` was shown work they couldn't open. On 8.4 that page checks nothing, so there the worklist is the stricter of the two. Every stock group with `patients/med` also holds `patients/trans`.
- **The Record reply button** still works on 8.5's added check that a referral belongs to the open chart, because it opens the patient's chart before the referral. Checked in a browser on master.
- **Tested:**
  - `dev/smoke.py` has a sixth access check: no referral tab or rows for a `patients/med` user without `patients/trans`, and both for a Clinicians user. It passes on 8.4.1 and on master 1faefbbeb9, and fails on master with 0.4.0.
  - On master, OpenEMR's transaction page refuses the first user (403) and opens the referral for the second.
- **Fixed:** the README's status line still said 0.3.0.
- No database change.

## 0.4.0 (2026-10-18)

- **Screening totals are counted from the answers**, using each answer option's points in the questionnaire, rather than read from the total the form saved. Questionnaires that don't code their questions are read from the saved total, as before.
- **Unfinished screens.** A skipped question can only add points, so:
  - **at or above the cut-off on the answers given:** listed as before, saying how many were answered;
  - **below it even with every skipped question at its highest:** not listed;
  - **anything between:** a new Normal-priority row, for example "PHQ-9 not finished: 6 of 9 questions answered, 5 points so far", with the most it could reach. It's cleared by what clears a finished screen, or by dismissing it with a reason.
  - **Nothing answered:** not a screen. Until now, the 0 that OpenEMR's runtime saves for an empty PHQ-9 read as a total under the cut-off.
- **Keys.** An unfinished row has its own key (`screen:<pid>:<response>.<digit>`), so dismissing it doesn't hide the result if the same response is finished later.
- **LOINC's own imported PHQ-9** is now read. Its total item is uncoded, so the instrument is recognized by its panel code.
- **Fixed:** the PHQ-2's cut-off wording and the tab's owner column showed a backtick for an apostrophe, because OpenEMR's `xl()` turns quotes into backticks.
- **Unchanged on purpose:** PHQ-9 item 9 isn't listed on its own (see the README); it counts toward the total like any other question.
- **Tested:**
  - **Isolated tests:** the range on each side of the cut-off, an empty form, answer points read from the options, from the answer's own extension or from `itemWeight`, out-of-range answers, nested answers, LOINC's shape, and a PHQ-9 not being read as a PHQ-2. Seven deliberate breaks each fail them.
  - **On OpenEMR 8.4.1 and master 1faefbbeb9** with `dev/worklist_screens_check.py`: seven full PHQ-9s with questions skipped, and an unfinished row dismissed and then finished in place, as well as every 0.2.0 case.
- No database change.

## 0.3.0 (2026-10-18)

- **New tab: Claims follow-up**, for billing staff. At most one row per visit:
  - **Denied** (High): the visit's latest claim is a denial recorded by OpenEMR's ERA posting. When an 835 says a claim was denied, OpenEMR posts nothing, marks the claim denied and says "follow up manually"; until now, the denial showed only on that visit's row in the Billing Manager.
  - **No payer response:** billed to an insurer more than 45 days ago, with that level still open and nothing posted at it since. High after 90 days.
  - **Flagged for follow-up:** a line someone ticked Follow Up on in payment posting, with the note.
- **Each row shows:** the payer, the service date, when the claim was billed or denied, and the denial reasons with OpenEMR's own descriptions. The button opens OpenEMR's posting page for the visit.
- **Rows leave the list** when the claim is billed again, its level is closed, something is posted at that level, or the flag is unticked.
- **Who sees it:** only users OpenEMR's Billing Manager admits (`acct/eob` or `acct/bill`, with write). For anyone else the tab, its counts and its actions aren't there.
- **Tested:**
  - **Isolated tests:** each kind and their precedence; each thing that clears a row; the 45-, 90- and 365-day limits; parsing the stored denial reasons. Two deliberate breaks of the rules each fail them.
  - **On OpenEMR 8.4.1** with `dev/worklist_claims_check.py` in the openhealthcare repository: claims billed with OpenEMR's own `updateClaim`, and a synthetic 835 posted through OpenEMR's own ERA posting (a denial with CO-16, and a payment). It checks rows for the denial, claims billed 60 and 100 days ago, and a follow-up flag, and each row clearing. Everything added is removed.
- No database change.

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
