"""Matrice d'accès attendue des rôles MySQL de l'application, vérifiée sur une instance réelle.

Cette matrice est écrite indépendamment de database/setup/app_roles.sql : elle reprend ce que
chaque rôle applicatif doit pouvoir faire (docs/security-assessment/06-MATRICE-DE-CONTROLE-D-ACCES.md).
Toute différence entre le script SQL et cette matrice fait échouer la recette.

Utilisée par tests/local-install.py, uniquement sur l'instance neuve de recette.
"""

READ = ['payroll_overview', 'payslip_detail', 'settlement_detail']
PROCEDURES = {
    'sp_employee': ['personal_data_view', 'personal_data_set_rib', *READ],
    'sp_hr_manager': ['account_onboard', 'hr_create_assignment', 'hr_close_assignment', 'hr_create_contract', 'hr_close_contract',
                      'compensation_submit', 'payroll_input_submit', *READ],
    'sp_finance_manager': ['compensation_decide', 'payroll_period_create', 'payroll_input_decide',
                           'payslip_transition', 'settlement_command', *READ],
    'sp_payroll_manager': ['payroll_calculate', 'payslip_transition', 'settlement_command', *READ],
    'sp_application_administrator': ['iam_overview', 'iam_grant', 'iam_revoke'],
    'sp_security_auditor': ['iam_overview', *READ],
    # Actif seul avec sp_session, le temps d'un changement de mot de passe (AuthService::changePassword).
    'sp_password': ['account_change_password'],
}
ALL_PROCEDURES = sorted({name for names in PROCEDURES.values() for name in names})

COMPENSATION = ['compensation_requests', 'compensation_decisions', 'salary_conditions', 'compensation_history']
TABLES = {
    'sp_employee': [],
    'sp_hr_manager': ['departments', 'positions', 'employee_assignments', 'employment_contracts', *COMPENSATION],
    'sp_finance_manager': ['employment_contracts', *COMPENSATION],
    'sp_payroll_manager': [],
    'sp_application_administrator': [],
    'sp_security_auditor': [],
    'sp_password': [],
}
# Tables jamais lisibles directement par l'application, quel que soit le rôle.
SENSITIVE = ['employee_personal_data', 'account_invitations', 'payroll_inputs', 'payroll_calculations', 'payslip_corrections',
             'recovery_requests', 'settlement_ledger', 'security_audit_log']
CHECKED_TABLES = sorted(set(TABLES['sp_hr_manager']) | set(SENSITIVE))


def error_code(local, statement, password):
    """Code d'erreur MySQL de l'instruction exécutée par svc_php, ou None si elle réussit."""
    try:
        local.sql('USE secure_payroll; ' + statement, 'svc_php', password)
    except RuntimeError as error:
        text = str(error)
        return text[text.index('(code ') + 6:text.index(')')]
    return None


def call_statement(local, procedure):
    """CALL avec NULL pour chaque paramètre IN et une variable pour chaque OUT/INOUT."""
    modes = local.sql("SELECT PARAMETER_MODE FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA='secure_payroll' "
                      "AND SPECIFIC_NAME='" + procedure + "' AND ORDINAL_POSITION>0 ORDER BY ORDINAL_POSITION;")
    arguments = ['NULL' if mode == 'IN' else '@out' + str(index)
                 for index, mode in enumerate(modes.split('\n') if modes else [])]
    return 'CALL ' + procedure + '(' + ','.join(arguments) + ');'


def row_counts(local):
    tables = local.sql("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='secure_payroll' "
                       'ORDER BY TABLE_NAME;').split('\n')
    return local.sql('USE secure_payroll; SELECT ' + ','.join('(SELECT COUNT(*) FROM `' + t + '`)' for t in tables) + ';')


def verify(local, password, check):
    before = row_counts(local)
    calls = {procedure: call_statement(local, procedure) for procedure in ALL_PROCEDURES}
    for role in PROCEDURES:
        activate = "SET ROLE 'sp_session','" + role + "'; "
        mismatches = []
        for procedure in ALL_PROCEDURES:
            code = error_code(local, activate + calls[procedure], password)
            # Autorisé : la procédure s'exécute, puis refuse l'acteur inexistant par SIGNAL (1644).
            # Interdit : MySQL refuse l'exécution (1370) avant toute instruction de la procédure.
            if code != ('1644' if procedure in PROCEDURES[role] else '1370'):
                mismatches.append(procedure + '=' + str(code))
        for table in CHECKED_TABLES:
            code = error_code(local, activate + 'SELECT COUNT(*) FROM ' + table + ';', password)
            if (code is None if table in TABLES[role] else code == '1142') is False:
                mismatches.append(table + '=' + str(code))
        check(mismatches == [], 'Matrice ' + role + ' : ' + str(len(PROCEDURES[role])) + '/' + str(len(ALL_PROCEDURES))
              + ' procédures, ' + str(len(TABLES[role])) + '/' + str(len(CHECKED_TABLES)) + ' tables'
              + ('' if not mismatches else ' ÉCARTS ' + ', '.join(mismatches)))
    check(row_counts(local) == before, 'Matrice vérifiée sans aucune modification de données')
