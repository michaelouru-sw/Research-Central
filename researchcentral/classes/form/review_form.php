<?php
namespace local_researchcentral\form;

defined("MOODLE_INTERNAL") || die();

require_once($CFG->libdir . "/formslib.php");

/** A supervisor's review of a submission: recommendation, mark, comments to the author, confidential note, attachments. */
class review_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $L = fn($k) => get_string($k, 'local_researchcentral');
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'pms', $this->_customdata['pms']);
        $mform->setType('pms', PARAM_INT);
        $mform->addElement('hidden', 'action', 'decide');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('select', 'recommendation', $L('rec_label'), \local_researchcentral\local\review::recommendations());
        $max = (int)($this->_customdata['maxgrade'] ?? 0);
        if ($max > 0) {
            $mform->addElement('text', 'grade', get_string('mark_out_of', 'local_researchcentral', $max), ['size' => 6]);
            $mform->setType('grade', PARAM_RAW);
            $mform->hideIf('grade', 'recommendation', 'neq', 'approve');
        }
        global $DB, $USER, $PAGE;
        $snips = $DB->get_records('local_researchcentral_snip', ['userid' => $USER->id], 'title');
        if ($snips) {
            $opts = \html_writer::tag('option', $L('snip_insert'), ['value' => '']);
            foreach ($snips as $sn) {
                $opts .= \html_writer::tag('option', s($sn->title), ['value' => $sn->id, 'data-body' => $sn->body]);
            }
            $mform->addElement('static', 'snipbar', $L('comment_bank'), \html_writer::tag('select', $opts, ['id' => 'tt-snip', 'class' => 'custom-select w-auto']) . ' ' .
                \html_writer::link(new \moodle_url('/local/researchcentral/comments.php'), $L('comment_bank_manage'), ['target' => '_blank']));
            $PAGE->requires->js_amd_inline("document.getElementById('tt-snip').addEventListener('change',function(){var o=this.options[this.selectedIndex];if(!o.value){return;}var t=document.getElementById('id_comment');t.value=(t.value?t.value+'\\n\\n':'')+o.getAttribute('data-body');this.selectedIndex=0;});");
        }
        $mform->addElement('textarea', 'comment', $L('review_comment'), ['rows' => 8, 'cols' => 70]);
        $mform->setType('comment', PARAM_TEXT);
        $mform->addHelpButton('comment', 'review_comment', 'local_researchcentral');
        $mform->addElement('textarea', 'confidential', $L('review_confidential'), ['rows' => 3, 'cols' => 70]);
        $mform->setType('confidential', PARAM_TEXT);
        $mform->addHelpButton('confidential', 'review_confidential', 'local_researchcentral');
        $maxmb = \local_researchcentral\local\uploads::site_default_mb();
        $mform->addElement('filemanager', 'attachments', $L('review_files'), null,
            ['subdirs' => 0, 'maxfiles' => 10, 'maxbytes' => $maxmb * 1048576, 'accepted_types' => ['.docx']]);
        $mform->addHelpButton('attachments', 'review_files', 'local_researchcentral');
        $this->add_action_buttons(true, $L('review_save'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $rec = $data['recommendation'];
        if ($rec !== 'approve' && trim($data['comment']) === '') {
            $errors['comment'] = get_string('feedback_required', 'local_researchcentral');
        }
        $max = (int)($this->_customdata['maxgrade'] ?? 0);
        if ($max > 0 && $rec === 'approve') {
            $g = trim((string)($data['grade'] ?? ''));
            $n = str_replace(',', '.', $g);
            if ($g === '') {
                $errors['grade'] = get_string('mark_required', 'local_researchcentral');
            } else if (!is_numeric($n) || (float)$n < 0 || (float)$n > $max) {
                $errors['grade'] = get_string('mark_invalid', 'local_researchcentral', $max);
            }
        }
        return $errors;
    }
}
