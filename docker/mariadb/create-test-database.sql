-- Runs once, when the MariaDB volume is first created. Gives the app user a
-- separate database for the test suite (see phpunit.xml).
CREATE DATABASE IF NOT EXISTS scheduler_test;
GRANT ALL PRIVILEGES ON scheduler_test.* TO 'scheduler'@'%';
