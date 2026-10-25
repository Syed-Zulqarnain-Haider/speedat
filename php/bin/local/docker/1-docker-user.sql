-- In Docker the PHP container reaches MariaDB over the compose network, not
-- from localhost, so the local-only account in ../bootstrap-db.sql needs a
-- network twin. Development only: the password is the bootstrap's own.
CREATE USER IF NOT EXISTS 'speedat'@'%' IDENTIFIED BY 'speedat';
GRANT ALL PRIVILEGES ON `speedat_php`.*      TO 'speedat'@'%';
GRANT ALL PRIVILEGES ON `speedat_php_test`.* TO 'speedat'@'%';
FLUSH PRIVILEGES;
