<?php

/**
 * @group plugin_csv
 * @group plugins
 */
class syntax_plugin_csv_test extends DokuWikiTest {

    private $delimiters = array(
        'c' => ',',
        's' => ';',
        't' => "\t"
    );

    private $enclosings = array(
        'q' => '"',
        's' => "'",
    );

    private $escapes = array(
        'q' => '"',
        'b' => '\\'
    );

    function test_files(){
        // run through all the test files
        $files = glob(__DIR__.'/csv/*.csv');
        foreach($files as $file){
            // load test csv and json files
            $csv  = file_get_contents($file);
            $file = basename($file, '.csv');
            $json = file_get_contents(__DIR__.'/json/'.$file.'.json');

            // get delimiter configs form file name
            list($delim, $enc, $esc) =  explode('-', $file);
            $delim = $this->delimiters[$delim];
            $enc = $this->enclosings[$enc];
            $esc = $this->escapes[$esc];

            // test
            $this->assertEquals(json_decode($json, true), $this->csvparse($csv, $delim, $enc, $esc), $file);
        }
    }

    /**
     * Calls the CSV line parser of our plugin and returns the whole array
     *
     * @param string $csvdata
     * @param string $delim
     * @param string $enc
     * @param string $esc
     * @return array
     */
    function csvparse($csvdata, $delim, $enc, $esc){

        $data = array();

        while($csvdata != '') {
            $line = helper_plugin_csv::csv_explode_row($csvdata, $delim, '"', '"');
            if($line !== false) array_push($data, $line);
        }

        return $data;
    }

    /**
     * check general content loading
     */
    function test_content() {
        $contents = file_get_contents(__DIR__ . '/avengers.csv');

        $opt = helper_plugin_csv::getDefaultOpt();

        $data = helper_plugin_csv::prepareData($contents, $opt);
        $this->assertSame(174, count($data), 'number of rows');
        $this->assertSame(21, count($data[0]), 'number of columns');
    }

    /**
     * check general content loading
     */
    function test_filter() {
        $contents = file_get_contents(__DIR__ . '/avengers.csv');

        $opt = helper_plugin_csv::getDefaultOpt();
        $opt['filter'][4] = '^FEMALE$';

        $data = helper_plugin_csv::prepareData($contents, $opt);
        $this->assertSame(59, count($data), 'number of rows');
        $this->assertSame(21, count($data[0]), 'number of columns');

        $opt['filter'][1] = '^.*?jessica.*?$';
        $data = helper_plugin_csv::prepareData($contents, $opt);
        $this->assertSame(3, count($data), 'number of rows');
        $this->assertSame(21, count($data[0]), 'number of columns');

        $this->assertEquals('Jessica Jones', $data[2][1]);
    }

    /**
     * check the option parsing
     */
    function test_options() {
        $opt = helper_plugin_csv::getDefaultOpt();
        $this->assertEquals($opt, helper_plugin_csv::parseOptions(''));

        $opt = helper_plugin_csv::parseOptions('foo.csv');
        $this->assertEquals(':foo.csv', $opt['file']);

        $opt = helper_plugin_csv::parseOptions('file=foo.csv');
        $this->assertEquals(':foo.csv', $opt['file']);

        $opt = helper_plugin_csv::parseOptions('file="foo.csv"');
        $this->assertEquals(':foo.csv', $opt['file']);

        $opt = helper_plugin_csv::parseOptions('delim=tab');
        $this->assertEquals("\t", $opt['delim']);

        $opt = helper_plugin_csv::parseOptions('filter[2]="*t(es)t*"');
        $this->assertEquals('^.*?t\(es\)t.*?$', $opt['filter'][1]);

        $opt = helper_plugin_csv::parseOptions('filter[2][r]="t(es)t.*"');
        $this->assertEquals('t(es)t.*', $opt['filter'][1]);

        $opt = helper_plugin_csv::parseOptions('foo="with spaces"');
        $this->assertEquals('with spaces', $opt['foo']);

        $opt = helper_plugin_csv::parseOptions('output=4,3');
        $this->assertSame(3, $opt['outc']);
        $this->assertSame(2, $opt['outr']);

        $opt = helper_plugin_csv::parseOptions('output=4');
        $this->assertSame(3, $opt['outc']);
        $this->assertSame(0, $opt['outr']);
    }
}
