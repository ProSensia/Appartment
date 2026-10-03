-- Truncate all but keep admin
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE activity_log; TRUNCATE TABLE announcements; TRUNCATE TABLE announcement_reads; TRUNCATE TABLE chore_tasks; TRUNCATE TABLE expense_splits; TRUNCATE TABLE expenses; TRUNCATE TABLE meal_participants; TRUNCATE TABLE meal_suggestions; TRUNCATE TABLE suggestion_votes; TRUNCATE TABLE magic_links; TRUNCATE TABLE reminders; TRUNCATE TABLE settlements; TRUNCATE TABLE sessions; TRUNCATE TABLE invites; TRUNCATE TABLE offboarding_tasks; TRUNCATE TABLE meal_plans; TRUNCATE TABLE meals;
DELETE FROM users WHERE role != 'admin';
SET FOREIGN_KEY_CHECKS=1;
SELECT * FROM users;

