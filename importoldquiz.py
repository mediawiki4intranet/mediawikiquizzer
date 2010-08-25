#!/usr/bin/python
# -*- coding: utf-8 -*-

import sys

def printquestions(questions):
  for q in questions:
    sys.stdout.write("== Вопрос")
    if 'label' in q and q['label'].strip() != '':
      sys.stdout.write(": "+q['label'].strip())
    if 'tags' in q:
      sys.stdout.write(', ' + ', '.join(q['tags']))
    sys.stdout.write(" ==\n\n"+q['question'].strip()+"\n\n")
    m = 0
    for c in q['choices']:
      if c[1].find('\n') != -1:
        m = 1
    if m:
      for c in q['choices']:
        if c[0]:
          sys.stdout.write("=== Правильный ответ ===\n\n")
        else:
          sys.stdout.write("=== Ответ ===\n\n")
        sys.stdout.write(c[1].strip()+"\n\n")
    else:
      sys.stdout.write("=== Ответы ===\n\n")
      for c in q['choices']:
        if c[0]:
          sys.stdout.write("* Правильный ответ: ")
        else:
          sys.stdout.write("* ")
        sys.stdout.write(c[1]+"\n")
      sys.stdout.write("\n")
    if 'reason' in q and q['reason'].strip() != '':
      sys.stdout.write("=== Объяснение ===\n\n"+q['reason'].strip()+"\n\n")
