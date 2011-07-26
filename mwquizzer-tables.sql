--
-- Tables used by MediawikiQuizzer extension.
-- (c) Vitaliy Filippov, 2010+
--
-- You should not have to create these tables manually unless you are doing
-- a manual installation. In normal conditions, maintenance/update.php should
-- perform any needed database setup.
--

CREATE TABLE /*$wgDBPrefix*/mwq_test (
  -- test ID
  test_id varchar(32) NOT NULL,
  -- test title
  test_name varchar(255) NOT NULL default '',
  -- brief description of test
  test_intro longblob,
  -- TEST or TUTOR
  test_mode enum('TEST', 'TUTOR') NOT NULL default 'TEST',
  -- randomize choice positions
  test_shuffle_choices tinyint(1) NOT NULL default '0',
  -- randomize question positions
  test_shuffle_questions tinyint(1) NOT NULL default '0',
  -- select only X random questions from test
  test_limit_questions tinyint(4) NOT NULL default '0',
  -- percent of correct answers to pass
  test_ok_percent tinyint(3) NOT NULL default '80',
  -- each variant includes questions shown less than X times ("too new to filter")...
  test_autofilter_min_tries smallint NOT NULL,
  -- ...and questions with correct answer percent greater than Y ("too simple")
  -- but if qt_autofilter_min_tries <= 0 then autofilter is disabled
  test_autofilter_success_percent tinyint NOT NULL,
  -- quiz article parse log
  test_log longblob NOT NULL,
  PRIMARY KEY (test_id)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_question (
  -- question ID (md5 hash of question text)
  qn_hash binary(32) NOT NULL,
  -- question text
  qn_text blob NOT NULL,
  -- correct answer explanation text
  qn_explanation blob default NULL,
  -- arbitrary label to classify questions
  qn_label varbinary(255) default NULL,
  -- HTML anchor of question section inside article
  qn_anchor varbinary(255) NOT NULL default '',
  -- extracted HTML code with edit question section link
  qn_editsection blob default NULL,
  PRIMARY KEY (qn_hash)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_question_test (
  -- test ID
  qt_test_id varchar(32) NOT NULL,
  -- question hash
  qt_question_hash binary(32) NOT NULL,
  -- question index number inside the test
  qt_num int UNSIGNED NOT NULL,
  PRIMARY KEY (qt_test_id, qt_num)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_choice (
  -- question hash
  ch_question_hash binary(32) NOT NULL,
  -- choice index number inside the question
  ch_num int UNSIGNED NOT NULL,
  -- choice text
  ch_text blob NOT NULL,
  -- is this choice correct?
  ch_correct tinyint(1) UNSIGNED NOT NULL default 0,
  PRIMARY KEY (ch_question_hash, ch_num)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_ticket (
  -- ticket ID
  tk_id int UNSIGNED NOT NULL auto_increment,
  -- ticket key
  tk_key char(32) NOT NULL,
  -- start time
  tk_start_time binary(14) NOT NULL,
  -- end time
  tk_end_time binary(14) DEFAULT NULL,
  -- user ID or NULL for anonymous users
  tk_user_id int UNSIGNED DEFAULT NULL,
  -- user display name (printed on the completion certificate)
  tk_displayname varchar(255) COLLATE utf8_bin DEFAULT NULL,
  -- user name
  tk_user_text varchar(255) COLLATE utf8_bin DEFAULT NULL,
  -- user IP address
  tk_user_ip varbinary(64) NOT NULL,
  -- test ID
  tk_test_id varchar(32) NOT NULL,
  -- variant
  tk_variant blob NOT NULL,
  -- score
  tk_score float DEFAULT NULL,
  -- score %
  tk_score_percent DECIMAL(4,1) DEFAULT NULL,
  -- correct answers count
  tk_correct int DEFAULT NULL,
  -- correct answers %
  tk_correct_percent DECIMAL(4,1) DEFAULT NULL,
  -- passed or no?
  tk_pass tinyint(1) DEFAULT NULL,
  PRIMARY KEY (tk_id)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_choice_stats (
  -- ticket ID
  cs_ticket int UNSIGNED NOT NULL,
  -- question hash
  cs_question_hash binary(32) NOT NULL,
  -- choice index number
  cs_choice_num int UNSIGNED NOT NULL,
  -- is this answer correct?
  cs_correct tinyint(1) NOT NULL,
  KEY (cs_question_hash)
) /*$wgDBTableOptions*/;

-- Create foreign keys (InnoDB only)

ALTER TABLE /*$wgDBPrefix*/mwq_question_test ADD FOREIGN KEY (qt_test_id) REFERENCES /*$wgDBPrefix*/mwq_test (test_id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE /*$wgDBPrefix*/mwq_question_test ADD FOREIGN KEY (qt_question_hash) REFERENCES /*$wgDBPrefix*/mwq_question (qn_hash) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE /*$wgDBPrefix*/mwq_choice ADD FOREIGN KEY (ch_question_hash) REFERENCES /*$wgDBPrefix*/mwq_question (qn_hash) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE /*$wgDBPrefix*/mwq_ticket ADD FOREIGN KEY (tk_test_id) REFERENCES /*$wgDBPrefix*/mwq_test (test_id) ON UPDATE CASCADE;
ALTER TABLE /*$wgDBPrefix*/mwq_ticket ADD FOREIGN KEY (tk_user_id) REFERENCES /*$wgDBPrefix*/user (user_id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE /*$wgDBPrefix*/mwq_choice_stats ADD FOREIGN KEY (cs_question_hash) REFERENCES /*$wgDBPrefix*/mwq_question (qn_hash) ON UPDATE CASCADE;
ALTER TABLE /*$wgDBPrefix*/mwq_choice_stats ADD FOREIGN KEY (cs_ticket) REFERENCES /*$wgDBPrefix*/mwq_ticket (tk_id) ON UPDATE CASCADE;
