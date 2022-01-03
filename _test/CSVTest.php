<?php

namespace dokuwiki\plugin\csv\test;

use DokuWikiTest;

/**
 * @group plugin_csv
 * @group plugins
 */
class CSVTest extends DokuWikiTest
{

    private $delimiters = array(
        'c' => ',',
        's' => ';',
        't' => "\t",
    );

    private $enclosings = array(
        'q' => '"',
        's' => "'",
    );

    private $escapes = array(
        'q' => '"',
        'b' => '\\',
    );

    /**
     * @return \Generator
     * @see testParser
     */
    public function provideCSVFiles()
    {
        // run through all the test files
        $files = glob(__DIR__ . '/csv/*.csv');
        foreach ($files as $file) {
            // load test csv and json files
            $csvdata = file_get_contents($file);
            $file = basename($file, '.csv');
            $json = file_get_contents(__DIR__ . '/json/' . $file . '.json');
            $expect = json_decode($json, true);

            // get delimiter configs form file name
            list($delim, $enc, $esc) = explode('-', $file);
            $delim = $this->delimiters[$delim];
            $enc = $this->enclosings[$enc];
            $esc = $this->escapes[$esc];

            yield [$file, $expect, $csvdata, $delim, $enc, $esc];
        }
    }

    /**
     * @dataProvider provideCSVFiles
     * @param string $file
     * @param string[][] $expect
     * @param string $csvdata
     * @param string $delim
     * @param string $enc
     * @param string $esc
     */
    public function testParser($file, $expect, $csvdata, $delim, $enc, $esc)
    {
        // read all data
        $result = [];
        while ($csvdata != '') {
            $line = \helper_plugin_csv::csv_explode_row($csvdata, $delim, $enc, $esc);
            if ($line !== false) array_push($result, $line);
        }

        $this->assertEquals($expect, $result, $file);
    }

    /**
     * check general content loading
     */
    public function testContent()
    {
        $contents = file_get_contents(__DIR__ . '/avengers.csv');

        $opt = \helper_plugin_csv::getDefaultOpt();

        $data = \helper_plugin_csv::prepareData($contents, $opt);
        $this->assertSame(174, count($data), 'number of rows');
        $this->assertSame(21, count($data[0]), 'number of columns');
    }

    /**
     * check general content loading
     */
    public function testFilter()
    {
        $contents = file_get_contents(__DIR__ . '/avengers.csv');

        $opt = \helper_plugin_csv::getDefaultOpt();
        $opt['filter'][4] = '^FEMALE$';

        $data = \helper_plugin_csv::prepareData($contents, $opt);
        $this->assertSame(59, count($data), 'number of rows');
        $this->assertSame(21, count($data[0]), 'number of columns');

        $opt['filter'][1] = '^.*?jessica.*?$';
        $data = \helper_plugin_csv::prepareData($contents, $opt);
        $this->assertSame(3, count($data), 'number of rows');
        $this->assertSame(21, count($data[0]), 'number of columns');

        $this->assertEquals('Jessica Jones', $data[2][1]);
    }

    /**
     * @return array[]
     * @see testOptions
     */
    public function provideOptions()
    {
        return [
            ['https://example.com/file.csv', ['file' => 'https://example.com/file.csv']],
            ['foo.csv', ['file' => 'foo.csv']],
            ['file=foo.csv', ['file' => 'foo.csv']],
            ['file="foo.csv"', ['file' => 'foo.csv']],
            ['delim=tab', ['delim' => "\t"]],
            ['filter[2]="*t(es)t*"', ['filter' => [1 => '^.*?t\(es\)t.*?$']]],
            ['filter[2][r]="t(es)t.*"', ['filter' => [1 => 't(es)t.*']]],
            ['foo="with spaces"', ['foo' => 'with spaces']],
            ['output=4,3', ['outc' => 3, 'outr' => 2]],
            ['output=4', ['outc' => 3, 'outr' => 0]],
        ];
    }

    /**
     * check the option parsing
     *
     * @dataProvider provideOptions
     * @param string $input
     * @param string[] $expect
     * @return void
     */
    public function testOptions($input, $expect)
    {
        $opt = \helper_plugin_csv::getDefaultOpt();
        unset($opt['output']);

        $expect = array_merge($opt, $expect);
        $result = \helper_plugin_csv::parseOptions($input);

        $this->assertEquals($expect, $result);
    }

}
