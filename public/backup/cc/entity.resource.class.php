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
 * @package   moodlecore
 * @subpackage backup-imscc
 * @copyright 2009 Mauro Rondinelli (mauro.rondinelli [AT] uvcms.com)
 * @copyright 2011 Darko Miletic (dmiletic@moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') or die('Direct access to this script is forbidden.');

class cc_resource extends entities {
    public function generate_node() {

        cc2moodle::log_action('Creating Resource mods');

        $response = '';
        $sheet_mod_resource = cc2moodle::loadsheet(SHEET_COURSE_SECTIONS_SECTION_MODS_MOD_RESOURCE);

        if (!empty(cc2moodle::$instances['instances'][MOODLE_TYPE_RESOURCE])) {
            foreach (cc2moodle::$instances['instances'][MOODLE_TYPE_RESOURCE] as $instance) {
                $response .= $this->create_node_course_modules_mod_resource($sheet_mod_resource, $instance);
            }
        }

        return $response;
    }

    private function create_node_course_modules_mod_resource($sheet_mod_resource, $instance) {
        global $CFG;

        require_once($CFG->libdir . '/validateurlsyntax.php');

        $link = '';
        $mod_alltext = '';
        $xpath = cc2moodle::newx_path(cc2moodle::$manifest, cc2moodle::$namespaces);

        if (
            $instance['common_cartriedge_type'] == cc2moodle::CC_TYPE_WEBCONTENT
            || $instance['common_cartriedge_type'] == cc2moodle::CC_TYPE_ASSOCIATED_CONTENT
        ) {
            $resource = $xpath->query('/imscc:manifest/imscc:resources/imscc:resource[@identifier="' .
                $instance['resource_indentifier'] . '"]/@href');
            if ($resource->length > 0) {
                $resource = !empty($resource->item(0)->nodeValue) ? $resource->item(0)->nodeValue : '';
            } else {
                $resource = '';
            }

            if (empty($resource)) {
                unset($resource);
                $resource = $xpath->query('/imscc:manifest/imscc:resources/imscc:resource[@identifier="' .
                    $instance['resource_indentifier'] . '"]/imscc:file/@href');
                if ($resource->length > 0) {
                    $resource = !empty($resource->item(0)->nodeValue) ? $resource->item(0)->nodeValue : '';
                } else {
                    $resource = '';
                }
            }

            if (!empty($resource)) {
                $link = $resource;
            }
        }

        if ($instance['common_cartriedge_type'] == cc2moodle::CC_TYPE_WEBLINK) {
            $external_resource = $xpath->query('/imscc:manifest/imscc:resources/imscc:resource[@identifier="' . $instance['resource_indentifier'] . '"]/imscc:file/@href')->item(0)->nodeValue;

            if ($external_resource) {
                $resource = $this->load_xml_resource(cc2moodle::$pathtomanifestfolder . DIRECTORY_SEPARATOR . $external_resource);

                if (!empty($resource)) {
                    $xpath = cc2moodle::newx_path($resource, cc2moodle::getresourcens());
                    $resource = $xpath->query('//url/@href');
                    if ($resource->length > 0) {
                        $rawlink = $resource->item(0)->nodeValue;
                        if (!validateUrlSyntax($rawlink, 's+')) {
                            $changed = rawurldecode($rawlink);
                            if (validateUrlSyntax($changed, 's+')) {
                                $link = $changed;
                            } else {
                                $link = 'http://invalidurldetected/';
                            }
                        } else {
                            $link = $rawlink;
                        }
                    }
                }
            }
        }

        $find_tags = ['[#mod_instance#]',
                           '[#mod_name#]',
                           '[#mod_type#]',
                           '[#mod_reference#]',
                           '[#mod_summary#]',
                           '[#mod_alltext#]',
                           '[#mod_options#]',
                           '[#date_now#]'];

        $mod_type      = 'file';
        $mod_options   = 'objectframe';
        $mod_reference = $link;

        if (!empty($link) && ($instance['common_cartriedge_type'] != cc2moodle::CC_TYPE_WEBLINK)) {
            $mod_reference = $this->normalise_file_path($link);
        }

        // Canvas pages AND assignments both arrive as HTML resources.
        if (!empty($link) && $this->is_html_resource($instance['common_cartriedge_type'], $link)) {
            $htmlcontent = $this->load_html_resource_content($link);
            if ($htmlcontent !== false) {
                $mod_type = 'html';
                $mod_alltext = $htmlcontent;
                $mod_reference = '';
                $mod_options = '';
            }
        }

        $replace_values = [
            $instance['instance'],
            self::safexml($instance['title']),
            $mod_type,
            $mod_reference,
            '',
            $mod_alltext,
            $mod_options,
            time(),
        ];

        return str_replace($find_tags, $replace_values, $sheet_mod_resource);
    }

    private function is_html_resource($cartype, $link) {
        if (
            $cartype != cc2moodle::CC_TYPE_WEBCONTENT
            && $cartype != cc2moodle::CC_TYPE_ASSOCIATED_CONTENT
        ) {
            return false;
        }

        $ext = strtolower(pathinfo($link, PATHINFO_EXTENSION));
        return in_array($ext, ['html', 'htm', 'xhtml']);
    }

    private function load_html_resource_content($link) {
        $rootpath = realpath(cc2moodle::$pathtomanifestfolder);
        $htmlpath = realpath($rootpath . DIRECTORY_SEPARATOR . $link);

        if ($rootpath === false || $htmlpath === false || !is_file($htmlpath)) {
            return false;
        }

        $fcontent = file_get_contents($htmlpath);
        $modalltext = clean_param($this->prepare_content($fcontent), PARAM_CLEANHTML);

        // Handles $IMS-CC-FILEBASE$ and ../web_resources/... paths.
        $modalltext = $this->update_sources($modalltext, dirname($link));

        if ($modalltext === '') {
            return '';
        }

        // Fallback for same-folder relative paths that update_sources may miss.
        $dirpath = dirname($htmlpath);
        $doc = new DOMDocument();
        $cdir = getcwd();
        chdir($dirpath);
        try {
            if ($modalltext!=='' && @$doc->loadHTML($modalltext)) {
                $xpath = new DOMXPath($doc);
                $attributes = ['href', 'src', 'background', 'archive', 'code'];
                $qtemplate = "//*[@##][not(contains(@##,'://'))]/@##";
                $query = '';
                foreach ($attributes as $attrname) {
                    if (!empty($query)) {
                        $query .= ' | ';
                    }
                    $query .= str_replace('##', $attrname, $qtemplate);
                }
                $list = $xpath->query($query);
                $searches = [];
                $replaces = [];
                foreach ($list as $resrc) {
                    $rpath = str_replace('$IMS-CC-FILEBASE$', '', $resrc->nodeValue);
                    $rtp = realpath($rpath);
                    if (($rtp !== false) && is_file($rtp)) {
                        $strip = str_replace('\\', '/', str_ireplace($rootpath, '', $rtp));
                        $strip = ltrim($strip, '/');
                        $strip = $this->normalise_file_path($strip);
                        $encodedfile = '$@FILEPHP@$' . str_replace('/', '$@SLASH@$', $strip);
                        $searches[] = $resrc->nodeValue;
                        $replaces[] = $encodedfile;
                    }
                }
                if (!empty($searches)) {
                    $modalltext = str_replace($searches, $replaces, $modalltext);
                }
            }
        } catch (Exception $e) {
            // Ignore DOM failures and keep the best content we already have.
        }
        chdir($cdir);

        return self::safexml($modalltext);
    }
}
