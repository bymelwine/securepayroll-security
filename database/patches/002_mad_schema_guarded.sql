-- PREPARED ONLY: review the actual diagnose-mad report and a coherent backup FIRST.
-- This is only the schema half: use bin/mad-repair.py with patch 003, never
-- apply this file alone to repair routines that still explicitly insert UM.
-- Explicit DBA authorization is required via @mad_schema_repair_authorized=1.
-- Schema-only repair for the exact known 004/006 column/check states.
-- No UPDATE, conversion, routine replacement, GRANT or change to monthly_amount.
-- Client must stop at the first error: never use --force.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET @mad_schema_guard = COALESCE(@mad_schema_repair_authorized=1,FALSE)
 AND (SELECT COUNT(*)=2 FROM information_schema.TABLES WHERE TABLE_SCHEMA='secure_payroll' AND TABLE_NAME IN ('compensation_requests','salary_conditions') AND ENGINE='InnoDB')
 AND (SELECT COUNT(*)=2 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='secure_payroll' AND TABLE_NAME IN ('compensation_requests','salary_conditions') AND COLUMN_NAME='currency'
  AND DATA_TYPE='char' AND CHARACTER_MAXIMUM_LENGTH IN (2,3) AND IS_NULLABLE='NO'
  AND BINARY COLUMN_DEFAULT IN ('UM','MAD') AND CHARACTER_SET_NAME='utf8mb4' AND COLLATION_NAME='utf8mb4_0900_as_cs'
  AND COLUMN_COMMENT='' AND EXTRA='' AND GENERATION_EXPRESSION='')
 AND (SELECT COUNT(*)=2 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='secure_payroll' AND TABLE_NAME IN ('compensation_requests','salary_conditions') AND COLUMN_NAME='monthly_amount'
  AND DATA_TYPE='decimal' AND NUMERIC_PRECISION=12 AND NUMERIC_SCALE=2 AND IS_NULLABLE='NO')
 AND (SELECT COUNT(*)=2 FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME
  WHERE tc.TABLE_SCHEMA='secure_payroll' AND tc.CONSTRAINT_TYPE='CHECK' AND tc.ENFORCED='YES'
   AND ((tc.TABLE_NAME='compensation_requests' AND tc.CONSTRAINT_NAME='ck_cr_amount') OR (tc.TABLE_NAME='salary_conditions' AND tc.CONSTRAINT_NAME='ck_sc_amount'))
   AND LOWER(REGEXP_REPLACE(REPLACE(REPLACE(cc.CHECK_CLAUSE,CHAR(92),''),'_utf8mb4',''),'[[:space:]`()]+','')) IN ('monthly_amount>0andcurrency=''um''','monthly_amount>0andcurrencyin''mad'',''um'''))
 AND NOT EXISTS(SELECT 1 FROM compensation_requests WHERE monthly_amount IS NULL OR monthly_amount<=0 OR currency IS NULL OR BINARY currency NOT IN ('UM','MAD'))
 AND NOT EXISTS(SELECT 1 FROM salary_conditions WHERE monthly_amount IS NULL OR monthly_amount<=0 OR currency IS NULL OR BINARY currency NOT IN ('UM','MAD'))
;
-- An unexpected state deliberately fails before any ALTER (no stored helper created).
SET @mad_schema_sql = IF(@mad_schema_guard, 'SELECT ''MAD schema preconditions verified'' AS status', 'SELECT * FROM information_schema.MAD_SCHEMA_REPAIR_REFUSED');
PREPARE mad_schema_stmt FROM @mad_schema_sql;
EXECUTE mad_schema_stmt;
DEALLOCATE PREPARE mad_schema_stmt;

SET @mad_schema_sql = IF((SELECT COUNT(*)=1 FROM information_schema.COLUMNS c JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.TABLE_SCHEMA=c.TABLE_SCHEMA AND tc.TABLE_NAME=c.TABLE_NAME JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE c.TABLE_SCHEMA='secure_payroll' AND c.TABLE_NAME='compensation_requests' AND c.COLUMN_NAME='currency' AND c.CHARACTER_MAXIMUM_LENGTH=3 AND BINARY c.COLUMN_DEFAULT='MAD' AND tc.CONSTRAINT_NAME='ck_cr_amount' AND LOWER(REGEXP_REPLACE(REPLACE(REPLACE(cc.CHECK_CLAUSE,CHAR(92),''),'_utf8mb4',''),'[[:space:]`()]+',''))='monthly_amount>0andcurrencyin''mad'',''um'''), 'SELECT ''compensation_requests: already correct; no ALTER'' AS status', 'ALTER TABLE compensation_requests DROP CHECK ck_cr_amount, MODIFY currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NOT NULL DEFAULT ''MAD'', ADD CONSTRAINT ck_cr_amount CHECK(monthly_amount>0 AND currency IN (''MAD'',''UM''))');
PREPARE mad_schema_stmt FROM @mad_schema_sql;
EXECUTE mad_schema_stmt;
DEALLOCATE PREPARE mad_schema_stmt;

SET @mad_schema_sql = IF((SELECT COUNT(*)=1 FROM information_schema.COLUMNS c JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.TABLE_SCHEMA=c.TABLE_SCHEMA AND tc.TABLE_NAME=c.TABLE_NAME JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE c.TABLE_SCHEMA='secure_payroll' AND c.TABLE_NAME='salary_conditions' AND c.COLUMN_NAME='currency' AND c.CHARACTER_MAXIMUM_LENGTH=3 AND BINARY c.COLUMN_DEFAULT='MAD' AND tc.CONSTRAINT_NAME='ck_sc_amount' AND LOWER(REGEXP_REPLACE(REPLACE(REPLACE(cc.CHECK_CLAUSE,CHAR(92),''),'_utf8mb4',''),'[[:space:]`()]+',''))='monthly_amount>0andcurrencyin''mad'',''um'''), 'SELECT ''salary_conditions: already correct; no ALTER'' AS status', 'ALTER TABLE salary_conditions DROP CHECK ck_sc_amount, MODIFY currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NOT NULL DEFAULT ''MAD'', ADD CONSTRAINT ck_sc_amount CHECK(monthly_amount>0 AND currency IN (''MAD'',''UM''))');
PREPARE mad_schema_stmt FROM @mad_schema_sql;
EXECUTE mad_schema_stmt;
DEALLOCATE PREPARE mad_schema_stmt;

-- Existing currencies remain unchanged: UM stays UM, with the same amounts.
SELECT TABLE_NAME,COLUMN_TYPE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='secure_payroll' AND TABLE_NAME IN ('compensation_requests','salary_conditions') AND COLUMN_NAME='currency' ORDER BY TABLE_NAME;
