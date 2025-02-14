<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The submission__files_uploaded event.
 *
 * @package    mod_peerwork
 * @copyright  2015 Amanda Doughty
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_peerwork\event;

defined('MOODLE_INTERNAL') || die();

/**
 * The mod_peerwork submission _files_uploaded event class.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - string filelist: List of content hashes of uploaded files.
 * }
 *
 * @package    mod_peerwork
 * @since      Moodle 2.8
 * @copyright  2015 Amanda Doughty
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_files_uploaded extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'peerwork_submission';
    }

    public static function get_name() {
        return get_string('eventsubmission_files_uploaded', 'mod_peerwork');
    }

    public function get_url() {
        return new \moodle_url('/mod/peerwork/view.php', ['id' => $this->contextinstanceid]);
    }

    public function get_description() {
        $list = $this->other['filelist'];
        $count = count($list);
        return "The user with id '{$this->userid}' uploaded {$count} file(s) " .
            "in the submission with id '{$this->objectid}'. The file hashes are: " . implode(', ', $list);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['filelist'])) {
            throw new \coding_exception('The \'filelist\' value must be set in other.');
        }
    }
}
