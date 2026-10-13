# Changelog

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
