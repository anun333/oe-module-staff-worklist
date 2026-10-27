# Staff Worklist for OpenEMR

One queue of outstanding staff work across all patients, so nobody has to check four separate reports.

**Status: 0.8.7, a pre-release.** Tested on OpenEMR with synthetic data only, access checks included (see [Compatibility](#compatibility)). It lists many patients' work on one page, so read [Access](#access) and [FOOTPRINT.md](FOOTPRINT.md) before installing, and try it with your own users and settings before giving staff access.

Using the module day to day, tab by tab for each role: see [GUIDE.md](GUIDE.md), or the page's **Help** link, which opens the guide for the installed version.

| Tab | Listed when | Leaves the list when | Default owner |
|---|---|---|---|
| Results to sign | A result was received and nobody has signed it | A clinician signs it (Procedures › results › **Sign Results**) | Ordering provider |
| No result yet | An order has no final result after 7 days (1 day for stat, ASAP, urgent or high) | A result arrives, or the order is completed or cancelled | Ordering provider |
| Referral replies | An outgoing referral has no **Reply Date** after 14 days | Someone enters the reply date on the referral | Referring provider |
| Care gaps, upcoming visits | A patient with a visit in the next 2 days has clinical reminders due | The visit passes or is cancelled, or the reminders are met | Visit provider |
| Screening follow-up | A patient's latest PHQ-2, PHQ-4 (each part), PHQ-9, GAD-7 or AUDIT-C from the last 180 days is at or above the instrument's published cut-off, or is unfinished and could still reach it | Something is coded after it: a later response, a problem or visit diagnosis for the condition, or a brief intervention for alcohol. See [Screening follow-up](#screening-follow-up) | Visit provider, else the patient's provider |
| Claims follow-up | A claim was denied (OpenEMR records it and posts nothing), had a line written off with a denial reason, was billed to an insurer more than 45 days ago with nothing posted since, had a remittance posted that left its insurance open, or has a line flagged for follow-up in payment posting. See [Claims follow-up](#claims-follow-up) | The claim is billed again or its insurance level is closed, something is posted at that level, or the flag is unticked; a write-off stays until dismissed | Nobody: billing staff take them from Unassigned |
| Sliding fee re-assessments | With the [Sliding Fee Discount](https://github.com/anun333/oe-module-sliding-fee) module installed: a patient seen in the last 12 months whose assessment runs out within 30 days, has run out, or who was never assessed | A new assessment is recorded | Nobody: the front desk takes them from Unassigned |
| Social needs follow-up | A patient's latest social needs screen of a kind, from the last 180 days, is positive: OpenEMR's SDOH history, a Hunger Vital Sign, or a six-item food security survey (raw score 2 or more). Interpersonal safety is High. See [Social needs follow-up](#social-needs-follow-up) | A referral on or after the screen; for food, housing, financial strain and utilities, a service UDS Table 6A Lines 40 to 42 count, billed on or after it; or a later negative screen of the same kind | Nobody: a care coordinator takes them from Unassigned |
| Care management, not billed | Added by the [Care Management](https://github.com/anun333/oe-module-care-management) module (0.3.2 or later) when it is installed: an enrollment whose month, in the last three completed months, met a code (CCM, PCM, BHI, CoCM or APCM, by CMS's time rules) with nothing billed for its service, read as Care Management's own month list reads it | A line for the service is billed for that month, or the row is dismissed (a month the clinic decides not to bill) | The enrollment's billing practitioner |
| Prior authorizations | With OpenEMR's own Prior Authorizations module enabled: an authorization in force whose units are used up (High), that ends within 14 days, or with a tenth or less of its units left | It ends, or someone dismisses it. Each authorization is listed on its own, so recording a new one leaves the old one's row until the old one ends: dismiss it then, as renewed | Nobody: the front desk takes them from Unassigned (see [Sites](#sites) for facility restriction) |

Work items are not stored. They're recomputed from OpenEMR data, and the worklist only reads them. Staff do the work in OpenEMR's own screens (the row button opens the right one), and the item disappears when that work is recorded.

## Priority

- **Critical**: a result with a panic value (`vhigh` / `vlow`).
- **High**: a result flagged abnormal, any result unsigned for more than 3 days, an overdue urgent order, an order overdue by twice its window, a referral that's high risk or more than 28 days old, and a screening total in the instrument's published severe band (PHQ-9 20 or more, GAD-7 15 or more), a denied claim, a claim line written off with a denial reason, a claim with no payer response after 90 days, a prior authorization with its units used up, and a positive interpersonal safety screen.
- **Normal**: everything else, including care gaps, other screening totals and unfinished screens. Care gaps are visit prep, not an open safety loop.

Within a priority, the oldest item comes first; care gaps sort by visit time. Thresholds are in `src/Policy/PriorityPolicy.php`; the screening cut-offs, from their papers, are in `src/Screening/ScreeningInstrument.php`.

## Staff actions

These change only what the worklist shows. They never change the chart.

- **Assign** to any active user. Unassigned items count as the owner's.
- **Snooze** until a date. Critical results can't be snoozed, and unsigned results can only be snoozed for up to 3 days.
- **Dismiss** with a required reason. Unsigned results can't be dismissed; only signing them clears them. A dismissal holds only the problem it was made on: if the record later shows a different one (a claim file not sent after a denial, the claim denied again after it was billed again, units used up after "ends soon", "ran out" after "runs out soon"), that comes back. From 0.8.6; on upgrade, what staff saved is moved to each item once.
- **Reopen** a snoozed or dismissed item.

Every change is written to `staff_worklist_log`, with who made it and when.

## Sites

Each item belongs to a site (an OpenEMR facility):

| Item | Site |
|---|---|
| Results and orders | The facility of the visit the order was placed in, or else the ordering provider's default facility |
| Referrals | The referring provider's default facility, the same rule Reports › Referrals uses |
| Care gaps | The appointment's facility |
| Screening follow-up | The facility of the visit the questionnaire was filled in; none when it was filled outside a visit |
| Claims follow-up | The visit's facility |
| Sliding fee re-assessments | The facility of the patient's last visit |
| Social needs follow-up | The facility of the visit the screen was filled in; none when it was filled outside a visit |
| Prior authorizations | None: an authorization belongs to no visit. With **Restrict Users to Facilities** on, work with no site is shown only to unrestricted users, so a restricted front desk sees these only once someone unrestricted assigns them |

Items with no determinable site are listed as **No site**. Inactive facilities are labeled *(inactive)* but still listed. An unsigned result at a closed site is still an open obligation, so the worklist never hides work because a site closed.

Use the **Sites** menu to choose which sites' work you see. The choice is remembered per user. It only narrows your view; it doesn't grant access.

### Who can see which sites

The worklist follows OpenEMR's **Restrict Users to Facilities** setting (Admin › Config › Features). With it on, a user with facilities set in User admin sees only work at their default facility plus those facilities. That's the same rule the calendar applies, using OpenEMR's own `getUserFacilities()`. Users with no facilities set, and everyone when the setting is off, see all sites.

Under restriction:
- Work at other sites isn't listed or counted, and actions on it fail as if it doesn't exist.
- Work with no known site is shown only to unrestricted users.
- Users still see items they own (ordering, referring or visit provider) or were assigned. They're responsible for that work and already have a treatment relationship with the patient.
- An item can only be assigned to someone who can reach its site, or to its owner.

Every worklist view is written to OpenEMR's audit log (Admin › Logs, event `patient-record-select`) with the patients listed, because the page shows many patients' records at once.

This supports role-based access under HIPAA's minimum necessary standard, but your compliance officer should decide whether to turn the restriction on and how to assign facilities. If a site runs a 42 CFR Part 2 program, its substance-use results and referral reasons need that restriction, or tighter controls, before staff at other sites use this module.

## Safety and privacy behaviour

- **Sensitive visits.** OpenEMR lets a visit be marked with a sensitivity level, "High" by default. Users without that level see the chart's details for such visits hidden. The worklist applies the same rule to results and orders from those visits: the row is still listed, so the work isn't lost, but the test name, details and notes are hidden and the button to open the results is removed. Those users can only Assign the item, and only to someone who holds that sensitivity level. The server enforces this, not just the page. On OpenEMR 8.4.x the core results page itself doesn't check visit sensitivity; a fix is proposed upstream in openemr/openemr#14442 and not yet merged.
- **Overdue critical results.** A result with a panic value that stays unsigned for 4 hours is marked **Overdue**. A red banner states how many there are, whatever the current view, scope, site or tab, as long as the user may see them. Set `criticalOverdueHours` in `src/Policy/PriorityPolicy.php` to match the clinic's critical-results policy.
- **Results the patient can already see.** The patient portal shows results whether or not anyone has signed them (`portal/get_lab_results.php`). When the portal is enabled and the patient can log in, unsigned abnormal and critical results say "Patient can already see this in the portal", so staff know to contact the patient first. The worklist doesn't hold results back from the portal, because that would risk information blocking under the Cures Act.
- **Deceased patients.** Their work is labelled **Deceased**. Their unsigned results still need signing, but they get no pre-visit care gaps.
- **Audit.** Each view of the worklist is written to OpenEMR's audit log, and each assign, snooze, dismiss or reopen to `staff_worklist_log` (with `rekey` and `drop` for the one-time move of what was saved under 0.8.5 keys).

To size the worklist for a real clinic, run the read-only `tools/profile-counts.sql` as a read-only database user. It returns clinic-wide counts only, with no patient details, covering:
- The age of the unsigned-results backlog.
- How often referral replies are recorded.
- Use of sensitive visits and site restrictions.
- How many patients have portal access.

## Screening follow-up

The tab reads the questionnaire responses OpenEMR's own runtime saves, from the encounter form (New Questionnaire) and the patient's Questionnaires page. It lists a patient's latest response to an instrument when its total is at or above the cut-off the instrument's validation paper gives, and nothing is coded after it.

**OpenEMR's own PHQ-9 and GAD-7 encounter forms** (0.6.0) are read too: Encounter › PHQ-9 and GAD-7, which keep each question's points in a column of their own. They're scored the same way, with the same cut-offs, and the row says which form it came from. A deleted form isn't read, and the forms' difficulty question isn't scored, as on the published instruments. A site that hasn't registered those forms simply has none.

**The total is counted from the answers** (0.4.0), not read from the total the form saved. Each answer's points come from the questionnaire's own answer options, and the questions are found by their LOINC codes. When a questionnaire doesn't code its questions, the saved total is read instead, as before.

**Unfinished screens.** Patients skip questions, and nothing is filled in for one. A skipped question can only add points, so the answers given decide most cases:
- **At or above the cut-off** on the answers alone: listed as usual. The row says how many questions were answered.
- **Below the cut-off even if every skipped question had its highest answer:** not listed.
- **Anything between:** listed as unfinished, for example "PHQ-9 not finished: 6 of 9 questions answered, 5 points so far", with the most the total could reach. Finish the screen or dismiss the row with a reason. Dismissing an unfinished row doesn't hide the result if the same response is finished later.
- **Nothing answered:** not a screen, so not listed. OpenEMR's runtime saves a total of 0 for an empty form; that 0 isn't read.

This needs no rule for missing answers. The PHQ instruction manual gives none (checked 2026-10-18), and filling in or rescaling missing answers is a research convention that varies by instrument.

| Instrument | Total read (LOINC) | Listed at | Source | Also cleared by, on or after the screen's day |
|---|---|---|---|---|
| PHQ-2 | 55758-7 | 3 or more | Kroenke 2003, PMID 14583691 | a PHQ-9 response, or what clears a PHQ-9 |
| PHQ-9 | 44261-6 | 10 or more (severe from 20) | Kroenke 2001, PMID 11556941 | a depression problem or visit diagnosis (ICD-10 F32, F33, F34.1) |
| GAD-7 | 70274-6 | 10 or more (severe from 15) | Spitzer 2006, PMID 16717171 | an anxiety problem or visit diagnosis (ICD-10 F41) |
| AUDIT-C | 75626-2 | 4 or more for men, 3 or more for women | Bradley 2007, PMID 17451397 | an alcohol problem or visit diagnosis (ICD-10 F10, Z71.41), or a brief intervention billed (CPT 99408, 99409; HCPCS G0443) |
| PHQ-4 depression (its PHQ-2) | from its two questions, 44250-9 and 44255-8 | 3 or more | Kroenke 2009, PMID 19996233 | what clears a PHQ-2 |
| PHQ-4 anxiety (its GAD-2) | from its two questions, 69725-0 and 68509-9 | 3 or more | Kroenke 2009, PMID 19996233 | a GAD-7 response, or what clears a GAD-7 |

The PHQ-4 (0.8.0) is followed up as its authors score it: each part on its own, at 3 or more. LOINC codes the PHQ-4's total (70272-0) but not its parts, and has no GAD-2 total, so each part is scored from its own two coded questions in a questionnaire with the PHQ-4's panel code (69724-3), as Clinic Forms' PHQ-4 has. Both parts at their cut-off make two rows, each keyed by its part.

- **A later response** to the same instrument always replaces an earlier one, so a re-screen under the cut-off clears it.
- **A problem already on the list** for the condition clears it too: the clinician already knows.
- **Follow-up recorded only in a note** can't be seen. Dismiss the item with a reason, such as "addressed in the visit note".
- **Each row shows its basis:** the score, the cut-off and what the paper says it means, the paper, and the date and who recorded it. It also says when a fact was missing; for example, when a patient's sex isn't recorded as male or female, AUDIT-C uses the women's cut-off and the row says so, and an unfinished screen says how many questions were answered.
- **It never calls a screen positive or suggests a diagnosis or treatment.** Whether a screen is positive under the clinic's protocol, and what to do, is the clinician's decision.
- **The cut-offs, questions and points** are the ones checked in [Clinic Forms](https://github.com/anun333/oe-module-clinic-forms)' instrument references, against each paper and LOINC.

What it leaves out:

- **Suicide risk.** PHQ-9 item 9 and the ASQ aren't read on their own. A positive answer needs action at the same visit under the clinic's protocol, and a worklist that people look at days later is the wrong tool for that. Item 9 counts toward the PHQ-9 total like any other question, and a skipped item 9 counts as unanswered. Clinic Forms' printed PHQ-9 tells staff to ask about it the same day when it's answered above "Not at all" or left blank.
- **Questionnaires that code neither the total nor the panel.** A questionnaire is read as an instrument when it carries the instrument's LOINC total code or panel code (PHQ-9 44249-1, PHQ-2 55757-9, GAD-7 69737-5, AUDIT-C 72109-2). LOINC's own imported PHQ-9, whose total item is uncoded, is now read through its panel code (0.4.0).
- **Responses whose encounter form was deleted.** OpenEMR keeps the response row when the form is deleted; the tab doesn't read it.
- **Responses from patients now deceased.**

**Intended use, and why it isn't a medical device.**
- **Who it's for:** clinicians and the clinical staff who work with them, to see screens that may not have been followed up. It doesn't appear in the patient portal.
- **The FDA position:** FDA's guidance "Clinical Decision Support Software" (January 29, 2026) excludes software from the device definition when it meets all four criteria of section 520(o)(1)(E) of the FD&C Act. Its examples include "reminders for preventive care" and matching a patient's record to published reference information. This tab is built to those criteria:
  - its inputs are recorded questionnaire answers, not images or signals;
  - it supports a clinician's decision without directing one;
  - it shows the basis for each row;
  - it isn't for time-critical decisions, which is why suicide risk is left out.

  That's our reading of the guidance, not FDA's review of this module.

**Privacy.** The tab lists depression, anxiety and alcohol screening results across patients.
- **Sensitive visits:** a response filled in at a visit marked sensitive is masked like a result from that visit.
- **Outside a visit:** a response filled in outside a visit has no sensitivity to apply.
- **42 CFR Part 2:** a site with a Part 2 program should apply the restrictions described under [Sites](#who-can-see-which-sites) before staff at other sites use this tab.

## Social needs follow-up

New in 0.7.0. A positive screen that nobody acted on is the gap between UDS Table 6A Line 39 (screened) and Lines 40 to 42 (services given).

**The screens:**
- **OpenEMR's SDOH history** (Patient › History › SDOH). A domain is positive when its answer is one OpenEMR itself treats as positive for that domain: the answers it turns into a health concern (`HistorySdohService`). A declined assessment isn't read.
- **The Hunger Vital Sign** (LOINC 88121-9): positive when either question is answered "Often true" or "Sometimes true" (Hager et al. 2010). A translated Clinic Forms version codes its answers by position in the published order, and is read the same way.
- **The six-item food security survey** (LOINC 95361-2): positive at a raw score of 2 or more, low or very low food security (USDA ERS user notes, May 2024). An unfinished survey that could still go either way isn't listed.

**What takes a row off**, on or after the screen's day:
- a referral (Transactions › Referral, by its Referral Date);
- for food, housing and financial strain (and utilities), a service in the UDS module's value sets for Table 6A Line 40, 41 or 42 billed at a visit, once that module has fetched them. Those are the services UDS counts;
- a later screen of the same kind with nothing positive.

Transportation, safety, social isolation, childcare and digital access have no UDS line, so only a referral, a later negative screen or a dismissal takes them off. The row names the needs still open.

## Claims follow-up

The tab lists claims that need billing staff, at most one row per visit:

| Kind | Listed when | Priority |
|---|---|---|
| Denied | The visit's latest claim is a denial that OpenEMR's ERA posting recorded. When an 835 says a claim was denied, OpenEMR posts nothing, marks the claim denied, and says "follow up manually" | High |
| Claim file not sent | The latest upload that carried the claim, through OpenEMR's "Auto SFTP claims to X12 partner" (core's `x12_remote_tracker`), failed: its settings, the local file, the login, the remote folder, or from 8.5.0 the upload itself. The claim is billed, its level open, and nothing posted since. A later successful upload of the claim clears it | High |
| Claim file still waiting | The latest upload that carried the claim has been waiting, or stuck mid-upload, for more than 24 hours, which usually means the SFTP background service isn't running | Normal |
| No payer response | The latest claim was billed to an insurer more than 45 days ago, that insurance level isn't closed, and no payment or adjustment has been posted at that level since | Normal; High after 90 days |
| Line written off with a denial reason | A remittance adjusted a line off ("Adjust code NN", as OpenEMR posts it), paid nothing for it, and gave a reason other than the contractual 45 or 59. OpenEMR before 8.4.1 posted a line denial that way; since openemr/openemr#11868 (in 8.4.1) it refuses to post one | High |
| Remittance left the insurance open | A payment or adjustment was posted at the claim's level more than 30 days ago and the level is still open. Since 8.4.1, a line OpenEMR won't post stops the rest of that claim's posting and leaves the level open, so this is how such a claim shows | Normal |
| Flagged for follow-up | Someone ticked **Follow Up** on a line in payment posting | Normal |

- **What each row shows:** the payer and the service date, when the claim was billed or denied, the denial reason codes with their descriptions (from OpenEMR's own list), and the follow-up note.
- **The button** opens OpenEMR's posting page for the visit, or for a claim file not sent, OpenEMR's Claim File Tracker (`interface/billing/billing_tracker.php`), where the file can be sent again.
- **Uploads before 8.5.0:** a file whose upload itself failed was recorded as sent until openemr/openemr#14336 (in 8.5.0), so on older releases only the failures before the upload show. Dates are when the file was queued (`created_at`): core stamps `updated_at` on the 12-hour clock with no AM or PM.
- **Window:** claims billed or denied in the last 365 days.
- **Who sees it:** only users OpenEMR's Billing Manager admits (`acct/eob` or `acct/bill`, with write). The tab, its counts and its actions aren't there for anyone else.

Telling a line denial from a contractual write-off uses OpenEMR's own rule: on a line paid nothing, any adjustment other than 45 or 59 is a denial. OpenEMR doesn't store the group code with a posted adjustment, so a PI-45 counts as contractual here too.

## Tab counts

A page collects only the tab it shows, plus **Results to sign**, whose overdue critical results the red banner counts on every tab (0.8.7). The **All** tab collects every tab. Before 0.8.7 every page collected every tab, so each tab took as long as all of them together.

The other tabs' counts are kept for 10 minutes, per user and per choice of **Mine**/**Unassigned**/**Everyone**, **Open**/**Snoozed**/**Dismissed** and sites:

- A tab counted in the last 10 minutes shows that count. Hovering over it shows when it was counted, and a line under the list gives the oldest count's time.
- A tab not counted in that time shows **…** and is counted after the page loads, one tab at a time (`public/counts.php`). The **All** count appears once every tab's count is known.
- Opening a tab counts it again, and so does the **All** tab for every tab.
- Assigning, snoozing, dismissing or reopening an item through the worklist drops every user's kept count for that tab.

So work done elsewhere in OpenEMR, such as a result signed or a claim paid, reaches another tab's count within 10 minutes, or as soon as the tab is opened. The rows shown are always collected when the page loads.

The **Sites** menu's counts are for the tab shown, or for every tab on **All**.

On a synthetic copy with 20,000 patients, 200,000 visits, 195,327 claims and 15,384 PHQ-9 responses, timed on the command line with Revenue Cycle's tab left out:

| Page | 0.8.6 | 0.8.7 |
|---|---|---|
| Results to sign | 53.2 s | 0.6 s |
| Screening follow-up | 52.7 s | 1.9 s |
| Social needs follow-up | 49.3 s | 0.8 s |
| Claims follow-up (61,430 items) | 48.7 s | 4.8 s |
| Sliding fee re-assessments (15,566 items) | 49.0 s | 4.4 s |
| All | about 50 s | 12.4 s |

## Care gaps run in the background

OpenEMR's rules engine takes about 150 ms per patient, which is too slow to run when the page loads. The background service **Staff Worklist care gaps** (Admin › Config › Background Services) runs every 10 minutes while someone is logged in or from cron, and caches the results. The schedule itself is read live, so a cancelled visit drops off immediately. The page shows when gaps were last checked, and **Check now** runs the job on demand.

**Multisite, OpenEMR 8.2.0 to 8.4.1:** a background service started for a site other than `default` ran against the default site's database (openemr/openemr#14359, fixed in 8.5.0; it came with #11801 in 8.2.0). On those releases this job never refreshes another site's patients, so their care gaps update only with **Check now**. The care gaps strip says so on such a site when the checks are more than a day behind or visits haven't been checked. Single-site installs, 8.1.x and 8.5.0 or later aren't affected.

## Install

From the OpenEMR directory:

```
composer require anun333/oe-module-staff-worklist
```

The installer plugin places it in `interface/modules/custom_modules/oe-module-staff-worklist/`. Without Composer, copy this directory there instead. A copied module carries its own `vendor/anun333/openhealthcare-common`; every module copied this way must carry the same version of it, because the first module to load serves its copy to all of them (Composer keeps one copy for the site, so this applies to copies only).

Then go to Modules › Manage Modules › Unregistered, and **Register**, **Install** and **Enable** it. A **Worklist** menu entry appears next to Messages. Enabling it also switches on the background service for care gaps; disabling switches it off.

What installing it adds and changes, extracted from the source, is in [FOOTPRINT.md](FOOTPRINT.md): four tables of its own, one row in `background_services`, one index on a core table, and no outbound network. The index (0.8.7) is on `form_questionnaire_assessments.response_id`, which core leaves unindexed; the UDS, Title X and CCBHC report modules add the same index under the same name, `response_id`, so whichever module installs first creates it and the others leave it. Removing the module leaves it in place.

## Access

Who sees what:

- **The page** needs `patients/med` (the same as the menu entry). **Changing items** (assign, snooze, dismiss, reopen) needs `patients/med` write. The server checks both, not just the page.
- **Sensitive visits:** see [Safety and privacy behaviour](#safety-and-privacy-behaviour).
- **Sites:** with **Restrict Users to Facilities** on, see [Who can see which sites](#who-can-see-which-sites).
- **Claims follow-up** is there only for users OpenEMR's Billing Manager admits (`acct/eob` or `acct/bill`, with write), the same check that page makes.
- **Sliding fee re-assessments** is there only when the Sliding Fee Discount module is installed and enabled, for users who can read Demographics (`patients/demo`), the check that module's own due list makes.
- **Care management, not billed** comes from the Care Management module, which adds it only for users who may see billing (`acct/bill`), the check its month list makes before showing what was billed.
- **Tabs from other modules** come through the `staff_worklist.collect_sources` event (`src/Event/CollectSourcesEvent.php`): the module adds its work source only for users it admits, with the tab's label and its rows' owner role. See [Tabs from other modules](#tabs-from-other-modules).
- **Prior authorizations** is there only when OpenEMR's Prior Authorizations module is installed and enabled, for users with `patients/docs`, the check that module's own menu entry makes.
- **Social needs follow-up** is there for users with `patients/med` write or add-only, the check OpenEMR's SDOH history page makes.
- **Referral replies** is there only for users with `patients/trans` (0.4.1), the check OpenEMR's transaction page makes from 8.5 (openemr/openemr#14627); without it, the page that records a reply refuses them. On 8.4.1 that page checks nothing, so there the worklist is the stricter of the two. Every stock group with `patients/med` also holds `patients/trans`.

How that has been checked, on 2026-10-10, on OpenEMR 8.4.1 and current master (8.5.0-dev), with synthetic data and test logins created and removed for the run:

- The rules are unit-tested (`tests/ComplianceRulesTest.php`, `tests/ActionRulesTest.php`): sensitivity masking, which actions masked items allow, site counts, snooze and dismiss limits.
- End to end, against a running OpenEMR, the checks below all passed (the sixth since 0.4.1):
  1. A user without `patients/med` (Front Office) gets no menu entry, a 403 access-denied page at the worklist URL, and an action posted with a valid session token is refused by the permission check.
  2. A user with `patients/med` at view only sees the list, with no assign, snooze, dismiss or reopen controls and no action form; an action posted anyway is refused by the permission check.
  3. A user without the "High" sensitivity (Clinicians) sees a result from a High-sensitivity visit as "Restricted", without its test name, can't snooze it, can't assign it to someone who also lacks that level, and can assign it to someone who holds it.
  4. With Restrict Users to Facilities on, a facility-limited user sees only that facility's work plus work they were assigned, counts include only that work, work at other sites can't be acted on and isn't revealed, and work can't be assigned to someone restricted elsewhere.
  5. Each worklist view appears in Admin › Logs as `patient-record-select`, with the patients listed.
  6. A user with `patients/med` and not `patients/trans` (the read-only test group) gets no Referral replies tab and no referral rows, while a Clinicians user still sees both. On master, OpenEMR's own transaction page refuses the first user (403) and opens the referral for the second; and as an administrator, a referral's Record reply button opens the patient's chart and then the referral, which OpenEMR's check that the referral belongs to the open chart accepts. Checked 2026-10-19 on 8.4.1 and master 1faefbbeb9; with 0.4.0 on master, this check fails.

The same six are worth repeating in your own install, with your own groups and facilities, before giving staff access: your ACL setup isn't the stock one the checks used.

## Tabs from other modules

Another module adds a tab by listening for `staff_worklist.collect_sources` and handing over a work source, the tab's label and the role that owns its rows. From 0.9.0 the source is built on the shared contract in [openhealthcare-common](https://github.com/anun333/openhealthcare-common) 1.5.0 (`OpenHealthcare\Common\Contract\Worklist`: `WorkSource`, `WorkItem`, `ItemKey`, `PatientRef`), so the module needn't load this one's classes, and is added with the library's `Worklist::contribute()`:

```php
$dispatcher->addListener(Worklist::COLLECT_SOURCES, function (object $event): void {
    // Where an older copy of the library loaded first, the contract isn't there: leave the tab out.
    if (!interface_exists(WorkSource::class) || !AclMain::aclCheckCore('patients', 'med')) {
        return;
    }
    Worklist::contribute($event, new MySource(Worklist::now($event)), xl('My tab'), xl('Clinician'));
});
```

Both ways work during the change: this worklist takes a source on the shared contract (`contribute()`) and one built on its own `WorkSourceInterface` (`addSource()`, as before 0.9.0), and a module on the shared contract adds its tab to a worklist from 0.8.0 to 0.8.7 too, through `addSource()`. The plan is in the library's README › Contracts between modules.

## Compatibility

**Floors.** The module needs OpenEMR 8.1.0 or later and PHP 8.2 or later. On older OpenEMR it stays switched off rather than stop OpenEMR's pages (below).

**PHP security.** PHP's php2shell advisory (2026-10-21; CVE-2026-109618 and CVE-2026-109619) is remote code execution without logging in, and it is being exploited. Run OpenEMR on PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later, whatever the module needs. The official OpenEMR 7.0.2 and 7.0.3 Docker images can't get that fix: they carry PHP 8.2.16 and 8.3.15 on Alpine releases that no longer get updates. Run 7.0 only on a patched PHP, or upgrade OpenEMR. OpenEMR rebuilt its 8.4.1, 8.3.0, 8.2.0 and 8.0.0.3 images with the fix on 2026-10-22 (forum t/27157); a container started before then needs pulling again.

**Older OpenEMR:** on 7.0.3 and older (no `OEGlobalsBag`), the module stays switched off rather than stop OpenEMR's pages; checked on fresh 7.0.2 and 7.0.3 installs with every module enabled. On fresh official 8.1.0, 8.3.0 and 8.5.0 images (2026-10-23) it installs through Module Manager and its pages that need no patient load, with every module enabled and with it alone (`dev/module_compat_check.sh`). 7.0.4 to 8.0 haven't been tried.

Tested with synthetic data on:

| OpenEMR | install by Composer and Module Manager | queues | access |
|---|---|---|---|
| 8.4.1 (Docker image `openemr/openemr:8.4.1`) | passes (GitHub and Packagist) | passes | passes |
| 8.5.0-dev, master as of 2026-10-09 (Docker image `openemr/openemr:8.5.0`, database version 548) | passes (GitHub) | passes | passes |
| 8.4.0, 8.3.0, 8.2.0, 8.1.0 (official Docker images) | passes (Module Manager) | not tested | not tested |
| 8.0.0 and older | stays switched off (before this release, enabling it on 7.0.4 to 8.0.x stopped every page): it uses OpenEMR's `AccessDeniedHelper`, added in 8.1.0 | | |

On 8.1.0, 8.2.0, 8.3.0 and 8.4.0 (checked 2026-10-12 with `dev/module_compat_check.sh` in the openhealthcare repository) the module was copied in, installed and enabled through Module Manager, and its page loaded for an administrator with no PHP error, on a fresh install with no patients. That's a smaller check than the 8.4.1 and master rows. 8.1 and 8.2 need the module to load OpenEMR's `library/user.inc.php` itself, which 8.3 started loading for every page; the page now does. The module needs PHP 8.2 or later (8.3 until 0.8.6, though nothing needed it).

**Install** means a fresh OpenEMR that installed itself, then `composer require` from this repository, then Register, Install and Enable in Modules › Manage Modules. Each step's effect was checked: where the files went, the three tables, the background service switching on (and off again when disabled), the menu entry and the permission it requires, the page rendering, and no PHP warnings from the module in Apache's logs.

**Queues** means synthetic patients covering each queue, and the page and action endpoint driven as an administrator. Screening follow-up (0.4.0) is checked on 8.4.1 and on master 1faefbbeb9 (2026-10-18), with `dev/worklist_screens_check.py` in the openhealthcare repository. That check covers each instrument at and under its cut-off, each thing that clears a row, the window, a deleted encounter form, and a PHQ-9 filled in OpenEMR's own Questionnaires page. Since 0.4.0 it also covers full PHQ-9s with questions skipped: unfinished, already at the cut-off, unable to reach it, empty, question 9 alone, LOINC's own shape, and an unfinished row dismissed and then finished in place. Social needs follow-up (0.7.0) is checked on 8.4.1 with `dev/worklist_social_check.py`, on Clinic Forms' own Hunger Vital Sign and food security questionnaires: SDOH history entries positive and later negative, a referral after a screen, a Line 40 service from a synthetic copy of its value set, a Hunger Vital Sign in English and one answered by position, food security raw scores 3 and 1, and interpersonal safety. OpenEMR's own PHQ-9 and GAD-7 forms and the prior authorizations tab (0.6.0) are checked on 8.4.1 with `dev/worklist_core_check.py`: a PHQ-9 form at the cut-off, a GAD-7 form unfinished, a deleted form and one below the cut-off; authorizations used up, ending, nearly used, ended, plentiful and not started. Claims follow-up (0.3.0) is checked on 8.4.1 and on master 1faefbbeb9, with `dev/worklist_claims_check.py`. That check posts a synthetic 835 through OpenEMR's own ERA posting (a denial and a payment), and covers claims billed 10, 60 and 100 days ago, a follow-up flag, and each one clearing. Items appear with the right priority and site, actions work and are logged, signing a result or cancelling a visit clears its items, and the site picker narrows the list. **Access** means the six checks listed under [Access](#access).

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php          # regenerate FOOTPRINT.md after changing the module
php tools/footprint.php --check  # what CI runs
```

The unit tests need no database or OpenEMR install. CI runs them, and the footprint check, on PHP 8.2, 8.3, 8.4 and 8.5.

## Known limitations

- An order placed outside a visit (no encounter) takes the ordering provider's default facility, which may not be where the patient was seen.
- On Safari the Assign list may still show people who can't reach the site. The server refuses the assignment anyway.
- Care gaps include every active rule's reminders. The chart widget hides rules a user isn't allowed to see; the background job runs as no user, so it can't do that.
- Only non-recurring appointments are checked for care gaps, and only each patient's next visit within 2 days, for up to 500 patients.
- If someone signs a *preliminary* result, OpenEMR marks the order complete, so the worklist stops waiting for the final result.
- Referral reply fields are on the referral's **Counter-Referral** tab. The worklist opens the referral, but can't open that tab directly.
- Assigning an item doesn't notify the assignee.
- Thresholds, including the 4-hour critical-results limit, are code constants, not admin settings yet.
- Overdue critical results are flagged on the page only. Nobody is paged or notified.
- Sensitivity masking applies to results, orders and screening responses filled in at a visit. Referrals, care gaps and responses filled in outside a visit aren't tied to a visit, so they have no sensitivity to apply.
- Screening follow-up and social needs count a saved questionnaire by its status. OpenEMR's runtime from 0.4.0 (in 8.5.0 and later) saves a submitted response as `completed`, or `amended` when it was completed before; earlier runtimes saved every response as `in-progress`. Until a site has its first `completed` or `amended` response, every response counts. After it, a response saved earlier counts whatever its status, and a later one only when `completed` or `amended`, so a draft an outside app leaves `in-progress` isn't treated as a finished screen. `entered-in-error` never counts.
- Screening follow-up can't see follow-up written only in a note, or a referral made for it (a referral's reason is free text). Dismiss the item with a reason.
- The PHQ-4's 0 to 12 total isn't followed up on its own: its authors give it no cut-off for screening, only one for each part.
- Claims follow-up shows how long ago a claim was denied rather than an appeal deadline, since those differ by payer. On 8.4.1 and later, a line OpenEMR refused to post is found only through the insurance it left open; the remittance itself names the line.
- At most 300 rows are shown at once, most urgent first.
- A tab's count, and its rows, are collected from every outstanding item it has, so its time and memory grow with them. In testing, 61,430 open claims took 4.8 seconds and about 250 MB, under OpenEMR's usual 512 MB memory limit. A site with well over 100,000 claims open in the 365-day window would pass it on the claims tab.
- The counts on tabs other than the one shown can be up to 10 minutes old; see [Tab counts](#tab-counts).

## Other modules for OpenEMR

From the same project. Each installs with `composer require anun333/<module>` and is then enabled in Modules › Manage Modules.

| Module | What it does |
|---|---|
| [Clinic Forms](https://github.com/anun333/oe-module-clinic-forms) | Printable clinic paperwork: consents, privacy notices, referral letters and validated screening questionnaires, with the clinic's own details, in English and Spanish |
| [UDS Report](https://github.com/anun333/oe-module-uds-report) | HRSA's Uniform Data System report for health centers: the patient, visit and diagnosis tables, eCQM readiness, a Table 9D worksheet and grant evidence |
| [Sliding Fee Discount](https://github.com/anun333/oe-module-sliding-fee) | HRSA's sliding fee discount program: the schedule checked against Chapter 9, income assessments, and the discount applied |
| [Care Management](https://github.com/anun333/oe-module-care-management) | CCM, PCM and BHI enrollment with CMS's consent elements, monthly time logging, and the codes each month meets |
| [Migration](https://github.com/anun333/oe-module-migration) | Records from another EHR, read from the FHIR export certified EHRs offer, checked before import and safe to rerun |
| [Layout Export](https://github.com/anun333/oe-module-layout-export) | Layouts and lists exported to files you can keep in version control, compared with the site or another file; reads the old Backup page's SQL files without running them |

## License

GPL-3.0-or-later, as OpenEMR. See [LICENSE](LICENSE).

The screening and social needs tabs read questionnaires by their LOINC codes, and this README names them:

> This material contains content from LOINC (http://loinc.org). LOINC is copyright © Regenstrief Institute, Inc. and the Logical Observation Identifiers Names and Codes (LOINC) Committee and is available at no cost under the license at http://loinc.org/license. LOINC® is a registered United States trademark of Regenstrief Institute, Inc.
