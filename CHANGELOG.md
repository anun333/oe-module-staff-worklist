# Changelog

## 0.8.7 (unreleased)

- **Fixed: upgrading from 0.8.5 or older no longer carries a dismissal onto a problem that began after it.** The first time 0.8.6 listed a claims, prior authorization or sliding fee tab, a 0.8.5 dismissal moved to whatever problem the record showed then, so a denial dismissed in September hid the "claim file not sent" that followed it, once. Now a dismissal moves only when the problem shown began before it was dismissed (the item's date is no later than the dismissal); otherwise its assignee and note move and the item opens, logged as `rekey-reopen`. A dismissal with no time on record opens too. Sites already on 0.8.6 have carried their state already; this applies to sites upgrading from 0.8.5 or older.
- **The look for 0.8.5 keys stops.** It ran on every worklist build. Now it ends for good once no 0.8.5 rows are left, and 30 days after it first ran any still left (a tab nobody's list includes) are dropped, so their items show again; both are recorded in the log under `_legacy_keys`.
- **The page declares the user's language.** It said `lang="en"` whatever OpenEMR's language was, so a screen reader read Spanish text with English pronunciation (WCAG 3.1.1). It now takes the user's OpenEMR language and direction from openhealthcare-common 1.4.0, now required, and says English, left to right, if an older copy of the library is the one loaded.
- **README:** LOINC's notice (the tabs read questionnaires by LOINC code); the floors stated in one line, OpenEMR 8.1.0 and PHP 8.2; and a note that every module copied in by hand must carry the same version of openhealthcare-common.
- **Checked:** unit tests on PHP 8.2 and 8.5 (187; 8 new, of which the 7 that test the change fail on 0.8.6's code), including the fallback when an older library is loaded; on OpenEMR 8.4.1, the claims, prior authorization and sliding fee end-to-end checks, a planted 0.8.5 dismissal made before its assessment ran out reopening with its assignee and note (logged `rekey-reopen`) and one made after staying dismissed, the scan ending on the next build, and the page rendering with its language declared. CI now also runs PHP 8.2.

## 0.8.6 (2026-10-26)

- **Fixed: dismissing one problem on a record no longer hides a later, different problem on it.** A dismissal was kept for as long as the item's key existed, and three tabs used one key for every problem a record could have. So a dismissed denial hid the "claim file not sent" (or the next denial, partial posting or no response) on that visit; dismissing "ends within 14 days" hid "units used up"; dismissing "runs out soon" hid "ran out". Each of those items' keys now names its problem, as screening items already did, and a claim's key also names the claim version, so a claim billed again and denied again comes back. The same problem stays dismissed.
- **Upgrading keeps what staff recorded.** The first time 0.8.6 lists one of these tabs, each assignment, note, snooze or dismissal saved under a 0.8.5 key moves to the item that record shows then, and the 0.8.5 row goes, so it never carries onto a later problem. A row whose record lists nothing any more is dropped. Both are written to the log (`rekey`, `drop`). A tab a user can't see is left until someone who can lists it.
- **PHP 8.2 or later.** composer.json asked for PHP 8.3, while OpenEMR 8.1.0, the module's floor, runs on 8.2; every file and test runs on 8.2, so it now asks for 8.2.
- **Checked:** unit tests on PHP 8.2 and 8.5 (179: 13 new, and one old case dropped, since a dotted prior authorization key is now valid; the 8 that test the change fail on 0.8.5's code, 7 errors and 1 failure); the claims, prior authorization and sliding fee end-to-end checks on OpenEMR 8.4.1, each now dismissing one problem and then showing the next (all pass; on 0.8.5's code, 7 steps fail, the later problem staying hidden); the carry-over on the same stack, with a 0.8.5 dismissal moved to its new key with its assignee and note, a row for nothing listed dropped, both logged, and a second load moving nothing.

## 0.8.5 (2026-10-26)

- **Claim files that never reached the clearinghouse are listed.** With OpenEMR's "Auto SFTP claims to X12 partner" on, each batch file is queued for upload and its outcome recorded in core's `x12_remote_tracker`. The **Claims follow-up** tab now lists a billed claim whose latest upload failed (the partner's settings, the local file, the login, the remote folder, or from OpenEMR 8.5.0 the upload itself), **High**, ahead of "no response", which it explains; and one whose file has been waiting, or stuck mid-upload, for more than a day, which usually means the SFTP background service isn't running. A later successful upload of the claim clears it. The row shows the status, file and first error, and its button opens OpenEMR's Claim File Tracker. Before 8.5.0 a failed upload itself was recorded as sent (openemr/openemr#14336), so there only the earlier failures show.
- **Multisite on OpenEMR 8.2 to 8.4:** there a background service for a site other than `default` ran against the default site (openemr/openemr#14359, fixed in 8.5.0), so this module's care gaps job never refreshed another site's patients. The care gaps strip now says so on such a site when its checks are more than a day behind, and points to **Check now**; the README and the staff guide explain it.
- **Checked:** unit tests on PHP 8.4 (167; 12 new, 11 of which fail on 0.8.4's code); the claims tab's end-to-end check on OpenEMR 8.4.1 with synthetic tracker rows (a failed login for two claims, a later successful resend of one, a file waiting 30 hours), whose six new steps fail on 0.8.4 and pass here.

## 0.8.4 (2026-10-26)

- **Text and buttons are easier to read on this module's pages.** OpenEMR's blue (#007bff) is 3.97:1 on white, under the 4.5:1 that WCAG 2.1 AA asks of normal text and that HHS's Section 504 rule requires from 2027-05-11. Under OpenEMR's light and manila themes, the module's links and primary buttons now use #0056b3 (7.0:1), and muted and warning text darker shades that stay readable on tinted rows, and a link inside text is underlined; the dark, solar and colour themes keep their own colours, and OpenEMR's own pages are unchanged.

## 0.8.3 (2026-10-25)

- **Fixed: on OpenEMR 7.0.4 and 8.0.0 to 8.0.0.3, enabling this module stopped every OpenEMR page, the login page included (HTTP 500).** Those releases have `OEGlobalsBag` without the getters its bootstrap called, which came with 8.1.0. The module needs 8.1.0 or later (its pages use 8.1's session and service container), so on an older release it now stays switched off, as it already did before 7.0.4. Its pages ask openhealthcare-common 1.3.3, now required at runtime, for the web root and OpenEMR's folders. Checked on fresh installs of the official 7.0.2, 7.0.4 and 8.0.0 images with every module enabled, and on 8.4.1 and master.
- **A screen saved before OpenEMR 8.5.0 shows its visit's site and provider again.** Before 8.5.0, OpenEMR stored a questionnaire's visit number wrong (only the leading digits of the visit's id, or 0; fixed in core by #14654, and rows saved before aren't repaired), so screening follow-up and social needs rows from those saves came with no site or provider. The visit is now read from the questionnaire form's own record, which was always right, and from the response only when it has no form.
- **A draft no longer counts as a finished screen.** OpenEMR's questionnaire runtime 0.4.0 (in 8.5.0 and later) saves a submitted response as `completed`, or `amended` when edited after; before it, every response was saved `in-progress`. Until a site has its first `completed` or `amended` response, every response counts, as before. After it, responses saved earlier count whatever their status, and later ones only when `completed` or `amended`. `entered-in-error` never counts. The rule is openhealthcare-common 1.3.0's `QuestionnaireStatus`, shared with the other modules that read screens. The rule applies only where the site's runtime is 0.4.0 or later (openhealthcare-common 1.3.3): on an older one, which saves every response in-progress, every response but entered-in-error counts, as before, even when an API client has written a completed one.
- **Help:** a **Help** link on the page opens the staff guide (GUIDE.md) for the installed version.
- **Other modules' tables:** the Sliding Fee tab also checks that the Sliding Fee Discount module's tables have the columns it reads, and social needs checks UDS Report's value set table the same way, so a version of either without them leaves that part off instead of failing the page.
- **Installs:** openhealthcare-common (^1.3) is now installed with the module; before, it was a development dependency only. Composer puts it in OpenEMR's vendor folder with the module.
- **Checked:** unit tests on PHP 8.3 and 8.5 (152), including a new one that every label the guide quotes in bold is on the page (51 labels); FOOTPRINT.md written again (it now names the Sliding Fee and UDS Report tables as those modules').

## 0.8.2 (2026-10-23)

- Development: the test for translated strings with an apostrophe or quote mark uses [openhealthcare-common](https://github.com/anun333/openhealthcare-common)'s scan, the same one every openhealthcare module runs. It is a development dependency only; nothing installed changes.
- **Accessibility:** the page declares its language (`<html lang>`).
- **README:** run OpenEMR on a PHP with PHP's php2shell fix (2026-10-21: 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later). The official OpenEMR 7.0.2 and 7.0.3 Docker images can't get it.
- **FOOTPRINT.md** now comes from openhealthcare-common's footprint tool (1.1), the one every module shares; `tools/footprint.php` only loads it. The sheet's closing notes, what it can't show, are this module's own (in composer.json). A check that accepts either write or addonly access now shows as one.
- **Checked:** unit tests; the nightly matrix on 8.4.1 and master (the smoke check and each tab's check); fresh official 8.1.0, 8.3.0 and 8.5.0 images, with every module enabled and with it alone (on 7.0.2 and 7.0.3 it stays switched off, as designed).

## 0.8.1 (2026-10-22)

- **No longer stops an older OpenEMR.** OpenEMR loads a module's bootstrap on every request, the login page included. This module's used `OEGlobalsBag`, which came with OpenEMR 7.0.4 and 8.0, so on 7.0.3 or older, enabling it made every page fail (HTTP 500), login included, until the module was switched off in the database. It now stays switched off there instead. Found by the nightly matrix's new fresh 7.0.2 install (`dev/module_compat_check.sh`).

## 0.8.0 (2026-10-22)

- **The PHQ-4, each part on its own.** Screening follow-up reads a PHQ-4's depression part (its PHQ-2) and anxiety part (its GAD-2), each at 3 or more, as its authors score it (Kroenke 2009, PMID 19996233). A later PHQ-9 clears the depression part, and a later GAD-7 the anxiety part. Each is scored from its own two LOINC-coded questions, in a questionnaire with the PHQ-4's panel code (69724-3), as Clinic Forms' PHQ-4 has. LOINC has no code for the parts' totals.
  - Both parts at their cut-off make two rows, each keyed by its part. Other instruments' row keys don't change.
  - Checked with Clinic Forms' own exported PHQ-4 on OpenEMR 8.4.1 and master a10ebfa8d3.
- **Other modules can add a tab.** As the worklist is put together it asks OpenEMR's event dispatcher (`staff_worklist.collect_sources`, `Event\CollectSourcesEvent`) whether another module adds a work source, with its label and the role that owns its rows.
  - **Who sees it:** the module decides, by adding its source only for users it admits.
  - **Row keys:** a contributed source's keys are accepted once it's added, and its id can't take one of the worklist's own.
  - **Care Management 0.3.2 adds "Care management, not billed" this way:** an enrollment whose month, in the last three completed months, met a code with nothing billed. It's read through Care Management's own month list, so the two agree. It's for users who may see billing (`acct/bill`), and is checked within Care Management's end-to-end check on 8.4.1 and master.
- **Not done: a decision clock for pending prior authorizations.** CMS-0057-F's 72-hour and 7-day clocks need a request's date, urgency and status. OpenEMR's Prior Authorizations module records only granted authorizations (number, dates, codes, units), so there's nothing to time.

## 0.7.0 (2026-10-20)

- **New tab: Social needs follow-up.** A patient's latest positive social needs screen of a kind from the last 180 days, with nothing after it.
  - **Screens read:** OpenEMR's SDOH history, by the answers OpenEMR itself treats as positive per domain; the Hunger Vital Sign, either question "Often" or "Sometimes" true, including a translated Clinic Forms version, whose answers are coded by position; and the six-item food security survey, positive at a raw score of 2.
  - **Taken off by:** a referral on or after the screen; for food, housing and financial strain, a service in the UDS module's Table 6A Line 40 to 42 value sets, billed on or after it, once that module has fetched them; or a later negative screen of the same kind.
  - Interpersonal safety is High. The row names the needs still open.
  - It's for users with `patients/med` write or add-only, the check OpenEMR's SDOH history page makes.

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
