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

/**
 * Display CSV data as table
 */
class syntax_plugin_csv_table extends DokuWiki_Syntax_Plugin
{

    /** @inheritdoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritdoc */
    public function getSort()
    {
        return 155;
    }

    /** @inheritdoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritdoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<csv[^>]*>.*?(?:<\/csv>)', $mode, 'plugin_csv_table');
    }

    /** @inheritdoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = substr($match, 4, -6); // <csv ... </csv>

        list($optstr, $content) = explode('>', $match, 2);
        $opt = helper_plugin_csv::parseOptions($optstr);
        $opt['content'] = $content;

        return $opt;
    }

    /** @inheritdoc */
    public function render($mode, Doku_Renderer $renderer, $opt)
    {
        if ($mode == 'metadata') return false;

        // load file data
        if ($opt['file']) {
            try {
                $opt['content'] = helper_plugin_csv::loadContent($opt['file']);
                if (!media_ispublic($opt['file'])) $renderer->info['cache'] = false;
            } catch (\Exception $e) {
                $renderer->cdata($e->getMessage());
                return true;
            }
        }

        // check if there is content
        $content =& $opt['content'];
        $content = trim($content);
        if ($content === '') {
            $renderer->cdata('No csv data found');
            return true;
        }

        $data = helper_plugin_csv::prepareData($content, $opt);

        if (empty($data)) {
            $message = $this->getLang('no_result');
            $renderer->cdata($message);
            return true;
        }

        $maxcol = count($data[0]);
        $line = 0;

        // render
        $renderer->table_open($maxcol, count($data));
        // Open thead or tbody
        ($opt['hdr_rows']) ? $renderer->tablethead_open() : $renderer->tabletbody_open();
        foreach ($data as $row) {
            // close thead yet?
            if ($line > 0 && $line == $opt['hdr_rows']) {
                $renderer->tablethead_close();
                $renderer->tabletbody_open();
            }
            $renderer->tablerow_open();
            for ($i = 0; $i < $maxcol;) {
                $span = 1;
                // lookahead to find spanning cells
                if ($opt['span_empty_cols']) {
                    for ($j = $i + 1; $j < $maxcol; $j++) {
                        if ($row[$j] === '') {
                            $span++;
                        } else {
                            break;
                        }
                    }
                }

                // open cell
                if ($line < $opt['hdr_rows'] || $i < $opt['hdr_cols']) {
                    $renderer->tableheader_open($span);
                } else {
                    $renderer->tablecell_open($span);
                }

                // print cell content, call linebreak() for newlines
                $lines = explode("\n", $row[$i]);
                $cnt = count($lines);
                for ($k = 0; $k < $cnt; $k++) {
                    $renderer->cdata($lines[$k]);
                    if ($k < $cnt - 1) $renderer->linebreak();
                }

                // close cell
                if ($line < $opt['hdr_rows'] || $i < $opt['hdr_cols']) {
                    $renderer->tableheader_close();
                } else {
                    $renderer->tablecell_close();
                }

                $i += $span;
            }
            $renderer->tablerow_close();
            $line++;
        }
        // if there was a tbody, close it
        if ($opt['hdr_rows'] < $line) $renderer->tabletbody_close();
        $renderer->table_close();

        return true;
    }

}
