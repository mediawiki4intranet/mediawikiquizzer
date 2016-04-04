--
-- Add 'test is secret' field to test table
-- (c) Vitaliy Filippov, 2016
--
-- You should not have to apply this patch manually.
-- In normal conditions, maintenance/update.php should perform any needed database setup.
--

ALTER TABLE /*$wgDBprefix*/mwq_ticket ALTER tk_start_time drop not null, ALTER tk_start_time set default null;
ALTER TABLE /*$wgDBprefix*/mwq_test ADD test_secret smallint not null default 0;
