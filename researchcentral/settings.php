<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_researchcentral', get_string('pluginname', 'local_researchcentral'));
    $ADMIN->add('localplugins', $settings);
    $settings->add(new admin_setting_configtext('local_researchcentral/reminderdays',
        get_string('set_reminderdays', 'local_researchcentral'),
        get_string('set_reminderdays_desc', 'local_researchcentral'), '7,3,1', PARAM_SEQUENCE));
    $settings->add(new admin_setting_configtext('local_researchcentral/overduerepeat',
        get_string('set_overduerepeat', 'local_researchcentral'),
        get_string('set_overduerepeat_desc', 'local_researchcentral'), 7, PARAM_INT));
    $settings->add(new admin_setting_configcheckbox('local_researchcentral/notifysupervisor',
        get_string('set_notifysupervisor', 'local_researchcentral'),
        get_string('set_notifysupervisor_desc', 'local_researchcentral'), 1));
    $settings->add(new admin_setting_configexecutable('local_researchcentral/pdftotextpath',
        get_string('set_pdftotextpath', 'local_researchcentral'),
        get_string('set_pdftotextpath_desc', 'local_researchcentral'), '/usr/bin/pdftotext'));
    $settings->add(new admin_setting_configtext('local_researchcentral/maxfilemb',
        get_string('set_maxfilemb', 'local_researchcentral'),
        get_string('set_maxfilemb_desc', 'local_researchcentral'), 30, PARAM_INT));
    $settings->add(new admin_setting_heading('local_researchcentral/h_review', get_string('set_h_review', 'local_researchcentral'), ''));
    $settings->add(new admin_setting_configtext('local_researchcentral/review_sla_days',
        get_string('set_review_sla', 'local_researchcentral'), get_string('set_review_sla_desc', 'local_researchcentral'), 7, PARAM_INT));
    $settings->add(new admin_setting_configtext('local_researchcentral/cosup_max',
        get_string('set_cosup_max', 'local_researchcentral'), get_string('set_cosup_max_desc', 'local_researchcentral'), 3, PARAM_INT));
    $settings->add(new admin_setting_heading('local_researchcentral/h_ext', get_string('set_h_ext', 'local_researchcentral'), ''));
    $settings->add(new admin_setting_configtext('local_researchcentral/maxext_project',
        get_string('set_maxext_project', 'local_researchcentral'), get_string('set_maxext_project_desc', 'local_researchcentral'), 2, PARAM_INT));
    $settings->add(new admin_setting_configtext('local_researchcentral/maxext_stage',
        get_string('set_maxext_stage', 'local_researchcentral'), get_string('set_maxext_stage_desc', 'local_researchcentral'), 1, PARAM_INT));
    $settings->add(new admin_setting_configcheckbox('local_researchcentral/ext_secondeye',
        get_string('set_ext_secondeye', 'local_researchcentral'), get_string('set_ext_secondeye_desc', 'local_researchcentral'), 1));
    $settings->add(new admin_setting_heading('local_researchcentral/h_research', get_string('set_h_research', 'local_researchcentral'), ''));
    $settings->add(new admin_setting_configtext('local_researchcentral/risk_inactive_days',
        get_string('set_risk_inactive', 'local_researchcentral'), get_string('set_risk_inactive_desc', 'local_researchcentral'), 30, PARAM_INT));
    $settings->add(new admin_setting_configtext('local_researchcentral/sim_max',
        get_string('set_sim_max', 'local_researchcentral'), get_string('set_sim_max_desc', 'local_researchcentral'), 20, PARAM_INT));
    $settings->add(new admin_setting_configcheckbox('local_researchcentral/checkin_on',
        get_string('set_checkin_on', 'local_researchcentral'), get_string('set_checkin_on_desc', 'local_researchcentral'), 1));
    $settings->add(new admin_setting_heading('local_researchcentral/h_v2', get_string('set_h_v2', 'local_researchcentral'), ''));
    $settings->add(new admin_setting_configtext('local_researchcentral/sup_load_max',
        get_string('set_sup_load', 'local_researchcentral'), get_string('set_sup_load_desc', 'local_researchcentral'), 10, PARAM_INT));
    $settings->add(new admin_setting_configtext('local_researchcentral/repo_license',
        get_string('set_repo_license', 'local_researchcentral'), get_string('set_repo_license_desc', 'local_researchcentral'), 'CC BY-NC-ND 4.0', PARAM_TEXT));
    $settings->add(new admin_setting_heading('local_researchcentral/h_upload', get_string('set_h_upload', 'local_researchcentral'), ''));
    $settings->add(new admin_setting_configtext('local_researchcentral/uploadmb',
        get_string('set_uploadmb', 'local_researchcentral'), get_string('set_uploadmb_desc', 'local_researchcentral'), 30, PARAM_INT));
    $settings->add(new admin_setting_configtext('local_researchcentral/uploadmb_ceiling',
        get_string('set_uploadmb_ceiling', 'local_researchcentral'), get_string('set_uploadmb_ceiling_desc', 'local_researchcentral'), 100, PARAM_INT));
    $settings->add(new admin_setting_configtext('local_researchcentral/figmb',
        get_string('set_figmb', 'local_researchcentral'), get_string('set_figmb_desc', 'local_researchcentral'), 5, PARAM_INT));
    $settings->add(new admin_setting_configtext('local_researchcentral/maxfigures',
        get_string('set_maxfigures', 'local_researchcentral'), get_string('set_maxfigures_desc', 'local_researchcentral'), 40, PARAM_INT));
    $settings->add(new admin_setting_configcheckbox('local_researchcentral/createactivities',
        get_string('set_createactivities', 'local_researchcentral'),
        get_string('set_createactivities_desc', 'local_researchcentral'), 1));
}
