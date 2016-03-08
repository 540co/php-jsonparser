<?php
namespace FiveFortyCo\Json;
require __DIR__ . '/../vendor/autoload.php';

use Keboola\CsvTable\Table;
use Keboola\Utils\Utils;

date_default_timezone_set('America/Los_Angeles');

$r = array();
$r['ff52436aa931128b4220047f67a956d2b3aab8eb'] = json_decode(file_get_contents('./tests/_data/lineitems/ff52436aa931128b4220047f67a956d2b3aab8eb.json'));
$r['ffa9b89f42e0e059df93cd3e79f328e1f11b359f'] = json_decode(file_get_contents('./tests/_data/lineitems/ffa9b89f42e0e059df93cd3e79f328e1f11b359f.json'));

$parser = \FiveFortyCo\Json\Parser::create(new \Monolog\Logger('json-parser'));
$parser->process($r);
$parser->getCsvTables();

var_dump($parser->csvTables);

die;
foreach ($csvfiles as $fileIndex=>$file) {

  $csvfile = $file->openFile('r');
  $csvfile->setFlags(\SplFileObject::READ_CSV);

  $attributes = $file->getAttributes();

  echo "-----------\n";
  var_dump($attributes);
  echo "-----------\n";
  foreach ($csvfile as $rownum=>$rowval) {
      var_dump($rowval);
  }


}
