--
-- Tables used by MediawikiQuizzer extension for PostgreSQL
-- (c) Vitaliy Filippov, 2015+
--
-- You should not have to create these tables manually unless you are doing
-- a manual installation. In normal conditions, maintenance/update.php should
-- perform any needed database setup.
--

CREATE TABLE /*$wgDBprefix*/mwq_test (
  -- test ID,
  test_id int not null,
  -- test page title
  test_page_title text not null,
  -- test title
  test_name text not null default '',
  -- brief description of test
  test_intro text,
  -- TEST or TUTOR
  test_mode varchar(5) not null default 'TEST',
  -- randomize choice positions
  test_shuffle_choices boolean not null default false,
  -- randomize question positions
  test_shuffle_questions boolean not null default false,
  -- select only X random questions from test
  test_limit_questions int not null default 0,
  -- percent of correct answers to pass
  test_ok_percent smallint not null default 80,
  -- user details
  test_user_details text not null,
  -- each variant includes questions shown less than X times ("too new to filter")...
  test_autofilter_min_tries smallint not null,
  -- ...and questions with correct answer percent greater than Y ("too simple")
  -- but if qt_autofilter_min_tries <= 0 then autofilter is disabled
  test_autofilter_success_percent smallint not null,
  -- is the quiz secret for non-admins, i.e. accessible only by pre-generated URLs with tokens
  test_secret smallint not null default 0,
  -- quiz article parse log
  test_log text not null,
  PRIMARY KEY (test_id)
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX mwq_test_page_title ON /*$wgDBprefix*/mwq_test (test_page_title);

CREATE TABLE /*$wgDBprefix*/mwq_question (
  -- question ID (md5 hash of question text)
  qn_hash char(32) not null,
  -- question text
  qn_text text not null,
  -- correct answer explanation text
  qn_explanation text default null,
  -- arbitrary label to classify questions
  qn_label varchar(255) default null,
  -- HTML anchor of question section inside article
  qn_anchor varchar(255) not null default '',
  -- extracted HTML code with edit question section link
  qn_editsection text default null,
  PRIMARY KEY (qn_hash)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBprefix*/mwq_question_test (
  -- test ID
  qt_test_id int not null,
  -- question hash
  qt_question_hash char(32) not null,
  -- question index number inside the test
  qt_num int not null,
  PRIMARY KEY (qt_test_id, qt_num)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBprefix*/mwq_choice (
  -- question hash
  ch_question_hash char(32) not null,
  -- choice index number inside the question
  ch_num int not null,
  -- choice text
  ch_text text not null,
  -- is this choice correct?
  ch_correct boolean not null default false,
  PRIMARY KEY (ch_question_hash, ch_num)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBprefix*/mwq_ticket (
  -- ticket ID
  tk_id serial not null,
  -- ticket key
  tk_key char(32) not null,
  -- start time
  tk_start_time timestamp with time zone default null,
  -- end time
  tk_end_time timestamp with time zone default null,
  -- user ID or NULL for anonymous users
  tk_user_id int default null,
  -- user display name (printed on the completion certificate)
  tk_displayname varchar(255) default null,
  -- user details (JSON hash of arbitrary data)
  tk_details text default null,
  -- is the ticket reviewed by admins?
  tk_reviewed boolean not null default false,
  -- user name
  tk_user_text varchar(255) default null,
  -- user IP address
  tk_user_ip varchar(64) not null,
  -- test ID
  tk_test_id int not null,
  -- variant
  tk_variant text not null,
  -- score
  tk_score float default null,
  -- score %
  tk_score_percent decimal(4,1) default null,
  -- correct answers count
  tk_correct int default null,
  -- correct answers %
  tk_correct_percent decimal(4,1) default null,
  -- passed or no?
  tk_pass boolean default null,
  PRIMARY KEY (tk_id)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBprefix*/mwq_choice_stats (
  -- ticket ID
  cs_ticket int not null,
  -- question hash
  cs_question_hash char(32) not null,
  -- choice index number
  cs_choice_num int not null,
  -- is this answer correct?
  cs_correct boolean not null default false,
  -- free-text answer
  cs_text text
) /*$wgDBTableOptions*/;

CREATE INDEX mwq_choice_stats_cs_question_hash ON /*$wgDBprefix*/mwq_choice_stats (cs_question_hash);

ALTER TABLE /*$wgDBprefix*/mwq_question_test ADD FOREIGN KEY (qt_test_id) REFERENCES /*$wgDBprefix*/mwq_test (test_id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE /*$wgDBprefix*/mwq_question_test ADD FOREIGN KEY (qt_question_hash) REFERENCES /*$wgDBprefix*/mwq_question (qn_hash) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE /*$wgDBprefix*/mwq_choice ADD FOREIGN KEY (ch_question_hash) REFERENCES /*$wgDBprefix*/mwq_question (qn_hash) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE /*$wgDBprefix*/mwq_ticket ADD FOREIGN KEY (tk_test_id) REFERENCES /*$wgDBprefix*/mwq_test (test_id) ON UPDATE CASCADE;
ALTER TABLE /*$wgDBprefix*/mwq_ticket ADD FOREIGN KEY (tk_user_id) REFERENCES /*$wgDBprefix*/mwuser (user_id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE /*$wgDBprefix*/mwq_choice_stats ADD FOREIGN KEY (cs_question_hash) REFERENCES /*$wgDBprefix*/mwq_question (qn_hash) ON UPDATE CASCADE;
ALTER TABLE /*$wgDBprefix*/mwq_choice_stats ADD FOREIGN KEY (cs_ticket) REFERENCES /*$wgDBprefix*/mwq_ticket (tk_id) ON UPDATE CASCADE;
