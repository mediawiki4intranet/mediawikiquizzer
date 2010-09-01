ALTER TABLE /*$wgDBPrefix*/mwq_ticket ADD tk_score float DEFAULT NULL;
ALTER TABLE /*$wgDBPrefix*/mwq_ticket ADD tk_score_percent DECIMAL(4,1) DEFAULT NULL;
ALTER TABLE /*$wgDBPrefix*/mwq_ticket ADD tk_correct int DEFAULT NULL;
ALTER TABLE /*$wgDBPrefix*/mwq_ticket ADD tk_correct_percent DECIMAL(4,1) DEFAULT NULL;
ALTER TABLE /*$wgDBPrefix*/mwq_ticket ADD tk_pass tinyint(1) DEFAULT NULL;
