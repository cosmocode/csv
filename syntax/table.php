<?php
/**
 * CSV Plugin: displays a cvs formatted file or inline data as a table
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Steven Danz <steven-danz@kc.rr.com>
 * @author     Gert
 * @author     Andreas Gohr <gohr@cosmocode.de>
 * @author     Jerry G. Geiger <JerryGeiger@web.de>
 */

if(!defined('DOKU_INC')) die('meh');

/**
 * Display CSV data as table
 */
class syntax_plugin_csv_table extends DokuWiki_Syntax_Plugin {

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
     * @inheritdoc
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<csv[^>]*>.*?(?:<\/csv>)', $mode, 'plugin_csv_table');
    }

    /**
     * @inheritdoc
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        $match = substr($match, 4, -6); // <csv ... </csv>

        list($optstr, $content) = explode('>', $match, 2);
        $opt = helper_plugin_csv::parseOptions($optstr);
        $opt['content'] = $content;

        return $opt;
    }

    /**
     * @inheritdoc
     */
    function render($mode, Doku_Renderer $renderer, $opt) {
        if($mode == 'metadata') return false;

        // load file data
        if($opt['file']) {
            if(preg_match('/^https?:\/\//i', $opt['file'])) {
                $http = new DokuHTTPClient();
                $opt['content'] = $http->get($opt['file']);
                if($opt['content'] === false) {
                    $renderer->cdata('Failed to fetch remote CSV data');
                    return true;
                }
            } else {
                $renderer->info['cache'] = false;
                if(auth_quickaclcheck(getNS($opt['file']) . ':*') < AUTH_READ) {
                    $renderer->cdata('Access denied to CSV data');
                    return true;
                } else {
                    $file = mediaFN($opt['file']);
                    $opt['content'] = io_readFile($file);
                }
            }
            // if not valid UTF-8 is given we assume ISO-8859-1
            if(!utf8_check($opt['content'])) $opt['content'] = utf8_encode($opt['content']);
        }

        // check if there is content
        $content =& $opt['content'];
        $content = trim($content);
        if($content === '') {
            $renderer->cdata('No csv data found');
            return true;
        }

        $data = helper_plugin_csv::prepareData($content, $opt);
        $maxcol = count($data[0]);
        $line = 0;

        foreach($data as $row) {
            // render
            $renderer->tablerow_open();
            for($i = 0; $i < $maxcol;) {
                $span = 1;
                // lookahead to find spanning cells
                if($opt['span_empty_cols']) {
                    for($j = $i + 1; $j < $maxcol; $j++) {
                        if($row[$j] === '') {
                            $span++;
                        } else {
                            break;
                        }
                    }
                }

                // open cell
                if($line < $opt['hdr_rows'] || $i < $opt['hdr_cols']) {
                    $renderer->tableheader_open($span);
                } else {
                    $renderer->tablecell_open($span);
                }

                // print cell content, call linebreak() for newlines
                $lines = explode("\n", $row[$i]);
                $cnt = count($lines);
                for($k = 0; $k < $cnt; $k++) {
                    $renderer->cdata($lines[$k]);
                    if($k < $cnt - 1) $renderer->linebreak();
                }

                // close cell
                if($line < $opt['hdr_rows'] || $i < $opt['hdr_cols']) {
                    $renderer->tableheader_close();
                } else {
                    $renderer->tablecell_close();
                }

                $i += $span;
            }
            $renderer->tablerow_close();
        }
        $renderer->table_close();

        return true;
    }

}
//Setup VIM: ex: et ts=4 enc=utf-8 :
