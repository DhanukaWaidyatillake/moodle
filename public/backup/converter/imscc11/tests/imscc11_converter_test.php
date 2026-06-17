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

namespace core_backup;

use convert_factory;
use convert_helper;
use imscc11_converter;
use manifest_validator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/convert_includes.php');
require_once($CFG->dirroot . '/backup/converter/imscc11/lib.php');

/**
 * Unit tests for the IMS Common Cartridge 1.1 converter.
 *
 * @package    core_backup
 * @subpackage backup-convert
 * @category   test
 * @copyright  2026 A K M Safat Shahin <safat.shahin@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class imscc11_converter_test extends \advanced_testcase {

    /** @var string the name of the directory containing the unpacked IMS CC backup */
    protected $tempdir;

    /** @var string the full path to the directory containing the unpacked IMS CC backup */
    protected $tempdirpath;

    protected function setUp(): void {
        parent::setUp();

        $this->tempdir = convert_helper::generate_id('unittest');
        $this->tempdirpath = make_backup_temp_directory($this->tempdir);
    }

    protected function tearDown(): void {
        global $CFG;

        if (empty($CFG->keeptempdirectoriesonbackup)) {
            fulldelete($this->tempdirpath);
        }
        parent::tearDown();
    }

    public function test_invalid_resource_rights_metadata_is_removed_before_retrying_validation(): void {
        global $CFG;

        $manifest = $this->tempdirpath . '/imsmanifest.xml';
        $this->write_manifest($manifest, true);
        check_dir_exists($this->tempdirpath . '/course_settings');
        file_put_contents($this->tempdirpath . '/course_settings/canvas_export.txt', 'canvas');

        $validator = new manifest_validator($CFG->dirroot . '/backup/cc/schemas11');
        \error_messages::instance()->reset();
        $this->assertFalse($validator->validate($manifest));

        $converter = convert_factory::get_converter('imscc11', $this->tempdir);
        $method = new \ReflectionMethod(imscc11_converter::class, 'normalise_resource_metadata');
        $method->setAccessible(true);
        $this->assertGreaterThan(0, $method->invoke($converter, $manifest));

        \error_messages::instance()->reset();
        $this->assertTrue($validator->validate($manifest));

        $xmldoc = new \DOMDocument();
        $xmldoc->load($manifest);
        $xpath = new \DOMXPath($xmldoc);
        $xpath->registerNamespace('imscc', 'http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1');
        $this->assertEquals(0, $xpath->evaluate('count(/imscc:manifest/imscc:resources/imscc:resource/imscc:metadata)'));
    }

    public function test_resource_metadata_without_export_marker_is_not_changed(): void {
        $manifest = $this->tempdirpath . '/imsmanifest.xml';
        $this->write_manifest($manifest, true);

        $converter = convert_factory::get_converter('imscc11', $this->tempdir);
        $method = new \ReflectionMethod(imscc11_converter::class, 'normalise_resource_metadata');
        $method->setAccessible(true);
        $this->assertEquals(0, $method->invoke($converter, $manifest));

        $this->assertStringContainsString('<lom:rights>', file_get_contents($manifest));
    }

    public function test_true_false_answers_can_use_non_literal_identifiers(): void {
        $question = [
            'id' => 1,
            'answers' => [
                [
                    'id' => 1,
                    'identifier' => '1327',
                    'title' => 'True',
                    'score' => '1.0000000',
                    'feedback' => '',
                ],
                [
                    'id' => 2,
                    'identifier' => '5033',
                    'title' => 'False',
                    'score' => '0.0000000',
                    'feedback' => '',
                ],
            ],
        ];

        $xml = $this->create_true_false_question_node($question);

        $this->assertStringContainsString('<TRUEANSWER>1</TRUEANSWER>', $xml);
        $this->assertStringContainsString('<FALSEANSWER>2</FALSEANSWER>', $xml);
    }

    public function test_true_false_answers_still_reject_unknown_identifiers(): void {
        $question = [
            'id' => 1,
            'answers' => [
                [
                    'id' => 1,
                    'identifier' => '1327',
                    'title' => 'Yes',
                    'score' => '1.0000000',
                    'feedback' => '',
                ],
                [
                    'id' => 2,
                    'identifier' => '5033',
                    'title' => 'No',
                    'score' => '0.0000000',
                    'feedback' => '',
                ],
            ],
        ];

        $this->expectException(\coding_exception::class);
        $this->create_true_false_question_node($question);
    }

    public function test_file_paths_are_normalised_for_copied_files_and_references(): void {
        $filename = "Blooms's Taxonomy Model.jpg";
        $collidingfilename = 'Bloomss Taxonomy Model.jpg';
        $ampersandfilename = 'Work Prioritisation & Professional Development LG V1.0.pdf';

        $this->write_file_manifest([$filename, $collidingfilename, $ampersandfilename]);
        \entities11::reset_file_path_map();
        new \cc112moodle($this->tempdirpath . '/imsmanifest.xml');

        $entities = new \entities11();
        $entities->prepare_file_path_map();
        $entities->move_all_files();

        $safefilename = clean_param($filename, PARAM_PATH);
        $safecollidingfilename = 'Bloomss Taxonomy Model-1.jpg';
        $safeampersandfilename = clean_param($ampersandfilename, PARAM_PATH);

        $this->assertFileExists($this->tempdirpath . '/course_files/' . $safefilename);
        $this->assertFileExists($this->tempdirpath . '/course_files/' . $safecollidingfilename);
        $this->assertFileExists($this->tempdirpath . '/course_files/' . $safeampersandfilename);
        $this->assertFileDoesNotExist($this->tempdirpath . '/course_files/' . $filename);

        $html = '<p><img src="' . $filename . '" /><a href="' . $collidingfilename . '">Collision</a>'
            . '<a href="' . $ampersandfilename . '">File</a></p>';
        $html = $entities->update_sources($html);

        $this->assertStringContainsString('$@FILEPHP@$/' . $safefilename, $html);
        $this->assertStringContainsString('$@FILEPHP@$/' . $safecollidingfilename, $html);
        $this->assertStringContainsString('$@FILEPHP@$/' . $safeampersandfilename, $html);
    }

    /**
     * Creates Moodle XML for a true/false question.
     *
     * @param array $question question data
     * @return string Moodle XML
     */
    protected function create_true_false_question_node($question): string {
        global $CFG;

        $quiz = new \cc11_quiz();
        $method = new \ReflectionMethod(\cc11_quiz::class,
            'create_node_course_question_categories_question_category_question_true_false');
        $method->setAccessible(true);

        \cc112moodle::$path_to_manifest_folder = $this->tempdirpath;
        $currentdir = getcwd();
        chdir($CFG->dirroot . '/backup');
        try {
            return $method->invoke($quiz, $question);
        } finally {
            chdir($currentdir);
        }
    }

    /**
     * Writes a minimal IMS CC 1.1 manifest.
     *
     * @param string $manifest manifest path
     * @param bool $invalidmetadata whether to include invalid resource rights metadata
     */
    protected function write_manifest($manifest, $invalidmetadata): void {
        $metadata = '';
        if ($invalidmetadata) {
            $metadata = <<<XML
      <metadata>
        <lom:lom>
          <lom:rights>
            <lom:copyrightAndOtherRestrictions>
              <lom:value>yes</lom:value>
            </lom:copyrightAndOtherRestrictions>
          </lom:rights>
        </lom:lom>
      </metadata>
XML;
        }

        $content = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest1"
    xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1"
    xmlns:lom="http://ltsc.ieee.org/xsd/imsccv1p1/LOM/resource"
    xmlns:lomimscc="http://ltsc.ieee.org/xsd/imsccv1p1/LOM/manifest"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <metadata>
    <schema>IMS Common Cartridge</schema>
    <schemaversion>1.1.0</schemaversion>
    <lomimscc:lom>
      <lomimscc:general>
        <lomimscc:title>
          <lomimscc:string>IMSCC export</lomimscc:string>
        </lomimscc:title>
      </lomimscc:general>
    </lomimscc:lom>
  </metadata>
  <organizations>
    <organization identifier="org1" structure="rooted-hierarchy">
      <item identifier="root">
        <item identifier="item1" identifierref="resource1">
          <title>Resource</title>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource type="webcontent" identifier="resource1" href="index.html">
$metadata
      <file href="index.html"/>
    </resource>
  </resources>
</manifest>
XML;

        file_put_contents($manifest, $content);
        file_put_contents($this->tempdirpath . '/index.html', 'Hello');
    }

    /**
     * Writes a minimal IMS CC 1.1 manifest with file resources.
     *
     * @param array $files file paths
     */
    protected function write_file_manifest(array $files): void {
        $items = '';
        $resources = '';
        foreach ($files as $index => $file) {
            $identifier = 'resource' . $index;
            $escapedfile = htmlspecialchars($file, ENT_QUOTES | ENT_XML1);
            $items .= <<<XML
        <item identifier="item{$index}" identifierref="{$identifier}">
          <title>{$escapedfile}</title>
        </item>
XML;
            $resources .= <<<XML
    <resource type="webcontent" identifier="{$identifier}" href="{$escapedfile}">
      <file href="{$escapedfile}"/>
    </resource>
XML;
            file_put_contents($this->tempdirpath . '/' . $file, $file);
        }

        $content = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest1"
    xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <metadata>
    <schema>IMS Common Cartridge</schema>
    <schemaversion>1.1.0</schemaversion>
  </metadata>
  <organizations>
    <organization identifier="org1" structure="rooted-hierarchy">
      <item identifier="root">
{$items}
      </item>
    </organization>
  </organizations>
  <resources>
{$resources}
  </resources>
</manifest>
XML;

        file_put_contents($this->tempdirpath . '/imsmanifest.xml', $content);
    }
}
