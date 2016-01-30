--
-- Adds a field for free-text answers.
-- (c) Vitaliy Filippov, 2015
--
-- You should not have to apply this patch manually.
-- In normal conditions, maintenance/update.php should perform any needed database setup.
--

SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;

ALTER TABLE /*$wgDBprefix*/mwq_choice_stats ADD cs_text TEXT;

SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
