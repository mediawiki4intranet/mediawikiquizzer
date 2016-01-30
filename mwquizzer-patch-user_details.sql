--
-- Add 'user details' field to quiz table
-- (c) Vitaliy Filippov, 2013
--
-- You should not have to apply this patch manually.
-- In normal conditions, maintenance/update.php should perform any needed database setup.
--

ALTER TABLE /*$wgDBprefix*/mwq_test ADD test_user_details blob not null AFTER test_ok_percent;
