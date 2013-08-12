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
 * Unit tests for badges
 *
 * @package    core
 * @subpackage badges
 * @copyright  2013 onwards Totara Learning Solutions Ltd {@link http://www.totaralms.com/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Yuliya Bozhko <yuliya.bozhko@totaralms.com>
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/badgeslib.php');

class core_badgeslib_testcase extends advanced_testcase {
    protected $badgeid;

    protected function setUp() {
        global $DB;
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();

        $fordb = new stdClass();
        $fordb->id = null;
        $fordb->name = "Test badge";
        $fordb->description = "Testing badges";
        $fordb->timecreated = time();
        $fordb->timemodified = time();
        $fordb->usercreated = $user->id;
        $fordb->usermodified = $user->id;
        $fordb->issuername = "Test issuer";
        $fordb->issuerurl = "http://issuer-url.domain.co.nz";
        $fordb->expiredate = null;
        $fordb->expireperiod = null;
        $fordb->type = BADGE_TYPE_SITE;
        $fordb->courseid = null;
        $fordb->messagesubject = "Test message subject";
        $fordb->message = "Test message body";
        $fordb->attachment = 1;
        $fordb->notification = 0;
        $fordb->status = BADGE_STATUS_INACTIVE;

        $this->badgeid = $DB->insert_record('badge', $fordb, true);
    }

    public function test_create_badge() {
        $badge = new badge($this->badgeid);

        $this->assertInstanceOf('badge', $badge);
        $this->assertEquals($this->badgeid, $badge->id);
    }

    public function test_clone_badge() {
        $badge = new badge($this->badgeid);
        $newid = $badge->make_clone();
        $cloned_badge = new badge($newid);

        $this->assertEquals($badge->description, $cloned_badge->description);
        $this->assertEquals($badge->issuercontact, $cloned_badge->issuercontact);
        $this->assertEquals($badge->issuername, $cloned_badge->issuername);
        $this->assertEquals($badge->issuerurl, $cloned_badge->issuerurl);
        $this->assertEquals($badge->expiredate, $cloned_badge->expiredate);
        $this->assertEquals($badge->expireperiod, $cloned_badge->expireperiod);
        $this->assertEquals($badge->type, $cloned_badge->type);
        $this->assertEquals($badge->courseid, $cloned_badge->courseid);
        $this->assertEquals($badge->message, $cloned_badge->message);
        $this->assertEquals($badge->messagesubject, $cloned_badge->messagesubject);
        $this->assertEquals($badge->attachment, $cloned_badge->attachment);
        $this->assertEquals($badge->notification, $cloned_badge->notification);
    }

    public function test_badge_status() {
        $badge = new badge($this->badgeid);
        $old_status = $badge->status;
        $badge->set_status(BADGE_STATUS_ACTIVE);
        $this->assertAttributeNotEquals($old_status, 'status', $badge);
        $this->assertAttributeEquals(BADGE_STATUS_ACTIVE, 'status', $badge);
    }

    public function test_delete_badge() {
        $badge = new badge($this->badgeid);
        $badge->delete();
        // We don't actually delete badges. We archive them.
        $this->assertAttributeEquals(BADGE_STATUS_ARCHIVED, 'status', $badge);
    }

    public function test_create_badge_criteria() {
        $badge = new badge($this->badgeid);
        $criteria_overall = badgecriteria_award::build(array('criteriatype' => 'overall', 'badgeid' => $badge->id));
        $criteria_overall->save(array('agg' => BADGE_CRITERIA_AGGREGATION_ALL));

        list($validcriteria, $invalidcriteria) = $badge->get_criteria();
        $this->assertCount(1, $validcriteria);

        $criteria_profile = badgecriteria_award::build(array('criteriatype' => 'profile', 'badgeid' => $badge->id));
        $params = array('agg' => BADGE_CRITERIA_AGGREGATION_ALL, 'field_address' => 'address');
        $criteria_profile->save($params);

        list($validcriteria, $invalidcriteria) = $badge->get_criteria();
        $this->assertCount(2, $validcriteria);
    }

    public function test_delete_badge_criteria() {
        $criteria_overall = badgecriteria_award::build(array('criteriatype' => 'overall', 'badgeid' => $this->badgeid));
        $criteria_overall->save(array('agg' => BADGE_CRITERIA_AGGREGATION_ALL));
        $badge = new badge($this->badgeid);

        $this->assertInstanceOf('badgecriteria_overall_award', $badge->criteria['overall']);

        $badge->criteria['overall']->delete();
        list($validcriteria, $invalidcriteria) = $badge->get_criteria();
        $this->assertEmpty($validcriteria);
    }

    public function test_badge_awards() {
        $badge = new badge($this->badgeid);
        $user1 = $this->getDataGenerator()->create_user();

        $badge->issue($user1->id, true);
        $this->assertTrue($badge->is_issued($user1->id));

        $user2 = $this->getDataGenerator()->create_user();
        $badge->issue($user2->id, true);
        $this->assertTrue($badge->is_issued($user2->id));

        $this->assertCount(2, $badge->get_awards());
    }

    public function data_for_invalid_criteria_test() {
        return array(
            // When aggregation is all, invalid criteria should prevent awarding.
            array(BADGE_CRITERIA_AGGREGATION_ALL, 0),
            // When aggregation is any, badge should be issued regardless of invalid criteria.
            array(BADGE_CRITERIA_AGGREGATION_ANY, 1),
        );
    }

    /**
     * @dataProvider data_for_invalid_criteria_test
     */
    public function test_badge_with_invalid_criteria($aggmethod, $awardcount) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/badges/lib/awardlib.php');
        $badge = new badge($this->badgeid);
        $criteria_overall = badgecriteria_award::build(array('criteriatype' => 'overall', 'badgeid' => $badge->id));
        $criteria_overall->save(array('agg' => $aggmethod));

        // Create a badge with 'manual' criteria.
        $roleid = $DB->get_field('role', 'id', array('shortname' => 'manager'));
        $criteria_manual = badgecriteria_award::build(array('criteriatype' => 'manual', 'badgeid' => $badge->id));
        $params = array('agg' => BADGE_CRITERIA_AGGREGATION_ALL, 'role_' . $roleid => $roleid);
        $criteria_manual->save($params);

        // Activate the badge.
        $badge->set_status(BADGE_STATUS_ACTIVE);

        // Manually add an invalid criteria type to this badge.
        $badcriteria = new stdClass();
        $badcriteria->badgeid = $badge->id;
        $badcriteria->criteriatype = 'badtype';
        $badcriteria->method = 1;
        $DB->insert_record('badge_criteria', $badcriteria);

        list($badge->criteria, $badge->invalidcriteria) = $badge->get_criteria();

        // Now award manual criterion and see if the overall badge is awarded.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        if (process_manual_award($user1->id, $user2->id, $roleid, $badge->id)) {
            $data = new stdClass();
            $data->crit = $badge->criteria['manual'];
            $data->userid = $user1->id;
            badges_award_handle_manual_criteria_review($data, true);
        }

        $this->assertCount($awardcount, $badge->get_awards());
    }

    public function data_for_message_from_template() {
        return array(
            array(
                'This is a message with no variables',
                array(), // no params
                'This is a message with no variables'
            ),
            array(
                'This is a message with %amissing% variables',
                array(), // no params
                'This is a message with %amissing% variables'
            ),
            array(
                'This is a message with %one% variable',
                array('one' => 'a single'),
                'This is a message with a single variable'
            ),
            array(
                'This is a message with %one% %two% %three% variables',
                array('one' => 'more', 'two' => 'than', 'three' => 'one'),
                'This is a message with more than one variables'
            ),
            array(
                'This is a message with %three% %two% %one%',
                array('one' => 'variables', 'two' => 'ordered', 'three' => 'randomly'),
                'This is a message with randomly ordered variables'
            ),
            array(
                'This is a message with %repeated% %one% %repeated% of variables',
                array('one' => 'and', 'repeated' => 'lots'),
                'This is a message with lots and lots of variables'
            ),
        );
    }

    /**
     * @dataProvider data_for_message_from_template
     */
    public function test_badge_message_from_template($message, $params, $result) {
        $this->assertEquals(badge_message_from_template($message, $params), $result);
    }

}
