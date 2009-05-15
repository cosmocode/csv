<?php
/*
 * CSV Plugin: displays a cvs formatted file or inline data as a table
 * Usage:
 * <csv[ options] file></csv>
 * or
 * <csv[ options]>
 * "a","b","c"
 * "1","2","3"
 * </csv>
 *
 * Where the file is a wiki reference to a file in the media area
 * If you use .csv as the file extension, you will most likely need to add
 * an entry to mime.local.conf in the conf area so you can upload .csv files.
 * Something like this should get you started:
 *      csv     application/msexcel
 *
 * Using both the file and inline methods at the same time will result
 * in two tables being generated, any options defined will apply to both.
 *
 * The plugin allows for two options that can be set in the <csv> tag:
 *
 * hdr_rows=<n>, where <n> is the number or rows at the start of the
 * csv to encode as column headings. The default is 1.
 *
 * span_empty_cols=[0,1], this tells the plugin to create colspans for
 * each empty (two adjacent commas) cell following a cell with content.
 *
 * Embedded commas should be handled by surrounding the field
 * with "" (which most systems do by default).  If you need to preserve
 * the "" around a field, then it should be surrounded by "" as well.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Steven Danz (steven-danz@kc.rr.com)
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/*
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_csv extends DokuWiki_Syntax_Plugin {

    /*
     * return some info
     */
    function getInfo(){
        return array(
            'author' => 'Steven Danz',
            'email'  => 'steven-danz@kc.rr.com',
            'date'   => '2005-11-08',
            'name'   => 'CSV Plugin',
            'desc'   => 'Displays a CSV file, or inline CSV data, as a table',
            'url'    => 'http://www.dokuwiki.org/plugin:csv',
        );
    }

    /*
     * What kind of syntax are we?
     */
    function getType(){
        return 'container';
    }

    /*
     * Where to sort in?
     */
    function getSort(){
        return 155;
    }

    /*
     * Paragraph Type
     */
    function getPType(){
        return 'block';
    }

    /*
     * Connect pattern to lexer
     */
    function connectTo($mode) {
      $this->Lexer->addEntryPattern("<csv[^>]*>(?=.*?\x3C/csv\x3E)",$mode,'plugin_csv');
    }

    function postConnect() {
      $this->Lexer->addExitPattern("</csv>",'plugin_csv');
    }

    /*
     * Handle the matches
     */
    function handle($match, $state, $pos, &$handler){
        if ($state == DOKU_LEXER_ENTER) {
            // default values for options
            $hdr_rows  = 1;
            $span_cols = 0;
            $file      = '';

            /* process possible options */
            $match = trim(substr($match, 4, -1));
            $data = preg_split("/\s/",$match,-1);
            while (count($data) > 0) {
                $entry = array_shift($data);
                if (preg_match("/^hdr_rows=([0-9]*)/",$entry,$matches)) {
                    $hdr_rows = $matches[1];
                } elseif (preg_match("/^span_empty_cols=([0-9]*)/",$entry,$matches)) {
                    $span_cols = $matches[1];
                } else {
                    $file = $entry;
                }
            }

            if ($file != '') {
                return array('file', $file, $hdr_rows, $span_cols);
            } else {
                return array('options', $hdr_rows, $span_cols);
            }
        } elseif ($state == DOKU_LEXER_UNMATCHED) {
            // clear out all the spaces, if anything is left, use it
            $clean = preg_replace('/\s/', '', $match);
            if ($clean != '') {
                return array('inline', $match);
            }
        }
        return array();
    }

    /*
     * Create output
     */
    function render($mode, &$renderer, $data) {
        global $csv_hdr_rows;   // global since the lexer_enter sets these in one match,
        global $csv_span_cols;  // then uses them in for the data (for inline data)

        if($mode == 'xhtml'){
            if (!isset($csv_hdr_rows)) $csv_hdr_rows = 1;
            if (count($data) > 0) {
                $type = array_shift($data);
                $process = 1;
                if ($type == 'file') {
                    // prevent caching to ensure the included data is always fresh
                    // and so we don't cache any errors that might be generated
                    // from permission problems
                    $renderer->info['cache'] = FALSE;

                    $file = $data[0];
                    if (auth_quickaclcheck(getNS($file).':*') < AUTH_READ) {
                        $auth = 0;
                        $content = "";
                    } else {
                        $auth = 1;
                        $file = mediaFN($file);
                        $csv_hdr_rows = $data[1];
                        $csv_span_cols = $data[2];
                        if(@file_exists($file)) {
                            // grab the entire file as a string
                            $content = file_get_contents($file);
                        }
                    }
                } elseif ($type == 'options') {
                    $csv_hdr_rows = $data[0];
                    $csv_span_cols = $data[1];
                    $process = 0;
                } elseif ($type == 'inline') {
                    $content = $data[0];
                } else {
                    $renderer->doc .= "Not sure what this is about " . $type ;
                    $process = 0;
                }

                if ($process) {
                    // clean up the input data
                    // clear any trailing or leading empty lines from the data set
                    $content = preg_replace("/[\r\n]*$/","",$content);
                    $content = preg_replace("/^\s*[\r\n]*/","",$content);

                    // Not sure if PHP handles the DOS \r\n or Mac \r, so being paranoid
                    // and converting them if the exist to \n
                    $content = preg_replace("/\r\n/","\n",$content);
                    $content = preg_replace("/\r/","\n",$content);

                    if ($content != "") {
                        $renderer->table_open();
                        $row = 1;
                        while($content != "") {
                            $renderer->tablerow_open();
                            $cells = $this->csv_explode_row($content);
                            // some spreadsheet systems (i.e., excell) appear to
                            // denote column spans with a completely empty cell
                            // (to adjacent commas) and an 'empty' cell will
                            // contain at least one blank space, so if the user
                            // asks, use that for attempting to span columns
                            // together
                            $spans = array();
                            $span  = 0;
                            $current = 0;
                            foreach($cells as $cell) {
                                if ($cell == '' && $csv_span_cols) {
                                    $spans[$current] = 0;
                                    $spans[$span]++;
                                } else {
                                    $spans[$current] = 1;
                                    $span = $current;
                                }
                                $current++;
                            }
                            $current = 0;
                            foreach($cells as $cell) {
                                $cell = preg_replace('/\\\\\\\\/','<br>',$cell);
                                if ($spans[$current] > 0) {
                                    $align = 'left';
                                    if ($spans[$current] > 1) {
                                        $align = 'center';
                                    }
                                    if ($row <= $csv_hdr_rows) {
                                        $renderer->tableheader_open($spans[$current], $align);
                                    } else {
                                        $renderer->tablecell_open($spans[$current], $align);
                                    }
                                    $renderer->doc .= $cell;
                                    if ($row <= $csv_hdr_rows) {
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
                    } else {
                        if ($type == 'file') {
                            if ($auth == 0) {
                                $renderer->doc .= "You do not have authorization to read " . $data[0];
                            } elseif(@file_exists($file)) {
                                $renderer->doc .= "Data file from " . $data[0] . " is empty";
                            } else {
                                $renderer->doc .= "Could not locate " . $data[0];
                            }
                        }
                    }
                }
                return true;
            }
        }
        return false;
    }

    // Explode CSV string, consuming it as we go
    // RFC 4180 claims that a CSV is allowed to have a cell enclosed in ""
    // that embeds a newline.  Convert those newlines to \\ (trying to keep
    // to the DokuWiki syntax) which we will key off of later in render()
    // as an embedded newline.
    // Careful, there could be both embedded newlines, commas and quotes
    // One thing to remember is that a row must end with a newline
    function csv_explode_row(&$str, $delim = ',', $qual = "\"") {
        $len = strlen($str);
        $inside = false;
        $word = '';
        for ($i = 0; $i < $len; ++$i) {
            $next = $i+1;
            if ($str[$i]==$delim && !$inside) {
                $out[] = $word;
                $word = '';
            } elseif ($str[$i] == $qual && (!$inside || $next == $len || $str[$next] == $delim || $str[$next] == "\n")) {
                $inside = !$inside;
            } elseif ($str[$i] == $qual && $next != $len && $str[$next] == $qual) {
                $word .= $str[$i];
                $i++;
            } elseif ($str[$i] == "\n") {
                if ($inside) {
                    $word .= '\\\\';
                } else {
                    $str = substr($str, $next);
                    $out[] = $word;
                    return $out;
                }
            } else {
                $word .= $str[$i];
            }
        }
        $str = substr($str, $next);
        $out[] = $word;
        return $out;
    }
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
