-- Staff Worklist sizing profile for an existing OpenEMR database.
--
-- Read-only: every statement is a SELECT. Output is clinic-wide counts only; no names,
-- dates of birth, record numbers or other patient-level values are returned, so the
-- result can be shared to size the worklist. Run it as a read-only database user:
--
--   mysql -u <readonly_user> -p <openemr_db> --table < profile-counts.sql
--
-- Each row: section | metric | value.

SELECT section, metric, value FROM (

  SELECT 1 AS ord, 'install' AS section, 'patients' AS metric, COUNT(*) AS value FROM patient_data
  UNION ALL SELECT 2, 'install', 'active facilities', COUNT(*) FROM facility WHERE COALESCE(inactive, 0) = 0
  UNION ALL SELECT 3, 'install', 'inactive facilities', COUNT(*) FROM facility WHERE inactive = 1
  UNION ALL SELECT 4, 'install', 'active login users', COUNT(*) FROM users WHERE active = 1 AND COALESCE(username, '') <> ''

  -- Results waiting for a clinician's signature (the "Results to sign" tab), by how long they have waited.
  UNION ALL SELECT 10, 'results', 'orders with unsigned results', COUNT(DISTINCT pr.procedure_order_id)
    FROM procedure_report pr
   WHERE COALESCE(pr.review_status, '') <> 'reviewed' AND COALESCE(pr.report_status, '') NOT IN ('cancel', 'error')
  UNION ALL SELECT 11, 'results', '  of which reported in the last 3 days', COUNT(DISTINCT pr.procedure_order_id)
    FROM procedure_report pr
   WHERE COALESCE(pr.review_status, '') <> 'reviewed' AND COALESCE(pr.report_status, '') NOT IN ('cancel', 'error')
     AND pr.date_report >= NOW() - INTERVAL 3 DAY
  UNION ALL SELECT 12, 'results', '  of which 4-30 days old', COUNT(DISTINCT pr.procedure_order_id)
    FROM procedure_report pr
   WHERE COALESCE(pr.review_status, '') <> 'reviewed' AND COALESCE(pr.report_status, '') NOT IN ('cancel', 'error')
     AND pr.date_report < NOW() - INTERVAL 3 DAY AND pr.date_report >= NOW() - INTERVAL 30 DAY
  UNION ALL SELECT 13, 'results', '  of which 31-365 days old', COUNT(DISTINCT pr.procedure_order_id)
    FROM procedure_report pr
   WHERE COALESCE(pr.review_status, '') <> 'reviewed' AND COALESCE(pr.report_status, '') NOT IN ('cancel', 'error')
     AND pr.date_report < NOW() - INTERVAL 30 DAY AND pr.date_report >= NOW() - INTERVAL 365 DAY
  UNION ALL SELECT 14, 'results', '  of which over a year old (legacy backlog)', COUNT(DISTINCT pr.procedure_order_id)
    FROM procedure_report pr
   WHERE COALESCE(pr.review_status, '') <> 'reviewed' AND COALESCE(pr.report_status, '') NOT IN ('cancel', 'error')
     AND pr.date_report < NOW() - INTERVAL 365 DAY
  UNION ALL SELECT 15, 'results', '  of which have a panic value (vhigh/vlow)', COUNT(DISTINCT pr.procedure_order_id)
    FROM procedure_report pr JOIN procedure_result ps ON ps.procedure_report_id = pr.procedure_report_id
   WHERE COALESCE(pr.review_status, '') <> 'reviewed' AND COALESCE(pr.report_status, '') NOT IN ('cancel', 'error')
     AND ps.abnormal IN ('vhigh', 'vlow')
  UNION ALL SELECT 16, 'results', 'results ever signed (review_status reviewed)', COUNT(*)
    FROM procedure_report WHERE review_status = 'reviewed'
  UNION ALL SELECT 17, 'results', '"Lab Results" messages still New in Messages', COUNT(*)
    FROM pnotes WHERE title = 'Lab Results' AND message_status = 'New' AND deleted = 0

  -- Orders with no final result after the follow-up window ("No result yet" tab; routine window 7 days).
  UNION ALL SELECT 20, 'orders', 'open orders older than 7 days with no result at all', COUNT(*)
    FROM procedure_order po
   WHERE po.activity = 1 AND COALESCE(po.order_status, '') NOT IN ('complete', 'canceled')
     AND COALESCE(po.history_order, '0') <> '1' AND po.date_ordered < NOW() - INTERVAL 7 DAY
     AND NOT EXISTS (SELECT 1 FROM procedure_report pr WHERE pr.procedure_order_id = po.procedure_order_id)
  UNION ALL SELECT 21, 'orders', 'orders not linked to any encounter', COUNT(*)
    FROM procedure_order po WHERE COALESCE(po.encounter_id, 0) = 0

  -- Referrals: whether staff record replies at all decides if the "Referral replies" tab is usable.
  UNION ALL SELECT 30, 'referrals', 'referrals (LBTref) in the last 2 years', COUNT(*)
    FROM transactions t JOIN lbt_data d ON d.form_id = t.id AND d.field_id = 'refer_date'
   WHERE t.title = 'LBTref' AND d.field_value >= DATE_FORMAT(NOW() - INTERVAL 2 YEAR, '%Y-%m-%d')
  UNION ALL SELECT 31, 'referrals', '  of which have a reply date recorded', COUNT(*)
    FROM transactions t
    JOIN lbt_data d ON d.form_id = t.id AND d.field_id = 'refer_date'
    JOIN lbt_data r ON r.form_id = t.id AND r.field_id = 'reply_date' AND r.field_value <> ''
   WHERE t.title = 'LBTref' AND d.field_value >= DATE_FORMAT(NOW() - INTERVAL 2 YEAR, '%Y-%m-%d')
  UNION ALL SELECT 32, 'referrals', 'unfiled documents (no patient) older than 2 days', COUNT(*)
    FROM documents WHERE COALESCE(foreign_id, 0) = 0 AND COALESCE(deleted, 0) = 0 AND date < NOW() - INTERVAL 2 DAY

  -- Access controls that change what each user may see.
  UNION ALL SELECT 40, 'access', 'setting: restrict users to facilities (1 = on)', COALESCE(MAX(CASE WHEN gl_value = '1' THEN 1 ELSE 0 END), 0)
    FROM globals WHERE gl_name = 'restrict_user_facility'
  UNION ALL SELECT 41, 'access', 'users with facility assignments', COUNT(DISTINCT table_id)
    FROM users_facility WHERE tablename = 'users'
  UNION ALL SELECT 42, 'access', 'encounters marked high sensitivity', COUNT(*)
    FROM form_encounter WHERE sensitivity = 'high'
  UNION ALL SELECT 43, 'access', 'encounters with another non-blank, non-normal sensitivity', COUNT(*)
    FROM form_encounter WHERE COALESCE(sensitivity, '') NOT IN ('', 'normal', 'high')
  UNION ALL SELECT 44, 'access', 'orders placed in high sensitivity encounters', COUNT(*)
    FROM procedure_order po JOIN form_encounter fe ON fe.pid = po.patient_id AND fe.encounter = po.encounter_id
   WHERE fe.sensitivity = 'high'

  -- Patient portal: results show there before anyone signs them.
  UNION ALL SELECT 50, 'portal', 'setting: patient portal enabled (1 = on)', COALESCE(MAX(CASE WHEN gl_value = '1' THEN 1 ELSE 0 END), 0)
    FROM globals WHERE gl_name = 'portal_onsite_two_enable'
  UNION ALL SELECT 51, 'portal', 'patients who can log in to the portal', COUNT(*)
    FROM patient_data pd
   WHERE pd.allow_patient_portal = 'YES'
     AND EXISTS (SELECT 1 FROM patient_access_onsite pao WHERE pao.pid = pd.pid AND COALESCE(pao.portal_username, '') <> '')

  -- Care gaps are computed per scheduled patient, so the schedule size drives background load.
  UNION ALL SELECT 60, 'schedule', 'patients with a visit today or in the next 2 days', COUNT(DISTINCT e.pc_pid)
    FROM openemr_postcalendar_events e
   WHERE e.pc_eventDate BETWEEN CURDATE() AND CURDATE() + INTERVAL 2 DAY
     AND e.pc_recurrtype = 0 AND COALESCE(e.pc_apptstatus, '') NOT IN ('x', '%', '?', '!')
     AND COALESCE(e.pc_pid, '') NOT IN ('', '0')
  UNION ALL SELECT 61, 'schedule', 'deceased patients with open orders', COUNT(DISTINCT po.patient_id)
    FROM procedure_order po JOIN patient_data pd ON pd.pid = po.patient_id
   WHERE pd.deceased_date IS NOT NULL AND pd.deceased_date >= '1000-01-01'
     AND po.activity = 1 AND COALESCE(po.order_status, '') NOT IN ('complete', 'canceled')
) AS profile
ORDER BY ord;
