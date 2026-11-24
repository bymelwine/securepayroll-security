-- New locked definer. No password is assigned or stored; authentication is disabled.
CREATE USER 'payroll_owner'@'localhost' IDENTIFIED WITH caching_sha2_password AS '' ACCOUNT LOCK;
