<?php
/*
 * CSV Plugin: displays a cvs formatted file or inline data as a table
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Steven Danz <steven-danz@kc.rr.com>
 * @author     Gert
 * @author     Andreas Gohr <gohr@cosmocode.de>
 * @author     Jerry G. Geiger <JerryGeiger@web.de>
 */

if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_csv extends DokuWiki_Syntax_Plugin {

    /**
     * What kind of syntax are we?
     */
    function getType() {
        return 'substition';
    }

    /**
     * Where to sort in?
     */
    function getSort() {
        return 155;
    }

    /**
     * Paragraph Type
     */
    function getPType() {
        return 'block';
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern("<csv[^>]*>.*?(?:<\/csv>)", $mode, 'plugin_csv');
    }

    /**
     * Handle the matches
     */
    function handle($match, $state, $pos, Doku_Handler &$handler) {
        global $INFO;
        $match = substr($match, 4, -6);

        //default options
        $opt = array(
            'hdr_rows'        => 1,
            'hdr_cols'        => 0,
            'span_empty_cols' => 0,
            'file'            => '',
            'delim'           => ',',
            'content'         => ''
        );

        list($optstr, $opt['content']) = explode('>', $match, 2);
        unset($match);

        // parse options
        $optsin = explode(' ', $optstr);
        foreach($optsin as $o) {
            $o = trim($o);
            if(preg_match('/(\w+)=(.*)/', $o, $matches)) {
                $opt[$matches[1]] = $matches[2];
            } elseif($o) {
                if(preg_match('/^https?:\/\//i', $o)) {
                    $opt['file'] = $o;
                } else {
                    $opt['file'] = cleanID($o);
                    if(!strlen(getNS($opt['file'])))
                        $opt['file'] = $INFO['namespace'].':'.$opt['file'];
                }
            }
        }
        if($opt['delim'] == 'tab') $opt['delim'] = "\t";

        return $opt;
    }

    /**
     * Create output
     */
    function render($mode, Doku_Renderer &$renderer, $opt) {
        if($mode == 'metadata') return false;

        // load file data
        if($opt['file']) {
            if(preg_match('/^https?:\/\//i', $opt['file'])) {
                require_once(DOKU_INC.'inc/HTTPClient.php');
                $http           = new DokuHTTPClient();
                $opt['content'] = $http->get($opt['file']);
                if(!$opt['content']) {
                    $renderer->cdata('Failed to fetch remote CSV data');
                    return true;
                }
            } else {
                $opt['file']             = cleanID($opt['file']);
                $renderer->info['cache'] = false; //no caching
                if(auth_quickaclcheck(getNS($opt['file']).':*') < AUTH_READ) {
                    $renderer->cdata('Access denied to CSV data');
                    return true;
                } else {
                    $file           = mediaFN($opt['file']);
                    $opt['content'] = io_readFile($file);
                    // if not valid UTF-8 is given we assume ISO-8859-1
                    if(!utf8_check($opt['content'])) $opt['content'] = utf8_encode($opt['content']);
                }
            }
        }

        $content =& $opt['content'];

        // clear any trailing or leading empty lines from the data set
        $content = preg_replace('/[\r\n]*$/', "", $content);
        $content = preg_replace('/^\s*[\r\n]*/', "", $content);

        if(!trim($content)) {
            $renderer->cdata('No csv data found');
        }
        $rows   = array();
        $maxcol = 0;
        $maxrow = 0;
        while($content != "") {
            $thisrow = $this->csv_explode_row($content, $opt['delim']);
            if($maxcol < count($thisrow))
                $maxcol = count($thisrow);
            array_push($rows, $thisrow);
            //$cells = $this->csv_explode_row($content,$opt['delim']);
            // some spreadsheet systems (i.e., excell) appear to
            // denote column spans with a completely empty cell
            // (to adjacent commas) and an 'empty' cell will
            // contain at least one blank space, so if the user
            // asks, use that for attempting to span columns
            // together
            $maxrow++;
        }
        // render table we need values e.g. for ODT plugin ... -jerry
        $renderer->table_open($maxcol, $maxrow);
        $row = 1;
        foreach($rows as $cells) {
            $renderer->tablerow_open();
            $spans   = array();
            $span    = 0;
            $current = 0;
            foreach($cells as $cell) {
                if($cell == '' && $opt['span_empty_cols']) {
                    $spans[$current] = 0;
                    $spans[$span]++;
                } else {
                    $spans[$current] = 1;
                    $span            = $current;
                }
                $current++;
            }
            //handle empty line feature ;-) jerry
            if($current < 2) {
                $spans[0] = $maxcol;
            }
            $current = 0;
            foreach($cells as $cell) {
                $cell = preg_replace('/\\\\\\\\/', ' ', $cell);
                if($spans[$current] > 0) {
                    $align = 'left';
                    if($spans[$current] > 1) {
                        $align = 'center';
                    }
                    if($row <= $opt['hdr_rows'] ||
                        $current < $opt['hdr_cols'] || // empty line feature
                        ($current == 0 && $spans[0] == $maxcol)
                    ) {
                        $renderer->tableheader_open($spans[$current], $align);
                    } else {
                        $renderer->tablecell_open($spans[$current], $align);
                    }
                    $renderer->cdata($cell);
                    if($row <= $opt['hdr_rows'] ||
                        $current < $opt['hdr_cols'] ||
                        ($current == 0 && $spans[0] == $maxcol)
                    ) {
                        $renderer->tableheader_close();
                    } else {
                        $renderer->tablecell_close();
                    }
                }
                $current++;
            }
            $renderer->tablerow_close();
            $row++;
        }
        $renderer->table_close();

        return true;
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
    function csv_explode_row(&$str, $delim = ',', $enc = '"', $esc = '\\') {
        $len    = strlen($str);

        $infield = false;
        $inenc = false;

        $fields = array();
        $word   = '';

        for($i = 0; $i < $len; $i++) {
            // convert to unix line endings
            if($str[$i] == "\015"){
                if($str[($i + 1)] != "\012"){
                    $str[$i] = "\012";
                }else{
                    $i++;
                    if($i >= $len) break;
                }
            }

            // simple escape that is not an enclosure
            if($str[$i] == $esc && $esc != $enc) {
                $i++; // skip this char and take next as is
                $word .= $str[$i];
                $infield = true;  // we are obviously in a field
                continue;
            }

            /*
             * Now decide special cases depending on current field and enclosure state
             */
            if(!$infield) { // not in field

                // we hit a delimiter even though we're not in a field - an empty field
                if($str[$i] == $delim){
                    $fields[] = $word;
                    $word = '';
                    $infield = false;
                    $inenc = false;
                    continue;
                }

                // a newline - an empty field as well, but we're done with this line
                if($str[$i] == "\n"){
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

            } elseif ($inenc) { // in field and enclosure

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
        if($infield && ($word || count($fields))){
            $fields[] = $word;
        }

        // shorten the string by the stuff we read
        $str   = substr($str, $i+1);

        if(!count($fields)) return false;
        return $fields;
    }
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
