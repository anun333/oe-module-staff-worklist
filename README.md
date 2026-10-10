# Staff Worklist for OpenEMR

One queue of outstanding staff work across all patients, so nobody has to check four separate reports.

**Status: 0.1.0, a pre-release.** Tested on OpenEMR with synthetic data only (see [Compatibility](#compatibility)). It lists many patients' work on one page, so read [Access](#access) and [FOOTPRINT.md](FOOTPRINT.md) before installing, and check access with your own users first.

| Tab | Listed when | Leaves the list when | Default owner |
|---|---|---|---|
| Results to sign | A result was received and nobody has signed it | A clinician signs it (Procedures › results › **Sign Results**) | Ordering provider |
| No result yet | An order has no final result after 7 days (1 day for stat, ASAP, urgent or high) | A result arrives, or the order is completed or cancelled | Ordering provider |
| Referral replies | An outgoing referral has no **Reply Date** after 14 days | Someone enters the reply date on the referral | Referring provider |
| Care gaps, upcoming visits | A patient with a visit in the next 2 days has clinical reminders due | The visit passes or is cancelled, or the reminders are met | Visit provider |

Work items are not stored. They're recomputed from OpenEMR data, and the worklist only reads them. Staff do the work in OpenEMR's own screens (the row button opens the right one), and the item disappears when that work is recorded.

## Priority

- **Critical**: a result with a panic value (`vhigh` / `vlow`).
- **High**: a result flagged abnormal, any result unsigned for more than 3 days, an overdue urgent order, an order overdue by twice its window, and a referral that's high risk or more than 28 days old.
- **Normal**: everything else, including care gaps. Care gaps are visit prep, not an open safety loop.

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

How that has been checked:

- The rules are unit-tested (`tests/ComplianceRulesTest.php`, `tests/ActionRulesTest.php`): sensitivity masking, which actions masked items allow, site counts, snooze and dismiss limits.
- The end-to-end check with restricted users on a running OpenEMR has **not yet been done for 0.1.0**. Until it has, check these in your own install before giving staff access:
  1. A user without `patients/med` gets no menu entry and an access-denied page at the worklist URL.
  2. A user with `patients/med` but not write sees the list, and gets no Assign, Snooze or Dismiss buttons; posting an action anyway is refused.
  3. A user without the "High" sensitivity sees a result from a High-sensitivity visit as "Restricted", can't open it, and can only assign it to someone who holds that level.
  4. With Restrict Users to Facilities on, a user limited to one facility sees only that facility's work, plus work they own or were assigned, and can't assign work to someone restricted elsewhere.
  5. Each worklist view appears in Admin › Logs as `patient-record-select`, with the patients listed.

## Compatibility

Tested with synthetic data on:

<!-- COMPATIBILITY: filled in after the install checks -->

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
- Sensitivity masking applies to results and orders. Referrals and care gaps aren't tied to a visit, so they have no sensitivity to apply.
- At most 300 rows are shown at once, most urgent first.

## License

GPL-3.0-or-later, as OpenEMR. See [LICENSE](LICENSE).
