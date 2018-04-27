<?php
/**
 * CSV Plugin helper plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

if(!defined('DOKU_INC')) die('meh');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class helper_plugin_csv extends DokuWiki_Plugin {

    /**
     * Returns the default options
     *
     * @return array
     */
    public static function getDefaultOpt() {
        return array(
            'hdr_rows' => 1,
            'hdr_cols' => 0,
            'span_empty_cols' => 0,
            'maxlines' => 0,
            'offset' => 0,
            'file' => '',
            'delim' => ',',
            'enclosure' => '"',
            'escape' => '"',
            'content' => '',
            'filter' => array(),
            'output' => '',
            'outc' => 0,
            'outr' => 0,
        );
    }

    /**
     * Parse the options given in the syntax
     *
     * @param $optstr
     * @return array
     */
    public static function parseOptions($optstr) {
        global $INFO;

        // defaults
        $opt = helper_plugin_csv::getDefaultOpt();

        $filters = array();
        // parse options - https://regex101.com/r/tNdS9P/3/
        preg_match_all(
            '/([^ =\[\]]+)(?:\[(\d+)\](?:\[(\w)\])?)?(?:=((?:".*?")|(?:[^ ]+)))?/',
            $optstr,
            $matches,
            PREG_SET_ORDER
        );
        foreach($matches as $set) {
            $option = $set[1];
            $value = isset($set[4]) ? $set[4] : '';
            $value = trim($value, '"');

            if($option == 'filter') {
                $col = isset($set[2]) ? $set[2] : 1;
                $typ = isset($set[3]) ? $set[3] : 'g';
                $filters[$col] = array($value, $typ);
            } elseif($value === '') {
                $opt['file'] = $option;
            } else {
                $opt[$option] = $value;
            }
        }

        // fix tab delimiter
        if($opt['delim'] == 'tab') $opt['delim'] = "\t";

        // resolve local files
        if($opt['file'] !== '' && !preg_match('/^https?:\/\//i', $opt['file'])) {
            $opt['file'] = cleanID($opt['file']);
            if(!strlen(getNS($opt['file']))) {
                $opt['file'] = $INFO['namespace'] . ':' . $opt['file'];
            }
        }

        // create regexp filters
        foreach($filters as $col => $filter) {
            list($text, $type) = $filter;
            if($type != 'r') {
                $text = preg_quote_cb($text);
                $text = str_replace('\*', '.*?', $text);
                $text = '^' . $text . '$';
            }

            if(@preg_match("/$text/", null) === false) {
                msg("Invalid filter for column $col");
            } else {
                $opt['filter'][$col - 1] = $text; // use zero based index internally
            }
        }

        // prepare the value output
        list($c, $r) = explode(',', $opt['output']);
        $opt['outc'] = (int) $c;
        $opt['outr'] = (int) $r;
        if($opt['outc']) $opt['outc'] -= 1;
        if($opt['outr']) $opt['outr'] -= 1;

        return $opt;
    }

    /**
     * Load CSV data from the given file or remote address
     *
     * @param $file
     * @return string
     * @throws Exception
     */
    public static function loadContent($file) {
        // load file data
        if(preg_match('/^https?:\/\//i', $file)) {
            $http = new DokuHTTPClient();
            $content = $http->get($file);
            if($content === false) throw new \Exception('Failed to fetch remote CSV data');

        } else {
            if(auth_quickaclcheck(getNS($file) . ':*') < AUTH_READ) {
                throw new \Exception('Access denied to CSV data');
            }
            $file = mediaFN($file);
            if(!file_exists($file)) {
                throw new \Exception('requested local CSV file does not exist');
            }
            $content = io_readFile($file);
        }
        // if not valid UTF-8 is given we assume ISO-8859-1
        if(!utf8_check($content)) $content = utf8_encode($content);

        return $content;
    }

    /**
     * @param string $content
     * @param array $opt
     * @return array
     */
    public static function prepareData($content, $opt) {
        $data = array();

        // get the first row - it will define the structure
        $row = helper_plugin_csv::csv_explode_row($content, $opt['delim'], $opt['enclosure'], $opt['escape']);
        $maxcol = count($row);
        $line = 0;

        while($row !== false) {
            // make sure we have enough columns
            $row = array_pad($row, $maxcol, '');

            if($line < $opt['hdr_rows']) {
                // if headers are wanted, always add them
                $data[] = $row;
            } elseif($opt['offset'] && $line < $opt['offset'] + $opt['hdr_rows']) {
                // ignore the line
            } elseif($opt['maxlines'] && $line >= $opt['maxlines'] + $opt['hdr_rows']) {
                // we're done
                break;
            } else {
                // check filters
                $filterok = true;
                foreach($opt['filter'] as $col => $filter) {
                    if(!preg_match("/$filter/i", $row[$col])) {
                        $filterok = false;
                        break;
                    }
                }

                // add the line
                if($filterok) {
                    $data[] = $row;
                }
            }

            $line++;
            $row = helper_plugin_csv::csv_explode_row($content, $opt['delim'], $opt['enclosure'], $opt['escape']);
        }

        return $data;
    }

    /**
     * Reads one CSV line from the given string
     *
     * Should handle embedded new lines, escapes, quotes and whatever else CSVs tend to have
     *
     * Note $delim, $enc, $esc have to be one ASCII character only! The encoding of the content is not
     * handled here but is read byte by byte - if you need conversions do it on the output
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     * @param string $str Input string, first CSV line will be removed
     * @param string $delim Delimiter character
     * @param string $enc Enclosing character
     * @param string $esc Escape character
     * @return array|boolean fields found on the line, false when no more lines could be found
     */
    public static function csv_explode_row(&$str, $delim = ',', $enc = '"', $esc = '\\') {
        $len = strlen($str);

        $infield = false;
        $inenc = false;

        $fields = array();
        $word = '';

        for($i = 0; $i < $len; $i++) {
            // convert to unix line endings
            if($str[$i] == "\015") {
                if($str[($i + 1)] != "\012") {
                    $str[$i] = "\012";
                } else {
                    $i++;
                    if($i >= $len) break;
                }
            }

            // simple escape that is not an enclosure
            if($str[$i] == $esc && $esc != $enc) {
                $i++; // skip this char and take next as is
                $word .= $str[$i];
                $infield = true; // we are obviously in a field
                continue;
            }

            /*
             * Now decide special cases depending on current field and enclosure state
             */
            if(!$infield) { // not in field

                // we hit a delimiter even though we're not in a field - an empty field
                if($str[$i] == $delim) {
                    $fields[] = $word;
                    $word = '';
                    $infield = false;
                    $inenc = false;
                    continue;
                }

                // a newline - an empty field as well, but we're done with this line
                if($str[$i] == "\n") {
                    $infield = false;
                    $inenc = false;

                    //we saw no fields or content yet? empty line! skip it.
                    if(!count($fields) && $word === '') continue;

                    // otherwise add field
                    $fields[] = $word;
                    $word = '';
                    break;
                }

                // we skip leading whitespace when we're not in a field yet
                if($str[$i] === ' ') {
                    continue;
                }

                // cell starts with an enclosure
                if($str[$i] == $enc) {
                    // skip this one but open an enclosed field
                    $infield = true;
                    $inenc = true;
                    continue;
                }

                // still here? whatever is here, is content and starts a field
                $word .= $str[$i];
                $infield = true;
                $inenc = false;

            } elseif($inenc) { // in field and enclosure

                // we have an escape char that is an enclosure and the next char is an enclosure, too
                if($str[$i] == $esc && $esc == $enc && $str[$i + 1] == $esc) {
                    $i++; // skip this char and take next as is
                    $word .= $str[$i];
                    continue;
                }

                // we have an enclosure char
                if($str[$i] == $enc) {
                    // skip this one but close the enclosure
                    $infield = true;
                    $inenc = false;
                    continue;
                }

                // still here? just add more content
                $word .= $str[$i];

            } else { // in field but no enclosure

                // a delimiter - next field please
                if($str[$i] == $delim) {
                    $fields[] = $word;
                    $word = '';
                    $infield = false;
                    $inenc = false;
                    continue;
                }

                // EOL - we're done with the line
                if($str[$i] == "\n") {
                    $infield = false;
                    $inenc = false;

                    //we saw no fields or content yet? empty line! skip it.
                    if(!count($fields) && $word === '') continue;

                    $fields[] = $word;
                    $word = '';
                    break;
                }

                // still here? just add more content
                $word .= $str[$i];
            }
        }

        // did we hit the end?
        if($infield && ($word || count($fields))) {
            $fields[] = $word;
        }

        // shorten the string by the stuff we read
        $str = substr($str, $i + 1);

        if(!count($fields)) return false;
        return $fields;
    }
}

