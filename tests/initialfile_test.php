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

namespace mod_collabora;

use mod_collabora\api\collabora_fs;

/**
 * Tests for handling a missing initial file.
 *
 * A missing initial file used to break the whole course (initialfilemissing), for example
 * after restoring a backup of an old plugin version which came without initial files.
 *
 * @package   mod_collabora
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class initialfile_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /**
     * Setup function to create a course we can test with.
     * @return void
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Create an activity and remove its initial and document files.
     *
     * @param  string    $format
     * @return \stdClass The collabora record with the property cmid.
     */
    private function create_broken_activity(string $format = 'text'): \stdClass {
        $collabora = $this->getDataGenerator()->create_module(
            'collabora',
            ['course' => $this->course, 'format' => $format, 'initialtext' => 'Test text']
        );
        $context = \context_module::instance($collabora->cmid);
        $fs      = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_collabora', collabora_fs::FILEAREA_INITIAL);
        $fs->delete_area_files($context->id, 'mod_collabora', collabora_fs::FILEAREA_GROUP);
        $this->assertFalse(util::has_initial_file($context));

        return $collabora;
    }

    /**
     * The initial file of a text activity is rebuilt from the settings.
     *
     * @covers \mod_collabora\util::fix_legacy_initfiles
     * @return void
     */
    public function test_fix_rebuilds_text_file(): void {
        $collabora = $this->create_broken_activity('text');
        $cm        = get_coursemodule_from_id('collabora', $collabora->cmid, 0, false, MUST_EXIST);
        $context   = \context_module::instance($cm->id);

        $this->assertTrue(util::fix_legacy_initfiles($collabora, $cm));
        $this->assertTrue(util::has_initial_file($context));
        // Nothing to do the second time.
        $this->assertFalse(util::fix_legacy_initfiles($collabora, $cm));
    }

    /**
     * An uploaded file can not be rebuilt, so an empty placeholder is stored.
     *
     * @covers \mod_collabora\util::fix_legacy_initfiles
     * @covers \mod_collabora\util::store_placeholder_initial_file
     * @return void
     */
    public function test_fix_stores_placeholder_for_upload(): void {
        global $DB;

        $collabora = $this->create_broken_activity('text');
        $DB->set_field('collabora', 'format', util::FORMAT_UPLOAD, ['id' => $collabora->id]);
        $collabora = $DB->get_record('collabora', ['id' => $collabora->id]);
        $cm        = get_coursemodule_from_instance('collabora', $collabora->id, 0, false, MUST_EXIST);
        $context   = \context_module::instance($cm->id);

        $this->assertTrue(util::fix_legacy_initfiles($collabora, $cm));
        $this->assertDebuggingCalled();
        $this->assertTrue(util::has_initial_file($context));

        $files = get_file_storage()->get_area_files($context->id, 'mod_collabora', collabora_fs::FILEAREA_INITIAL, false, 'id', false);
        $this->assertCount(1, $files);
        $this->assertSame('docx', pathinfo(reset($files)->get_filename(), PATHINFO_EXTENSION));
    }

    /**
     * Opening an activity without initial file rebuilds the file instead of throwing an exception.
     *
     * @covers \mod_collabora\api\collabora_fs
     * @return void
     */
    public function test_collabora_fs_heals_missing_initial_file(): void {
        global $USER;

        $collabora = $this->create_broken_activity('text');
        $context   = \context_module::instance($collabora->cmid);

        $collaborafs = new collabora_fs($collabora, $context, 0, $USER->id);

        $this->assertNotEmpty($collaborafs);
        $this->assertTrue(util::has_initial_file($context));
    }

    /**
     * Restoring a backup without initial files must neither fail nor leave the course broken.
     *
     * @covers \restore_collabora_activity_structure_step
     * @return void
     */
    public function test_restore_backup_without_initial_files(): void {
        global $CFG, $USER, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $this->getDataGenerator()->create_module(
            'collabora',
            ['course' => $this->course, 'format' => 'wordprocessor']
        );

        // Create a backup without users and remove the initial files like a backup of the old plugin versions.
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(0);
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $file     = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $tempdir = make_backup_temp_directory($backupid);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $tempdir);
        $dom = new \DOMDocument();
        $dom->load($tempdir . '/files.xml');
        foreach (iterator_to_array($dom->getElementsByTagName('file')) as $node) {
            $component = $node->getElementsByTagName('component')->item(0)->nodeValue;
            $filearea  = $node->getElementsByTagName('filearea')->item(0)->nodeValue;
            if ($component === 'mod_collabora' && $filearea === collabora_fs::FILEAREA_INITIAL) {
                $node->parentNode->removeChild($node);
            }
        }
        $dom->save($tempdir . '/files.xml');

        // Restore it into a new course.
        $newcourseid = \restore_dbops::create_new_course('Restored', 'restored', $this->course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(0);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $instances = $DB->get_records('collabora', ['course' => $newcourseid]);
        $this->assertCount(1, $instances);
        $instance = reset($instances);
        $cm       = get_coursemodule_from_instance('collabora', $instance->id, $newcourseid, false, MUST_EXIST);
        $this->assertTrue(util::has_initial_file(\context_module::instance($cm->id)));
        // The course cache can be built.
        get_fast_modinfo($newcourseid);
    }
}
