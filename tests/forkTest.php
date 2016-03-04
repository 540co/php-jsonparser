<?php
namespace FiveFortyCo\Json;
require __DIR__ . '/../vendor/autoload.php';

use Keboola\CsvTable\Table;
use Keboola\Utils\Utils;

date_default_timezone_set('America/Los_Angeles');

$r = array();
$r[] = json_decode(file_get_contents('./tests/_data/lineitems/ff52436aa931128b4220047f67a956d2b3aab8eb.json'));

$parser = \FiveFortyCo\Json\Parser::create(new \Monolog\Logger('json-parser'));
$parser->process($r);
$csvfiles = $parser->getCsvFiles();

foreach ($csvfiles as $fileIndex=>$file) {

  $csvfile = $file->openFile('r');
  $csvfile->setFlags(\SplFileObject::READ_CSV);

  $attributes = $file->getAttributes();

}
