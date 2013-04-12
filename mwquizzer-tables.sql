--
-- Tables used by MediawikiQuizzer extension.
-- (c) Vitaliy Filippov, 2010+
--
-- You should not have to create these tables manually unless you are doing
-- a manual installation. In normal conditions, maintenance/update.php should
-- perform any needed database setup.
--

CREATE TABLE /*$wgDBPrefix*/mwq_test (
  -- test ID,
  test_id int unsigned not null auto_increment,
  -- test page title
  test_page_title varchar(255) binary not null,
  -- test title
  test_name varchar(255) not null default '',
  -- brief description of test
  test_intro longblob,
  -- TEST or TUTOR
  test_mode enum('TEST', 'TUTOR') not null default 'TEST',
  -- randomize choice positions
  test_shuffle_choices tinyint(1) not null default '0',
  -- randomize question positions
  test_shuffle_questions tinyint(1) not null default '0',
  -- select only X random questions from test
  test_limit_questions tinyint(4) not null default '0',
  -- percent of correct answers to pass
  test_ok_percent tinyint(3) not null default '80',
  -- user details
  test_user_details blob not null,
  -- each variant includes questions shown less than X times ("too new to filter")...
  test_autofilter_min_tries smallint not null,
  -- ...and questions with correct answer percent greater than Y ("too simple")
  -- but if qt_autofilter_min_tries <= 0 then autofilter is disabled
  test_autofilter_success_percent tinyint not null,
  -- quiz article parse log
  test_log longblob not null,
  PRIMARY KEY (test_id),
  UNIQUE KEY (test_page_title)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_question (
  -- question ID (md5 hash of question text)
  qn_hash binary(32) not null,
  -- question text
  qn_text blob not null,
  -- correct answer explanation text
  qn_explanation blob default null,
  -- arbitrary label to classify questions
  qn_label varbinary(255) default null,
  -- HTML anchor of question section inside article
  qn_anchor varbinary(255) not null default '',
  -- extracted HTML code with edit question section link
  qn_editsection blob default null,
  PRIMARY KEY (qn_hash)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_question_test (
  -- test ID
  qt_test_id int unsigned not null,
  -- question hash
  qt_question_hash binary(32) not null,
  -- question index number inside the test
  qt_num int unsigned not null,
  PRIMARY KEY (qt_test_id, qt_num)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_choice (
  -- question hash
  ch_question_hash binary(32) not null,
  -- choice index number inside the question
  ch_num int unsigned not null,
  -- choice text
  ch_text blob not null,
  -- is this choice correct?
  ch_correct tinyint(1) unsigned not null default 0,
  PRIMARY KEY (ch_question_hash, ch_num)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_ticket (
  -- ticket ID
  tk_id int unsigned not null auto_increment,
  -- ticket key
  tk_key char(32) not null,
  -- start time
  tk_start_time binary(14) not null,
  -- end time
  tk_end_time binary(14) default null,
  -- user ID or NULL for anonymous users
  tk_user_id int unsigned default null,
  -- user display name (printed on the completion certificate)
  tk_displayname varchar(255) COLLATE utf8_bin default null,
  -- user details (JSON hash of arbitrary data) + predefined field 'reviewed'
  tk_details blob default null,
  -- user name
  tk_user_text varchar(255) COLLATE utf8_bin default null,
  -- user IP address
  tk_user_ip varbinary(64) not null,
  -- test ID
  tk_test_id int unsigned not null,
  -- variant
  tk_variant blob not null,
  -- score
  tk_score float default null,
  -- score %
  tk_score_percent decimal(4,1) default null,
  -- correct answers count
  tk_correct int default null,
  -- correct answers %
  tk_correct_percent decimal(4,1) default null,
  -- passed or no?
  tk_pass tinyint(1) default null,
  PRIMARY KEY (tk_id)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_choice_stats (
  -- ticket ID
  cs_ticket int unsigned not null,
  -- question hash
  cs_question_hash binary(32) not null,
  -- choice index number
  cs_choice_num int unsigned not null,
  -- is this answer correct?
  cs_correct tinyint(1) not null,
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
