<?php
namespace local_researchcentral\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/** Ask a question (new thread) or post a reply, depending on customdata['mode']. */
class thread_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mode = $this->_customdata['mode'];
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        if ($mode === 'thread') {
            $mform->addElement('text', 'subject', get_string('forum_subject', 'local_researchcentral'), ['size' => 70, 'maxlength' => 255]);
            $mform->setType('subject', PARAM_TEXT);
            $mform->addRule('subject', null, 'required', null, 'client');
        }
        $mform->addElement('textarea', 'message', get_string($mode === 'thread' ? 'forum_question' : 'forum_yourreply', 'local_researchcentral'),
            ['rows' => $mode === 'thread' ? 8 : 5, 'cols' => 70]);
        $mform->setType('message', PARAM_TEXT);
        $mform->addRule('message', null, 'required', null, 'client');
        $this->add_action_buttons($mode === 'thread', get_string($mode === 'thread' ? 'forum_post_thread' : 'forum_post_reply', 'local_researchcentral'));
    }
}
