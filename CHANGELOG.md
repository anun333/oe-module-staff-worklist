# Changelog

## 0.1.0 (unreleased)

First release, as a standalone Composer package.

- Four queues across all patients: results to sign, orders with no result, referrals with no reply, and care gaps for visits in the next two days.
- Staff actions (assign, snooze, dismiss, reopen) recorded in `staff_worklist_log`; work items themselves are recomputed, never stored.
- Sites, following OpenEMR's Restrict Users to Facilities setting; masking of work from sensitive visits; overdue critical results; a portal-visibility notice on unsigned results; every view audit-logged.
- Care gaps from OpenEMR's rules engine, refreshed by a background service the module switches on when enabled.
- `FOOTPRINT.md`, generated from the source and checked in CI, and `tools/profile-counts.sql` for sizing a clinic without patient-level data.
