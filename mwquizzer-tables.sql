-- Tables used by MediawikiQuizzer extension.
-- Vitaliy Filippov, 2010.
--
-- You should not have to create these tables manually unless you are doing
-- a manual installation. In normal conditions, maintenance/update.php should
-- perform any needed database setup.
--

CREATE TABLE /*$wgDBPrefix*/mwq_choice (
  mwc_id_test int(3) unsigned NOT NULL default 0,
  mwc_num int(3) unsigned NOT NULL default 0,
  mwc_pos tinyint(3) unsigned NOT NULL auto_increment,
  mwc_choice blob NOT NULL,
  mwc_answer tinyint(1) unsigned NOT NULL default 0,
  PRIMARY KEY (mwc_id_test, mwc_num, mwc_pos)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_question (
  mwq_id_test int(3) unsigned NOT NULL default '0',
  mwq_num int(3) unsigned NOT NULL default '0',
  mwq_question blob NOT NULL,
  mwq_explanation blob,
  mwq_label varchar(64) default NULL,
  mwq_code binary(32) NOT NULL default '',
  PRIMARY KEY (mwq_id_test, mwq_num),
  KEY mwq_code (mwq_code)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_question_stats (
  mws_code binary(32) NOT NULL,
  mws_dtm timestamp NOT NULL default CURRENT_TIMESTAMP,
  mws_try bigint(20) NOT NULL default '0',
  mws_success bigint(20) NOT NULL default '0',
  UNIQUE KEY mws_code (mws_code)
) /*$wgDBTableOptions*/;

CREATE TABLE /*$wgDBPrefix*/mwq_test (
  mwt_id int(3) unsigned NOT NULL auto_increment,
  mwt_name varchar(64) NOT NULL default '',
  mwt_intro blob,
  mwt_prompt blob,
  mwt_secret_code varchar(32) NOT NULL default '',
  mwt_mode varchar(16) NOT NULL default '',
  mwt_shuffle_choices tinyint(1) NOT NULL default '0',
  mwt_shuffle_questions tinyint(1) NOT NULL default '0',
  mwt_ok_percent tinyint(3) NOT NULL default '80',
  mwt_q_limit tinyint(4) NOT NULL default '0',
  mwt_include_if_try_count_less smallint(6) default NULL,
  mwt_exclude_if_success_gt tinyint(4) default NULL,
  PRIMARY KEY (mwt_id)
) /*$wgDBTableOptions*/;
