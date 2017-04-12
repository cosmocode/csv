<?php
/**
 * CSV Plugin: displays a single value from a CSV file
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

if(!defined('DOKU_INC')) die('meh');

/**
 * Display a single CSV value
 */
class syntax_plugin_csv_value extends DokuWiki_Syntax_Plugin {

    protected $rowcache = array();

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
        return 'normal';
    }

    /**
     * @inheritdoc
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<csvval[^>]*>', $mode, 'plugin_csv_value');
    }

    /**
     * @inheritdoc
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        $optstr = substr($match, 7, -1); // <csvval ... >
        $opt = helper_plugin_csv::parseOptions($optstr);

        return $opt;
    }

    /**
     * @param array $opt
     * @return string
     */
    function getCachedValue($opt) {
        $r = $opt['outr'] + $opt['hdr_rows'];
        $c = $opt['outc'];
        unset($opt['output']);
        unset($opt['outr']);
        unset($opt['outc']);

        $cache = md5(serialize($opt));
        if(!isset($this->rowcache[$cache])) {
            try {
                $content = helper_plugin_csv::loadContent($opt['file']);
            } catch(\Exception $e) {
                return $e->getMessage();
            }
            $this->rowcache[$cache] = helper_plugin_csv::prepareData($content, $opt);
        }

        if(isset($this->rowcache[$cache][$r][$c])) {
            return $this->rowcache[$cache][$r][$c];
        } else {
            return 'Failed to find requested value';
        }
    }

    /**
     * @inheritdoc
     */
    function render($mode, Doku_Renderer $renderer, $opt) {
        if($mode == 'metadata') return false;

        if($opt['file'] === '') {
            $renderer->cdata('no csv file given');
            return true;
        }

        if(!media_ispublic($opt['file'])) $renderer->info['cache'] = false;

        $value = $this->getCachedValue($opt);
        $renderer->cdata($value);
        return true;
    }

}
//Setup VIM: ex: et ts=4 enc=utf-8 :
