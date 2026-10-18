# Staff Worklist for OpenEMR

One queue of outstanding staff work across all patients, so nobody has to check four separate reports.

**Status: 0.2.0, a pre-release.** Tested on OpenEMR with synthetic data only, access checks included (see [Compatibility](#compatibility)). It lists many patients' work on one page, so read [Access](#access) and [FOOTPRINT.md](FOOTPRINT.md) before installing, and try it with your own users and settings before giving staff access.

| Tab | Listed when | Leaves the list when | Default owner |
|---|---|---|---|
| Results to sign | A result was received and nobody has signed it | A clinician signs it (Procedures › results › **Sign Results**) | Ordering provider |
| No result yet | An order has no final result after 7 days (1 day for stat, ASAP, urgent or high) | A result arrives, or the order is completed or cancelled | Ordering provider |
| Referral replies | An outgoing referral has no **Reply Date** after 14 days | Someone enters the reply date on the referral | Referring provider |
| Care gaps, upcoming visits | A patient with a visit in the next 2 days has clinical reminders due | The visit passes or is cancelled, or the reminders are met | Visit provider |
| Screening follow-up | A patient's latest PHQ-2, PHQ-9, GAD-7 or AUDIT-C total from the last 180 days is at or above the instrument's published cut-off | Something is coded after it: a later response, a problem or visit diagnosis for the condition, or a brief intervention for alcohol. See [Screening follow-up](#screening-follow-up) | Visit provider, else the patient's provider |

Work items are not stored. They're recomputed from OpenEMR data, and the worklist only reads them. Staff do the work in OpenEMR's own screens (the row button opens the right one), and the item disappears when that work is recorded.

## Priority

- **Critical**: a result with a panic value (`vhigh` / `vlow`).
- **High**: a result flagged abnormal, any result unsigned for more than 3 days, an overdue urgent order, an order overdue by twice its window, a referral that's high risk or more than 28 days old, and a screening total in the instrument's published severe band (PHQ-9 20 or more, GAD-7 15 or more).
- **Normal**: everything else, including care gaps and other screening totals. Care gaps are visit prep, not an open safety loop.

Within a priority, the oldest item comes first; care gaps sort by visit time. All thresholds are in `src/Policy/PriorityPolicy.php`.

## Staff actions

These change only what the worklist shows. They never change the chart.

- **Assign** to any active user. Unassigned items count as the owner's.
- **Snooze** until a date. Critical results can't be snoozed, and unsigned results can only be snoozed for up to 3 days.
- **Dismiss** with a required reason. Unsigned results can't be dismissed; only signing them clears them.
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
- **Audit.** Each view of the worklist is written to OpenEMR's audit log, and each assign, snooze, dismiss or reopen to `staff_worklist_log`.

To size the worklist for a real clinic, run the read-only `tools/profile-counts.sql` as a read-only database user. It returns clinic-wide counts only, with no patient details, covering:
- The age of the unsigned-results backlog.
- How often referral replies are recorded.
- Use of sensitive visits and site restrictions.
- How many patients have portal access.

## Screening follow-up

The tab reads the questionnaire responses OpenEMR's own runtime saves, from the encounter form (New Questionnaire) and the patient's Questionnaires page. It lists a patient's latest response to an instrument when its total is at or above the cut-off the instrument's validation paper gives, and nothing is coded after it.

| Instrument | Total read (LOINC) | Listed at | Source | Also cleared by, on or after the screen's day |
|---|---|---|---|---|
| PHQ-2 | 55758-7 | 3 or more | Kroenke 2003, PMID 14583691 | a PHQ-9 response, or what clears a PHQ-9 |
| PHQ-9 | 44261-6 | 10 or more (severe from 20) | Kroenke 2001, PMID 11556941 | a depression problem or visit diagnosis (ICD-10 F32, F33, F34.1) |
| GAD-7 | 70274-6 | 10 or more (severe from 15) | Spitzer 2006, PMID 16717171 | an anxiety problem or visit diagnosis (ICD-10 F41) |
| AUDIT-C | 75626-2 | 4 or more for men, 3 or more for women | Bradley 2007, PMID 17451397 | an alcohol problem or visit diagnosis (ICD-10 F10, Z71.41), or a brief intervention billed (CPT 99408, 99409; HCPCS G0443) |

- **A later response** to the same instrument always replaces an earlier one, so a re-screen under the cut-off clears it.
- **A problem already on the list** for the condition clears it too: the clinician already knows.
- **Follow-up recorded only in a note** can't be seen. Dismiss the item with a reason, such as "addressed in the visit note".
- **Each row shows its basis:** the score, the cut-off and what the paper says it means, the paper, and the date and who recorded it. It also says when a fact was missing; for example, when a patient's sex isn't recorded as male or female, AUDIT-C uses the women's cut-off and the row says so.
- **It never calls a screen positive or suggests a diagnosis or treatment.** Whether a screen is positive under the clinic's protocol, and what to do, is the clinician's decision.
- **The cut-offs** are the ones checked in [Clinic Forms](https://github.com/anun333/oe-module-clinic-forms)' instrument references, against each paper.

What it leaves out:

- **Suicide risk.** PHQ-9 item 9 and the ASQ aren't read. A positive answer needs action at the same visit under the clinic's protocol, and a worklist that people look at days later is the wrong tool for that.
- **Totals that no item is coded for.** LOINC's own imported PHQ-9 leaves its total item uncoded, so those responses aren't read. Clinic Forms' questionnaires (0.25.0 and later) code it.
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

## Care gaps run in the background

OpenEMR's rules engine takes about 150 ms per patient, which is too slow to run when the page loads. The background service **Staff Worklist care gaps** (Admin › Config › Background Services) runs every 10 minutes while someone is logged in or from cron, and caches the results. The schedule itself is read live, so a cancelled visit drops off immediately. The page shows when gaps were last checked, and **Check now** runs the job on demand.

## Install

From the OpenEMR directory:

```
composer require anun333/oe-module-staff-worklist
```

The installer plugin places it in `interface/modules/custom_modules/oe-module-staff-worklist/`. Without Composer, copy this directory there instead.

Then go to Modules › Manage Modules › Unregistered, and **Register**, **Install** and **Enable** it. A **Worklist** menu entry appears next to Messages. Enabling it also switches on the background service for care gaps; disabling switches it off.

What installing it adds and changes, extracted from the source, is in [FOOTPRINT.md](FOOTPRINT.md): three tables of its own, one row in `background_services`, no change to any core table's structure, no outbound network.

## Access

Who sees what:

- **The page** needs `patients/med` (the same as the menu entry). **Changing items** (assign, snooze, dismiss, reopen) needs `patients/med` write. The server checks both, not just the page.
- **Sensitive visits:** see [Safety and privacy behaviour](#safety-and-privacy-behaviour).
- **Sites:** with **Restrict Users to Facilities** on, see [Who can see which sites](#who-can-see-which-sites).

How that has been checked, on 2026-10-10, on OpenEMR 8.4.1 and current master (8.5.0-dev), with synthetic data and test logins created and removed for the run:

- The rules are unit-tested (`tests/ComplianceRulesTest.php`, `tests/ActionRulesTest.php`): sensitivity masking, which actions masked items allow, site counts, snooze and dismiss limits.
- End to end, against a running OpenEMR, the five checks below all passed:
  1. A user without `patients/med` (Front Office) gets no menu entry, a 403 access-denied page at the worklist URL, and an action posted with a valid session token is refused by the permission check.
  2. A user with `patients/med` at view only sees the list, with no assign, snooze, dismiss or reopen controls and no action form; an action posted anyway is refused by the permission check.
  3. A user without the "High" sensitivity (Clinicians) sees a result from a High-sensitivity visit as "Restricted", without its test name, can't snooze it, can't assign it to someone who also lacks that level, and can assign it to someone who holds it.
  4. With Restrict Users to Facilities on, a facility-limited user sees only that facility's work plus work they were assigned, counts include only that work, work at other sites can't be acted on and isn't revealed, and work can't be assigned to someone restricted elsewhere.
  5. Each worklist view appears in Admin › Logs as `patient-record-select`, with the patients listed.

The same five are worth repeating in your own install, with your own groups and facilities, before giving staff access: your ACL setup isn't the stock one the checks used.

## Compatibility

Tested with synthetic data on:

| OpenEMR | install by Composer and Module Manager | queues | access |
|---|---|---|---|
| 8.4.1 (Docker image `openemr/openemr:8.4.1`) | passes (GitHub and Packagist) | passes | passes |
| 8.5.0-dev, master as of 2026-10-09 (Docker image `openemr/openemr:8.5.0`, database version 548) | passes (GitHub) | passes | passes |
| 8.4.0, 8.3.0, 8.2.0, 8.1.0 (official Docker images) | passes (Module Manager) | not tested | not tested |
| 8.0.0 and older | won't run: it uses OpenEMR's `AccessDeniedHelper`, added in 8.1.0 | | |

On 8.1.0, 8.2.0, 8.3.0 and 8.4.0 (checked 2026-10-12 with `dev/module_compat_check.sh` in the openhealthcare repository) the module was copied in, installed and enabled through Module Manager, and its page loaded for an administrator with no PHP error, on a fresh install with no patients. That's a smaller check than the 8.4.1 and master rows. 8.1 and 8.2 need the module to load OpenEMR's `library/user.inc.php` itself, which 8.3 started loading for every page; the page now does. The module needs PHP 8.3 or later, which 8.1 and 8.2 also run on.

**Install** means a fresh OpenEMR that installed itself, then `composer require` from this repository, then Register, Install and Enable in Modules › Manage Modules. Each step's effect was checked: where the files went, the three tables, the background service switching on (and off again when disabled), the menu entry and the permission it requires, the page rendering, and no PHP warnings from the module in Apache's logs.

**Queues** means synthetic patients covering each queue, and the page and action endpoint driven as an administrator. Screening follow-up (0.2.0) is checked on 8.4.1 only, with `dev/worklist_screens_check.py` in the openhealthcare repository. That check covers each instrument at and under its cut-off, each thing that clears a row, the window, a deleted encounter form, and a PHQ-9 filled in OpenEMR's own Questionnaires page. Items appear with the right priority and site, actions work and are logged, signing a result or cancelling a visit clears its items, and the site picker narrows the list. **Access** means the five checks listed under [Access](#access).

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php          # regenerate FOOTPRINT.md after changing the module
php tools/footprint.php --check  # what CI runs
```

The unit tests need no database or OpenEMR install. CI runs them, and the footprint check, on PHP 8.3, 8.4 and 8.5.

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
- Screening follow-up counts responses whatever their status, because OpenEMR's questionnaire runtime saves them all as `in-progress`.
- Screening follow-up can't see follow-up written only in a note, or a referral made for it (a referral's reason is free text). Dismiss the item with a reason.
- The PHQ-4 isn't read, because Clinic Forms' PHQ-4 has no total.
- At most 300 rows are shown at once, most urgent first.

## License

GPL-3.0-or-later, as OpenEMR. See [LICENSE](LICENSE).
