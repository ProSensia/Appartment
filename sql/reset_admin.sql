-- Reset to single admin
UPDATE users SET role='resident';
UPDATE users SET role='admin' WHERE email='aisha@flatmate.test' LIMIT 1;
UPDATE users SET role='admin' WHERE id=1 LIMIT 1;
SELECT id,email,role,status FROM users ORDER BY id;

