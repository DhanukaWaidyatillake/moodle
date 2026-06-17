<?php

/**
 * Provides Common Cartridge v1.1 converter class
 *
 * @package    core
 * @subpackage backup-convert
 * @copyright  2011 Darko Miletic <dmiletic@moodlerooms.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/backup/converter/convertlib.php');
require_once($CFG->dirroot.'/backup/cc/includes/constants.php');
require_once($CFG->dirroot.'/backup/cc/cc112moodle.php');
require_once($CFG->dirroot.'/backup/cc/validator.php');


class imscc11_converter extends base_converter {

    /**
     * Log a message
     *
     * @see parent::log()
     * @param string $message message text
     * @param int $level message level {@example backup::LOG_WARNING}
     * @param null|mixed $a additional information
     * @param null|int $depth the message depth
     * @param bool $display whether the message should be sent to the output, too
     */
    public function log($message, $level, $a = null, $depth = null, $display = false) {
        parent::log('(imscc1) '.$message, $level, $a, $depth, $display);
    }

    /**
     * Detects the Common Cartridge 1.0 format of the backup directory
     *
     * @param string $tempdir the name of the backup directory
     * @return null|string backup::FORMAT_IMSCC11 if the Common Cartridge 1.1 is detected, null otherwise
     */
    public static function detect_format($tempdir) {
        $filepath = make_backup_temp_directory($tempdir, false);
        if (!is_dir($filepath)) {
            throw new convert_helper_exception('tmp_backup_directory_not_found', $filepath);
        }
        $manifest = cc112moodle::get_manifest($filepath);
        if (file_exists($manifest)) {
            // Looks promising, lets load some information.
            $handle = fopen($manifest, 'r');
            $xml_snippet = fread($handle, 1024);
            fclose($handle);

            // Check if it has the required strings.

            $xml_snippet = strtolower($xml_snippet);
            $xml_snippet = preg_replace('/\s*/m', '', $xml_snippet);
            $xml_snippet = str_replace("'", '', $xml_snippet);
            $xml_snippet = str_replace('"', '', $xml_snippet);

            $search_string = "xmlns=http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1";
            if (strpos($xml_snippet, $search_string) !== false) {
                return backup::FORMAT_IMSCC11;
            }
        }

        return null;
    }


    /**
     * Returns the basic information about the converter
     *
     * The returned array must contain the following keys:
     * 'from' - the supported source format, eg. backup::FORMAT_MOODLE1
     * 'to'   - the supported target format, eg. backup::FORMAT_MOODLE
     * 'cost' - the cost of the conversion, non-negative non-zero integer
     */
    public static function description() {

        return array(
                'from'  => backup::FORMAT_IMSCC11,
                'to'    => backup::FORMAT_MOODLE1,
                'cost'  => 10
        );
    }

    protected function execute() {
        global $CFG;

        $manifest = cc112moodle::get_manifest($this->get_tempdir_path());
        if (empty($manifest)) {
            throw new imscc11_convert_exception('No Manifest detected!');
        }

        $this->log('validating manifest', backup::LOG_DEBUG, null, 1);
        $validator = new manifest_validator($CFG->dirroot . '/backup/cc/schemas11');
        error_messages::instance()->reset();
        if (!$validator->validate($manifest)) {
            $validationerrors = error_messages::instance()->to_string(true);
            if ($this->normalise_resource_metadata($manifest)) {
                $this->log('normalised resource metadata', backup::LOG_DEBUG, null, 1);
                error_messages::instance()->reset();
                if (!$validator->validate($manifest)) {
                    $this->log('validation error(s): '.PHP_EOL.error_messages::instance(), backup::LOG_DEBUG, null, 2);
                    throw new imscc11_convert_exception(error_messages::instance()->to_string(true));
                }
            } else {
                $this->log('validation error(s): '.PHP_EOL.$validationerrors, backup::LOG_DEBUG, null, 2);
                throw new imscc11_convert_exception($validationerrors);
            }
        }
        $manifestdir = dirname($manifest);
        $cc112moodle = new cc112moodle($manifest);
        if ($cc112moodle->is_auth()) {
            throw new imscc11_convert_exception('protected_cc_not_supported');
        }
        $status = $cc112moodle->generate_moodle_xml();
        // Final cleanup.
        $xml_error = new libxml_errors_mgr(true);
        $mdoc = new DOMDocument();
        $mdoc->preserveWhiteSpace = false;
        $mdoc->formatOutput = true;
        $mdoc->validateOnParse = false;
        $mdoc->strictErrorChecking = false;
        if ($mdoc->load($manifestdir.'/moodle.xml', LIBXML_NONET)) {
            $mdoc->save($this->get_workdir_path().'/moodle.xml', LIBXML_NOEMPTYTAG);
        } else {
            $xml_error->collect();
            $this->log('validation error(s): '.PHP_EOL.error_messages::instance(), backup::LOG_DEBUG, null, 2);
            throw new imscc11_convert_exception(error_messages::instance()->to_string(true));
        }
        // Move the files to the workdir.
        rename($manifestdir.'/course_files', $this->get_workdir_path().'/course_files');
    }

    /**
     * Removes resource metadata that does not match the IMS CC 1.1 resource LOM profile.
     *
     * Some IMSCC exports can include resource-level LOM rights metadata in CC 1.1 packages. The CC 1.1
     * resource profile accepted by Moodle only permits educational metadata there, while Moodle's
     * converter does not use the invalid resource metadata for the restore. Keep this compatibility
     * step limited to known compatible exports and only call it after normal schema validation has failed.
     *
     * @param string $manifest the manifest file path
     * @return int number of metadata nodes or child nodes removed
     */
    protected function normalise_resource_metadata($manifest) {

        $manifestdir = dirname($manifest);
        if (!file_exists($manifestdir . '/course_settings/canvas_export.txt')) {
            return 0;
        }

        $xmldoc = new DOMDocument();
        $xmldoc->preserveWhiteSpace = false;
        $xmldoc->formatOutput = true;
        $xmldoc->validateOnParse = false;
        $xmldoc->strictErrorChecking = false;

        $xmlerror = new libxml_errors_mgr(true);
        if (!$xmldoc->load($manifest, LIBXML_NONET)) {
            $xmlerror->collect();
            return 0;
        }

        $xpath = cc112moodle::newx_path($xmldoc, cc112moodle::$namespaces);
        $metadata = $xpath->query('/imscc:manifest/imscc:resources/imscc:resource/imscc:metadata[lom:lom]');
        $changed = 0;

        foreach ($metadata as $metadatanode) {
            $lomnodes = $xpath->query('lom:lom', $metadatanode);
            if (empty($lomnodes) || $lomnodes->length === 0) {
                continue;
            }
            $lomnode = $lomnodes->item(0);

            $children = array();
            foreach ($lomnode->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $children[] = $child;
                }
            }

            foreach ($children as $child) {
                if ($child->namespaceURI !== cc112moodle::$namespaces['lom'] || $child->localName !== 'educational') {
                    $lomnode->removeChild($child);
                    $changed++;
                }
            }

            if ($xpath->evaluate('count(lom:educational)', $lomnode) == 0) {
                $metadatanode->parentNode->removeChild($metadatanode);
                $changed++;
            }
        }

        if ($changed) {
            $xmldoc->save($manifest);
        }

        return $changed;
    }

}

/**
 * Exception thrown by this converter
 */
class imscc11_convert_exception extends convert_exception {
}
