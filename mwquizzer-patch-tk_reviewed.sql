--
-- Add 'ticket reviewed' field to ticket table
-- (c) Vitaliy Filippov, 2013
--
-- You should not have to apply this patch manually.
-- In normal conditions, maintenance/update.php should perform any needed database setup.
--

ALTER TABLE /*$wgDBprefix*/mwq_ticket ADD tk_reviewed tinyint(1) not null default 0 AFTER tk_details;
