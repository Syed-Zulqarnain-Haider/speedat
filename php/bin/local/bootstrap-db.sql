-- Speedat PHP port - local development database bootstrap.
--
-- Run once, as root, against the local MariaDB on port 3307:
--   D:\mariadb\bin\mariadb.exe --protocol=tcp --port=3307 --user=root < bootstrap-db.sql
--
-- The `speedat` user deliberately gets ONLY the privileges a Hostinger
-- database user gets: everything inside its own two schemas, nothing global,
-- no GRANT OPTION, no CREATE USER, no FILE, no PROCESS. If the application
-- ever needs a privilege this user does not have, that is a design problem to
-- fix here, not a privilege to add on the server.
--
-- These credentials are LOCAL DEVELOPMENT ONLY and are committed on purpose so
-- the setup is reproducible. The production credentials live in
-- config/config.php on the server, which is never committed and never sits
-- inside public_html.

CREATE DATABASE IF NOT EXISTS `speedat_php`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS `speedat_php_test`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Both hosts: 'localhost' and '127.0.0.1' are distinct accounts in MySQL /
-- MariaDB, and PDO's mysql:host=127.0.0.1 matches the latter.
CREATE USER IF NOT EXISTS 'speedat'@'localhost'  IDENTIFIED BY 'speedat';
CREATE USER IF NOT EXISTS 'speedat'@'127.0.0.1'  IDENTIFIED BY 'speedat';

GRANT ALL PRIVILEGES ON `speedat_php`.*      TO 'speedat'@'localhost';
GRANT ALL PRIVILEGES ON `speedat_php`.*      TO 'speedat'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `speedat_php_test`.* TO 'speedat'@'localhost';
GRANT ALL PRIVILEGES ON `speedat_php_test`.* TO 'speedat'@'127.0.0.1';

FLUSH PRIVILEGES;

SELECT VERSION() AS mariadb_version;
SHOW DATABASES LIKE 'speedat\_php%';
SELECT user, host FROM mysql.user WHERE user = 'speedat';
