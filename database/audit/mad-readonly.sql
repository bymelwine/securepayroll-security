-- Strictly read-only diagnosis. No DDL, DML, GRANT, procedure call or migration replay.
SELECT 'MAD_DIAGNOSTIC_SERVER' AS section, VERSION() AS version, DATABASE() AS selected_database,
 @@hostname AS server_hostname, @@socket AS server_socket, @@datadir AS server_datadir,
 CURRENT_USER() AS authenticated_account;
SELECT 'MAD_ORIGINAL_VALIDATOR' AS section, COUNT(*) AS actual_matches, 2 AS required_matches
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='secure_payroll' AND TABLE_NAME IN ('compensation_requests','salary_conditions')
 AND COLUMN_NAME='currency' AND CHARACTER_MAXIMUM_LENGTH=3 AND COLUMN_DEFAULT='MAD';
SELECT 'MAD_COLUMN_DETAIL' AS section, n.table_name,c.COLUMN_TYPE,c.DATA_TYPE,c.CHARACTER_MAXIMUM_LENGTH,
 c.IS_NULLABLE,c.COLUMN_DEFAULT,HEX(c.COLUMN_DEFAULT) AS default_hex,c.CHARACTER_SET_NAME,c.COLLATION_NAME,
 c.COLUMN_COMMENT,c.EXTRA,c.GENERATION_EXPRESSION,
 IF(c.COLUMN_NAME IS NULL,'FAIL missing column','OK') AS column_presence,
 IF(c.CHARACTER_MAXIMUM_LENGTH=3,'OK','FAIL length != 3') AS original_length_check,
 IF(c.COLUMN_DEFAULT='MAD','OK','FAIL default != MAD or NULL') AS original_default_check
FROM (SELECT 'compensation_requests' AS table_name UNION ALL SELECT 'salary_conditions') n
LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA='secure_payroll' AND c.TABLE_NAME=n.table_name AND c.COLUMN_NAME='currency';
SELECT 'MAD_AMOUNT_DETAIL' AS section,TABLE_NAME,COLUMN_TYPE,IS_NULLABLE,NUMERIC_PRECISION,NUMERIC_SCALE
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='secure_payroll'
 AND TABLE_NAME IN ('compensation_requests','salary_conditions') AND COLUMN_NAME='monthly_amount';
SELECT 'MAD_CHECKS' AS section,tc.TABLE_NAME,tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE
FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc
 ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME
WHERE tc.TABLE_SCHEMA='secure_payroll' AND tc.TABLE_NAME IN ('compensation_requests','salary_conditions') AND tc.CONSTRAINT_TYPE='CHECK'
ORDER BY tc.TABLE_NAME,tc.CONSTRAINT_NAME;
SELECT 'MAD_ROUTINES' AS section,n.name,r.DEFINER,r.SECURITY_TYPE,r.SQL_DATA_ACCESS,r.SQL_MODE,
 IF(r.ROUTINE_NAME IS NULL,'MISSING',IF(r.ROUTINE_DEFINITION IS NULL,'BODY NOT VISIBLE','BODY VISIBLE')) AS visibility,
 r.ROUTINE_DEFINITION
FROM (SELECT 'compensation_check_period' AS name UNION ALL SELECT 'compensation_submit' UNION ALL SELECT 'compensation_decide') n
LEFT JOIN information_schema.ROUTINES r ON r.ROUTINE_SCHEMA='secure_payroll' AND r.ROUTINE_NAME=n.name AND r.ROUTINE_TYPE='PROCEDURE';
SELECT 'MAD_TRIGGER_INVENTORY' AS section,TRIGGER_NAME,EVENT_OBJECT_TABLE,DEFINER,ACTION_TIMING,EVENT_MANIPULATION
FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='secure_payroll' AND EVENT_OBJECT_TABLE IN ('compensation_requests','salary_conditions')
ORDER BY EVENT_OBJECT_TABLE,TRIGGER_NAME;
-- Data are aggregated per recorded currency, never added across currencies.
START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY;
SELECT 'MAD_EXACT_ROW_COUNTS' AS section,'compensation_requests' AS table_name,COUNT(*) AS row_count FROM secure_payroll.compensation_requests
UNION ALL SELECT 'MAD_EXACT_ROW_COUNTS','salary_conditions',COUNT(*) FROM secure_payroll.salary_conditions;
SELECT 'MAD_RECORDED_DATA' AS section,'compensation_requests' AS table_name,currency,HEX(currency) AS currency_hex,
 COUNT(*) AS row_count,CAST(SUM(monthly_amount) AS CHAR) AS total_in_recorded_currency
FROM secure_payroll.compensation_requests GROUP BY currency,HEX(currency);
SELECT 'MAD_RECORDED_DATA' AS section,'salary_conditions' AS table_name,currency,HEX(currency) AS currency_hex,
 COUNT(*) AS row_count,CAST(SUM(monthly_amount) AS CHAR) AS total_in_recorded_currency
FROM secure_payroll.salary_conditions GROUP BY currency,HEX(currency);
SELECT 'MAD_INVALID_DATA' AS section,'compensation_requests' AS table_name,COUNT(*) AS invalid_rows
FROM secure_payroll.compensation_requests WHERE monthly_amount<=0 OR monthly_amount IS NULL OR currency IS NULL OR BINARY currency NOT IN ('MAD','UM');
SELECT 'MAD_INVALID_DATA' AS section,'salary_conditions' AS table_name,COUNT(*) AS invalid_rows
FROM secure_payroll.salary_conditions WHERE monthly_amount<=0 OR monthly_amount IS NULL OR currency IS NULL OR BINARY currency NOT IN ('MAD','UM');
SELECT 'MAD_REQUEST_SALARY_MISMATCH' AS section,COUNT(*) AS mismatched_rows
FROM secure_payroll.salary_conditions s LEFT JOIN secure_payroll.compensation_requests q ON q.id=s.request_id
WHERE q.id IS NULL OR s.monthly_amount<>q.monthly_amount OR BINARY s.currency<>BINARY q.currency;
COMMIT;
SHOW CREATE TABLE secure_payroll.compensation_requests;
SHOW CREATE TABLE secure_payroll.salary_conditions;
