--
-- Staff Worklist 0.8.6 to 0.8.7: each user's tab counts (staff_worklist_count), so a page collects only the tab
-- it shows, and an index on form_questionnaire_assessments.response_id for the screening and social needs tabs.
-- The same blocks are in install.sql; they are guarded, so running both changes nothing.
--

#IfNotTable staff_worklist_count
CREATE TABLE `staff_worklist_count` (
    `user_id` BIGINT NOT NULL,
    `filter_key` VARCHAR(191) NOT NULL COMMENT 'view|scope|sites the count was taken for',
    `source` VARCHAR(32) NOT NULL,
    `item_count` INT NOT NULL,
    `counted_at` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`, `filter_key`, `source`),
    KEY `idx_counted_at` (`counted_at`)
) ENGINE=InnoDB COMMENT='Staff Worklist: tab counts per user, kept 10 minutes so a page collects only the tab it shows';
#EndIf

-- The screening and social needs tabs find each response's visit through form_questionnaire_assessments.response_id,
-- which core leaves unindexed (a TEXT column); without an index, every lookup reads the whole table. Other modules
-- add the same index under the same name, so whichever runs first creates it.
#IfNotIndex form_questionnaire_assessments response_id
ALTER TABLE `form_questionnaire_assessments` ADD INDEX `response_id` (`response_id`(64));
#EndIf
