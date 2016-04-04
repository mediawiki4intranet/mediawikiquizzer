--
-- Add 'test is secret' field to test table
-- (c) Vitaliy Filippov, 2016
--
-- You should not have to apply this patch manually.
-- In normal conditions, maintenance/update.php should perform any needed database setup.
--

ALTER TABLE /*$wgDBprefix*/mwq_ticket CHANGE tk_start_time tk_start_time binary(14) default null;
ALTER TABLE /*$wgDBprefix*/mwq_test ADD test_secret tinyint(1) not null default 0 AFTER test_autofilter_success_percent;
