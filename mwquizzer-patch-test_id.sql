--
-- Changes test IDs from varchar(32) to auto-incremented int (MySQL).
-- (c) Vitaliy Filippov, 2013
--
-- You should not have to apply this patch manually.
-- In normal conditions, maintenance/update.php should perform any needed database setup.
--

SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;

ALTER TABLE /*$wgDBprefix*/mwq_question_test DROP FOREIGN KEY /*$wgDBprefix*/mwq_question_test_ibfk_1;
ALTER TABLE /*$wgDBprefix*/mwq_ticket DROP FOREIGN KEY /*$wgDBprefix*/mwq_ticket_ibfk_1;

ALTER TABLE /*$wgDBprefix*/mwq_test ADD test_page_title varchar(255) binary not null AFTER test_id;
SET @i = 1;
UPDATE      /*$wgDBprefix*/mwq_test SET test_page_title=test_id, test_id=(@i := @i+1);
ALTER TABLE /*$wgDBprefix*/mwq_test CHANGE test_id test_id int unsigned not null auto_increment;
ALTER TABLE /*$wgDBprefix*/mwq_test ADD UNIQUE KEY (test_page_title);

UPDATE      /*$wgDBprefix*/mwq_question_test, /*$wgDBprefix*/mwq_test SET qt_test_id=test_id WHERE qt_test_id=test_page_title;
ALTER TABLE /*$wgDBprefix*/mwq_question_test CHANGE qt_test_id qt_test_id int unsigned not null;

UPDATE      /*$wgDBprefix*/mwq_ticket, /*$wgDBprefix*/mwq_test SET tk_test_id=test_id WHERE tk_test_id=test_page_title;
ALTER TABLE /*$wgDBprefix*/mwq_ticket CHANGE tk_test_id tk_test_id int unsigned not null;
ALTER TABLE /*$wgDBprefix*/mwq_ticket ADD tk_details blob DEFAULT NULL AFTER tk_displayname;

ALTER TABLE /*$wgDBprefix*/mwq_question_test ADD CONSTRAINT /*$wgDBprefix*/mwq_question_test_ibfk_1 FOREIGN KEY (qt_test_id) REFERENCES /*$wgDBprefix*/mwq_test (test_id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE /*$wgDBprefix*/mwq_ticket ADD CONSTRAINT /*$wgDBprefix*/mwq_ticket_ibfk_1 FOREIGN KEY (tk_test_id) REFERENCES /*$wgDBprefix*/mwq_test (test_id) ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
