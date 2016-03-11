<?php
namespace FiveFortyCo\Json;
require __DIR__ . '/../vendor/autoload.php';

use Keboola\CsvTable\Table;
use Keboola\Utils\Utils;

date_default_timezone_set('America/Los_Angeles');

$r = array();
//$r['foo'] = json_decode(file_get_contents('./tests/_data/simple/foo.json'));
//$r['bar'] = json_decode(file_get_contents('./tests/_data/simple/bar.json'));
$r['ff52436aa931128b4220047f67a956d2b3aab8eb'] = json_decode(file_get_contents('./tests/_data/lineitems/ff52436aa931128b4220047f67a956d2b3aab8eb.json'));
$r['ffa9b89f42e0e059df93cd3e79f328e1f11b359f'] = json_decode(file_get_contents('./tests/_data/lineitems/ffa9b89f42e0e059df93cd3e79f328e1f11b359f.json'));

$parser = \FiveFortyCo\Json\Parser::create(new \Monolog\Logger('json-parser'));
$parser->process($r);
$csvTables = $parser->getCsvTables();
//$csvTableDefinition = $parser->getCsvTableDefinition();
//$csvTableStatus = $parser->getCsvTableStats();

var_dump($csvTables);
//var_dump($csvTableDefinition);
//var_dump($csvTableStatus);
