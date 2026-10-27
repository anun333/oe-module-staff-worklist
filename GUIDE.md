# Staff Worklist: staff guide

For the staff who work the list every day: clinicians, the front desk, billing staff and care coordinators. How items are chosen, who can see what, and how to install it are in the [README](README.md).

The Worklist is one list of outstanding work across all patients. It only reads OpenEMR: you do the work in OpenEMR's own screens, and the item leaves the list when that work is recorded.

## Everyone

### Open the Worklist

*Where:* **Worklist** in the main menu, next to Messages.

It opens on **Mine** and **Open**.
- **Mine**, **Unassigned** or **Everyone**: whose work. Mine is work assigned to you, or yours as its owner (the ordering provider, say) when nobody else is assigned. Unassigned is work with nobody, such as claims to follow up.
- **Open**, **Snoozed** or **Dismissed**: which items.
- **Sites**: tick sites and choose **Apply**, or **All sites**. Your choice is remembered.
- The tabs: **All**, then one for each kind of work, with counts, including tabs other modules add (each is described below under the staff it serves). You see only the tabs your permissions allow.
  - The tab you're on is counted when the page opens. The others may show a count from up to 10 minutes ago: hover over one to see when it was counted, and the line under the list gives the oldest. **…** means the tab is being counted.
  - The numbers in the **Sites** menu are for the tab you're on.

Each row shows its **Priority** (**Critical**, **High** or **Normal**), the **Patient**, the **Site**, the **Work**, how long it has been **Waiting**, and who it is **Assigned** to. Click the patient's name to open the chart. The button on the right opens the screen where the work is done.

*What it doesn't do:* it shows at most 300 rows, most urgent first; pick a tab or **Mine** to see the rest. It never changes the chart, and it notifies nobody.

### Assign, snooze, dismiss or reopen an item

*Where:* the small arrow button beside the row's main button. You need permission to change patient records.

- **Assign**: choose someone in **Assign to**, or **Nobody (use the item owner)**, then **Save**.
- **Snooze**: choose a date in **Hide until**, up to 30 days ahead. The item comes back that day.
- **Dismiss**: give a **Reason (required)**, such as "addressed in the visit note". The dismissal holds only that problem: if the same claim, authorization or assessment later shows a different one (a claim file not sent after a denial, units used up after "ends soon", "ran out" after "runs out soon"), it comes back on the list.
- **Reopen**: from the **Snoozed** or **Dismissed** view.

*What it doesn't do:* these change only the Worklist, never the chart, and each is logged with who and when. Assigning doesn't tell the person. Unsigned results can't be dismissed, and can be snoozed for 3 days at most. **Critical** results can't be snoozed.

### When a row says Restricted

A row from a visit marked sensitive, at a level you don't hold, shows **Restricted**. Its details are hidden and it has no work button. You can only **Assign** it, to someone who holds that level.

## Clinicians

### Sign results

*Where:* the **Results to sign** tab. Results go to the ordering provider.

1. Choose **Review and sign**. OpenEMR's results page opens in a new window.
2. Sign the result there and close the window. The Worklist reloads without the row.

*What you see:* a panic value is **Critical**. Unsigned for 4 hours it is also **Overdue**, and a red banner gives the count, with **Show all results to sign**. A result flagged abnormal, or unsigned for more than 3 days, is **High**. **Patient can already see this in the portal** means the patient may read it before you call, so contact them first.

*What it doesn't do:* only signing clears a result. Overdue critical results are flagged on this page only: nobody is paged. Signing a preliminary result marks the order complete, so the Worklist stops waiting for the final one.

### Chase orders with no result

*Where:* the **No result yet** tab.

An order is listed when it has no final result after 7 days, or 1 day for stat, ASAP, urgent or high. **View order** shows it. The row leaves when a result arrives, or the order is completed or cancelled. If the test won't happen, cancel the order, or dismiss the row with a reason.

*What it doesn't do:* an order placed outside a visit takes the ordering provider's default facility as its site.

### Follow up a screen

*Where:* the **Screening follow-up** tab.

A row is a patient's latest PHQ-2, PHQ-4, PHQ-9, GAD-7 or AUDIT-C from the last 180 days, at or above its published cut-off, with nothing recorded after it. An unfinished screen that could still reach the cut-off is listed too. Each row shows the score, the cut-off, its source, and who recorded it when. A severe PHQ-9 or GAD-7 is **High**.

1. Choose **Open chart**, and act as your protocol says.
2. The row leaves when a later screen of the same kind is recorded, or a problem or visit diagnosis for the condition is added, or for alcohol a brief intervention is billed.
3. If the follow-up is only in a note, or is a referral, dismiss the row with a reason.

*What it doesn't do:* it never calls a screen positive or suggests a diagnosis or treatment. It doesn't flag suicide risk (PHQ-9 question 9, the ASQ): act on those at the visit.

### Prepare for upcoming visits

*Where:* the **Care gaps, upcoming visits** tab.

Patients with a visit in the next 2 days whose clinical reminders are due. **Open chart** to prepare. **Care gaps checked** says when they were last checked; **Check now** checks again.

