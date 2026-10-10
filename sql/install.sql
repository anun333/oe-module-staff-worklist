--
-- Staff Worklist module tables.
--
-- Work items themselves are never stored; they are recomputed from OpenEMR data.
-- These tables hold only what staff record about an item, and the history of it.
--

#IfNotTable staff_worklist_state
CREATE TABLE `staff_worklist_state` (
    `item_key` VARCHAR(191) NOT NULL COMMENT 'source:pid:ref, see ItemKey',
    `pid` BIGINT(20) NOT NULL,
    `assigned_to` INT(11) DEFAULT NULL COMMENT 'users.id; NULL falls back to the item owner',
    `status` VARCHAR(16) NOT NULL DEFAULT 'open' COMMENT 'open, snoozed or dismissed',
    `snooze_until` DATE DEFAULT NULL,
    `note` TEXT,
    `updated_by` INT(11) NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`item_key`),
    KEY `idx_assigned_to` (`assigned_to`),
    KEY `idx_pid` (`pid`)
) ENGINE=InnoDB COMMENT='Staff Worklist: assignment, snooze and dismissal per work item';
#EndIf

#IfNotTable staff_worklist_log
CREATE TABLE `staff_worklist_log` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `item_key` VARCHAR(191) NOT NULL,
    `pid` BIGINT(20) NOT NULL,
    `action` VARCHAR(16) NOT NULL COMMENT 'assign, snooze, dismiss or reopen',
    `assigned_to` INT(11) DEFAULT NULL,
    `status` VARCHAR(16) NOT NULL,
    `snooze_until` DATE DEFAULT NULL,
    `note` TEXT,
    `user_id` INT(11) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_item_key` (`item_key`),
    KEY `idx_pid` (`pid`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB COMMENT='Staff Worklist: every state change, never updated or deleted';
#EndIf

#IfNotTable staff_worklist_caregap_cache
CREATE TABLE `staff_worklist_caregap_cache` (
    `pid` BIGINT(20) NOT NULL,
    `gaps_json` TEXT NOT NULL COMMENT 'list of {item: rule_action option id, due: rule_reminder_due_opt option id}',
    `computed_at` DATETIME NOT NULL,
    PRIMARY KEY (`pid`),
    KEY `idx_computed_at` (`computed_at`)
) ENGINE=InnoDB COMMENT='Staff Worklist: rules-engine reminders for scheduled patients, refreshed in the background';
#EndIf

-- Inactive until the module is enabled; ModuleManagerListener switches it on and off.
#IfNotRow background_services name StaffWorklistCareGaps
INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`)
VALUES ('StaffWorklistCareGaps', 'Staff Worklist care gaps', 0, 0, current_timestamp(), 10, 'staffWorklistRefreshCareGaps', '/interface/modules/custom_modules/oe-module-staff-worklist/background/caregaps.php', 110);
#EndIf
