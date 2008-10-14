#!/usr/bin/python
# -*- coding: windows-1251 -*-
# $Id: mwquiz_load.py,v 1.3 2006/12/11 16:46:33 stas Exp $
#

import sys
import string

import MySQLdb
import _mysql
import string
import re

if len(sys.argv)<=4:
 print """
  Usage: %s dbhost dbuser password test_file
  Example:
         python %s myserver.office.com mwquizzer olga56 cvs_quiz.py 
 """ % (sys.argv[0],sys.argv[0])
 exit(0);

dbc=MySQLdb.connect(host = sys.argv[1],
                    user = sys.argv[2], 
                  passwd = sys.argv[3], 
                      db = 'mwquizzer')

execfile(sys.argv[4]);

lc = dbc.cursor()
lc.execute("set character_set_results='cp1251'");
lc.execute("set character_set_client='cp1251'");
lc.execute("set character_set_server='utf8'");
lc.execute("set collation_connection='utf8_general_ci'");

for q in quizzes:
    lc.execute("DELETE FROM choice WHERE id_test=%s", (q['id']))
    lc.execute("DELETE FROM question WHERE id_test=%s", (q['id']))
    lc.execute("DELETE FROM test WHERE id=%s", (q['id']))
    if not q.has_key('prompt'):
      q['prompt']=''
    if not q.has_key('secret_code'):
      q['secret_code']='secret_code'
    if not q.has_key('ok_percent'):
      q['ok_percent']=80
    if not q.has_key('limit'):
      q['limit']='0'
    lc.execute("""
        insert into test
           (id, name, intro, mode,
            shuffle_choices,shuffle_questions,
            prompt,secret_code, ok_percent, q_limit) 
        values(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
    """,(q['id'], q['name'], q['intro'], q['mode'],
         q['shuffle_choices'], q['shuffle_questions'],
         q['prompt'], q['secret_code'], q['ok_percent'], 
	 q['limit']
         ));
    questions=q['questions']
    for i in range(0,len(questions)):
        lq=questions[i]
        if not lq.has_key('reason'):
          lq['reason']=''
	if not lq.has_key('label'):
      	  lq['label']=''
        lc.execute(
        """
        insert into question(id_test, num, question, explanation, label) 
        values(%s, %s, %s, %s, %s)
        """,
        (q['id'], i+1, lq['question'], lq['reason'], lq['label']));
        choices=lq['choices']
        for li_choice in range(0,len(choices)):
            choice=choices[li_choice]
            lc.execute("""
            insert into choice (id_test,num,pos,choice,answer) 
            values(%s,%s,%s,%s,%s)
            """,
            (q['id'], i+1, li_choice+1, choice[1],choice[0]))
            

