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
 * Grades released event.
 *
 * @package    mod_peerwork
 * @copyright  2019 Coventry University
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_peerwork\event;

defined('MOODLE_INTERNAL') || die();

/**
 * The mod_peerwork submission created event class.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int groupid: The group ID.
 * }
 *
 * @package    mod_peerwork
 * @copyright  2019 Coventry University
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grades_released extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'peerwork_submission';
    }

    public static function get_name() {
        return get_string('eventgradesreleased', 'mod_peerwork');
    }

    public function get_url() {
        return new \moodle_url('/mod/peerwork/view.php', ['id' => $this->contextinstanceid]);
    }

    public function get_description() {
        return "The user with id '$this->userid' released the grades of " .
            "peerwork with course module id '$this->contextinstanceid' " .
            "for group  '{$this->other['groupid']}'.";
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['groupid'])) {
            throw new \coding_exception('The \'groupid\' value must be set in other.');
        }
    }
}