On a multisite install running OpenEMR 8.2 to 8.4, the background check runs only against the default site, so on any other site the gaps update only when someone uses **Check now**. The strip says so there when the checks are more than a day behind. OpenEMR 8.5.0 fixed it.

*What it doesn't do:* it looks only at non-recurring appointments, each patient's next visit, and up to 500 patients. It lists every active rule's reminders, including any the chart hides from you.

### Get replies to referrals

*Where:* the **Referral replies** tab, for staff allowed to open referrals.

An outgoing referral is listed 14 days after it was sent if it still has no reply date. **Record reply** opens the chart, then the referral. Enter the reply date on its Counter-Referral tab, and the row leaves.

*What it doesn't do:* it can't open the Counter-Referral tab for you.

### Review treatment plans when due

With the Behavioral Health module installed, its "Treatment plan reviews" tab lists each treatment plan whose review is due soon or overdue, owned by the clinician. Recording the review on the patient's treatment plan page clears the row. That module's guide describes the review.

## Front desk

### Book evaluations for new requests

With the CCBHC Report module installed, its "Requests for services" tab lists each logged request for services with no initial evaluation yet, with the business days since it came in. One past its time (10 business days for a routine request, 1 for an urgent one) is high priority. Book the evaluation; recording the evaluation visit clears the row.


### Re-assess sliding fee patients

*Where:* the **Sliding fee re-assessments** tab, when the Sliding Fee Discount module is installed. Take rows from **Unassigned**.

Patients seen in the last 12 months whose assessment runs out within 30 days, has run out, or who were never assessed. **Assess** opens the patient's sliding fee page. The row leaves when a new assessment is recorded.

### Watch prior authorizations

*Where:* the **Prior authorizations** tab, when OpenEMR's Prior Authorizations module is enabled. Take rows from **Unassigned**.

An authorization in force is listed when its units are used up (**High**), it ends within 14 days, or a tenth or less of its units are left. **Open chart**, then record the new authorization in the Prior Authorizations module. Each authorization is checked on its own: the old row leaves when that authorization ends, so once the new one is recorded, dismiss the old row with a reason such as "renewed".

*What it doesn't do:* these rows have no site (**No site**). If your account is limited to some facilities, you see them only once someone assigns them to you.

## Billing staff

### Work claims follow-up

*Where:* the **Claims follow-up** tab, for staff who can use OpenEMR's Billing Manager. Take rows from **Unassigned**.

One row per visit, for claims billed or denied in the last 365 days:
- denied (**High**);
- a claim file OpenEMR's automatic SFTP upload didn't send to the clearinghouse (**High**), or one still waiting to be sent after a day;
- billed over 45 days ago with nothing posted since (**High** after 90 days);
- a line written off with a denial reason (**High**);
- a remittance posted over 30 days ago that left the insurance open: a line OpenEMR couldn't post, a payment the payer reversed, or a line the payer pended for review;
- a line flagged for follow-up in payment posting.

Each row shows the payer, service date, denial reasons and follow-up note. **Open posting** opens OpenEMR's posting page for the visit. The row leaves when the claim is billed again, the insurance level is closed, something is posted at that level, or the flag is unticked. A write-off stays until you dismiss it.

For a claim file that wasn't sent, the row shows the upload's status, the file and the first error, and **Open claim file tracker** opens OpenEMR's Claim File Tracker, where the file can be checked and sent again. Fix the cause first (the clearinghouse login or folder in the X12 partner's settings, usually). The row leaves once a later upload of the claim succeeds. One still waiting after a day usually means OpenEMR's SFTP background service isn't running. Before OpenEMR 8.5.0 a file whose upload itself failed was recorded as sent, so there only the earlier failures (settings, login, the remote folder) show.

A denial recorded again because the same remittance was posted twice keeps its first date, and stays dismissed if you dismissed it.

*What it doesn't do:* it shows how long ago a claim was denied, not an appeal deadline, since those differ by payer.

### Check charges against the record

With the Revenue Cycle module installed, its "Charges to check" tab lists visits where the charges and the chart disagree: a service documented but not billed, or a line billed with nothing behind it. Fix the fee sheet and the row clears the next time the tab is read; if the finding is wrong, dismiss it with a reason. That module's guide describes each check.

## Care coordinators

### Follow up a social needs screen

*Where:* the **Social needs follow-up** tab. Take rows from **Unassigned**.

A patient's latest positive social needs screen of a kind, from the last 180 days: OpenEMR's SDOH history, a Hunger Vital Sign, or a six-item food security survey. A safety need is **High**. The row names the needs still open.

1. Choose **Open chart**.
2. Make a referral in Transactions, dated on or after the screen. For food, housing, utilities and financial strain, a service billed at a visit also clears the row, once the UDS Report module has fetched its value sets. A later negative screen of the same kind clears it too.

*What it doesn't do:* transportation, safety, social isolation, childcare and digital access have no billed service that clears them: only a referral, a later negative screen or a dismissal does.

### Care management months not billed

With the Care Management module installed, its "Care management, not billed" tab lists months that met a code and weren't billed, owned by the billing practitioner. That module's guide describes it.
