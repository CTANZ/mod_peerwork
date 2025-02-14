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
 * The peer_grade_created event.
 *
 * @package    mod_peerwork
 * @copyright  2015 Amanda Doughty
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
 *      - int grade: Grade awarded.
 * }
 *
 * @package    mod_peerwork
 * @since      Moodle 2.8
 * @copyright  2015 Amanda Doughty
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class peer_grade_created extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'peerwork_peers';
    }

    public static function get_name() {
        return get_string('eventpeer_grade_created', 'mod_peerwork');
    }

    public function get_url() {
        return new \moodle_url('/mod/peerwork/view.php', ['id' => $this->contextinstanceid]);
    }

    public function get_description() {
        return "User with id '{$this->userid}' gave their peer with id '{$this->relateduserid}' " .
            "the grade '{$this->other['grade']}'.";
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!$this->relateduserid) {
            throw new \coding_exception('The \'relateduserid\' value must be set.');
        }
        if (!isset($this->other['grade'])) {
            throw new \coding_exception('The \'grade\' value must be set in other.');
        }
    }
}