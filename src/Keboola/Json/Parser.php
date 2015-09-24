<?php

namespace Keboola\Json;

use Keboola\CsvTable\Table;
use Keboola\Temp\Temp;
use Monolog\Logger;
use Keboola\Json\Exception\JsonParserException;

/**
 * JSON to CSV data analyzer and parser/converter
 *
 * Use to convert JSON data into CSV file(s).
 * Creates multiple files if the JSON contains arrays
 * to store values of child nodes in a separate table,
 * linked by JSON_parentId column.

 * The analyze function loops through each row of an array (generally an array of results)
 * and passes the row into analyzeRow() method. If the row only contains a string,
 * it's stored in a "data" column, otherwise the row should usually be an object,
 * so each of the object's variables will be used as a column name, and it's value analysed:
 *
 * - if it's a scalar, it'll be saved as a value of that column.
 * - if it's another object, it'll be parsed recursively to analyzeRow(),
 * 		with it's variable names prepended by current object's name
 *	- example:
 *			"parent": {
 *				"child" : "value1"
 *			}
 *			will result into a "parent_child" column with a string type of "value1"
 * - if it's an array, it'll be passed to analyze() to create a new table, linked by JSON_parentId
 *
 *
 * @author		Ondrej Vana (kachna@keboola.com)
 * @package		keboola/json-parser
 * @copyright	Copyright (c) 2014 Keboola Data Services (www.keboola.com)
 * @license		GPL-3.0
 * @link		https://github.com/keboola/php-jsonparser
 *
 * @todo Use a $file parameter to allow writing the same
 * 		data $type to multiple files
 * 		(ie. type "person" to "customer" and "user")
 *
 * @todo A Struct class that will ensure the struct is free of errors
 *		- Exactly one level of nesting
 *		- The data type in each $type is supported (array, object, string, ..., arrayOf$)
 */
class Parser
{
	const DATA_COLUMN = 'data';

	const STRUCT_VERSION = 1.0;

	/**
	 * Headers for each type
	 * @var array
	 */
	protected $headers = [];

	/**
	 * @var Table[]
	 */
	protected $csvFiles = [];

	/**
	 * True if analyze() was called
	 * @var bool
	 */
	protected $analyzed;

	/**
	 * Counts of analyzed rows per data type
	 * @var array
	 */
	protected $rowsAnalyzed = [];

	/**
	 * @var Cache
	 */
	protected $cache;

	/**
	 * @var Logger
	 */
	protected $log;

	/**
	 * @var Temp
	 */
	protected $temp;

	/**
	 * @var array
	 */
	protected $primaryKeys = [];

	/**
	 * @var bool
	 */
	protected $nestedArrayAsJson = false;

	/**
	 * @var Analyzer
	 */
	protected $analyzer;

	/**
	 * @var Struct
	 */
	protected $struct;

	/**
	 * @param Logger $logger
	 * @param array $struct should contain an array with previously
	 * 		cached results from analyze() calls (called automatically by process())
	 * @param int $analyzeRows determines how many rows of data
	 * 		(counting only the "root" level of each Json)
	 * 		will be analyzed [default -1 for infinite/all]
	 */
	public function __construct(Logger $logger, Analyzer $analyzer = null)
	{
		$this->analyzer = $analyzer;

		$this->log = $logger;
	}

	public static function create(Logger $logger, array $struct = [], $analyzeRows = -1)
	{
		$analyzer = new Analyzer($logger, $struct, $analyzeRows);

		return new static($logger, $analyzer);
	}

	/**
	 * Parse an array of results. If their structure isn't known,
	 * it is stored, analyzed and then parsed upon retrieval by getCsvFiles()
	 * Expects an array of results in the $data parameter
	 * Checks whether the data needs to be analyzed,
	 * and either analyzes or parses it into $this->csvFiles[$type]
	 * ($type is polished to comply with SAPI naming requirements)
	 * If the data is analyzed, it is stored in Cache
	 * and **NOT PARSED** until $this->getCsvFiles() is called
	 *
	 * @TODO FIXME keep the order of data as on the input
	 * 	- try to parse data from Cache before parsing new data
	 * 	- sort of fixed by defaulting to -1 analyze default
	 *
	 * @param array $data
	 * @param string $type is used for naming the resulting table(s)
	 * @param string|array $parentId may be either a string,
	 * 		which will be saved in a JSON_parentId column,
	 * 		or an array with "column_name" => "value",
	 * 		which will name the column(s) by array key provided
	 *
	 * @return void
	 *
	 * @api
	 */
	public function process(array $data, $type = "root", $parentId = null)
	{
		// The analyzer wouldn't set the $struct and parse fails!
		if (empty($data) && empty($this->struct[$type])) {
			$this->log->log("warning", "Empty data set received for {$type}", [
				"data" => $data,
				"type" => $type,
				"parentId" => $parentId
			]);

			return;
		}

		// If we don't know the data (enough), store it in Cache,
		// analyze, and parse when asked for it in getCsvFiles()
// TODO leave this IF to analyzer
// always send it to analyzer, leave it to decide whether to analyze or do nothing.
// then cache everything, and parse everything upon retrieval by results getter
		if (
			!array_key_exists($type, $this->struct) ||
			$this->analyzeRows == -1 ||
			(!empty($this->rowsAnalyzed[$type]) && $this->rowsAnalyzed[$type] < $this->analyzeRows)
		) {
			if (empty($this->rowsAnalyzed[$type])) {
				$this->log->log("debug", "Analyzing {$type}", [
// 					"struct" => json_encode($this->struct),
					"analyzeRows" => $this->analyzeRows,
					"rowsAnalyzed" => json_encode($this->rowsAnalyzed)
				]);
			}

			$this->rowsAnalyzed[$type] = empty($this->rowsAnalyzed[$type])
				? count($data)
				: ($this->rowsAnalyzed[$type] + count($data));

			$this->initCache();

			$this->cache->store([
				"data" => $data,
				"type" => $type,
				"parentId" => $parentId
			]);

			$this->analyze($data, $type);
		} else {
			$this->parse($data, $type, $parentId);
		}
		// TODO return the files written into
	}

	/**
	 * Get header for a data type
	 * @param string $type Data type
	 * @param string|array $parent String with a $parentId or an array with $colName => $parentId
	 * @return array
	 */
	protected function getHeader($type, $parent = false)
	{
		$header = [];
		if (is_scalar($this->struct[$type])) {
			$header[] = self::DATA_COLUMN;
		} else {
			foreach($this->struct[$type] as $column => $dataType) {
				if ($dataType == "object") {
					foreach($this->getHeader($type . "." . $column) as $col => $val) {
						// FIXME this is awkward, the createSafeSapiName shouldn't need to be used twice
						// (here and in validateHeader again)
						$header[] = $this->createSafeSapiName($column) . "_" . $val;
					}
				} else {
					$header[] = $column;
				}
			}
		}

		if ($parent) {
			if (is_array($parent)) {
				$header = array_merge($header, array_keys($parent));
			} else {
				$header[] = "JSON_parentId";
			}
		}

		// TODO set $this->headerNames[$type] = array_combine($validatedHeader, $header);
		// & add a getHeaderNames fn()
		return $this->validateHeader($header);
	}

	/**
	 * Validate header column names to comply with MySQL limitations
	 *
	 * @param array $header Input header
	 * @return array
	 */
	protected function validateHeader(array $header)
	{
		$newHeader = [];
		foreach($header as $key => $colName) {
			$newName = $this->createSafeSapiName($colName);

			// prevent duplicates
			if (in_array($newName, $newHeader)) {
				$newHeader[$key] = md5($colName);
			} else {
				$newHeader[$key] = $newName;
			}
		}
		return $newHeader;
	}

	/**
	 * Validates a string for use as MySQL column/table name
	 *
	 * @param string $name A string to be validated
	 * @return string
	 */
	protected function createSafeSapiName($name)
	{
		if (strlen($name) > 64) {
			if(str_word_count($name) > 1 && preg_match_all('/\b(\w)/', $name, $m)) {
				$short = implode('',$m[1]);
			} else {
				$short = md5($name);
			}
			$short .= "_";
			$remaining = 64 - strlen($short);
			$nextSpace = strpos($name, " ", (strlen($name)-$remaining))
				? : strpos($name, "_", (strlen($name)-$remaining));

			if ($nextSpace !== false) {
				$newName = $short . substr($name, $nextSpace);
			} else {
				$newName = $short;
			}
		} else {
			$newName = $name;
		}

		$newName = preg_replace('/[^A-Za-z0-9-]/', '_', $newName);
		return trim($newName, "_");
	}

	/**
	 * Parse data of known type
	 *
	 * @param array $data
	 * @param string $type
	 * @param string|array $parentId
	 * @return void
	 * @see Parser::process()
	 */
	public function parse(array $data, $type, $parentId = null)
	{
		if (empty($this->struct[$type])) {
			// analyse instead of failing if the data is unknown!
			$this->log->log(
				"debug",
				"Json::parse() ran into an unknown data type '{$type}' - trying on-the-fly analysis",
				[
					"data" => $data,
					"type" => $type,
					"parentId" => $parentId
				]
			);

			$this->analyze($data, $type);
		}

		if (empty($this->headers[$type])) {
			$this->headers[$type] = $this->getHeader($type, $parentId);
		}

		// TODO add a $file parameter to use instead of $type
		// to allow saving a single type to different files
		$safeType = $this->createSafeSapiName($type);
		if (empty($this->csvFiles[$safeType])) {
			$this->csvFiles[$safeType] = Table::create(
				$safeType,
				$this->headers[$type],
				$this->getTemp()
			);
			$this->csvFiles[$safeType]->addAttributes(["fullDisplayName" => $type]);
		}

		if (!empty($parentId)) {
			if (is_array($parentId)) {
				// Ensure the parentId array is not multidimensional
				// TODO should be a different exception
				// - separate parse and "setup" exceptions
				if (count($parentId) != count($parentId, COUNT_RECURSIVE)) {
					throw new JsonParserException(
						'Error assigning parentId to a CSV file! $parentId array cannot be multidimensional.',
						[
							'parentId' => $parentId,
							'type' => $type,
							'dataRow' => $row
						]
					);
				}
			} else {
				$parentId = ['JSON_parentId' => $parentId];
			}
		} else {
			$parentId = [];
		}

		$parentCols = array_fill_keys(array_keys($parentId), "string");

		foreach($data as $row) {
			// in case of non-associative array of strings
			// prepare {"data": $value} objects for each row
			if (is_scalar($row) || is_null($row)) {
				$row = (object) [self::DATA_COLUMN => $row];
			} elseif ($this->nestedArrayAsJson && is_array($row)) {
				$row = (object) [self::DATA_COLUMN => json_encode($row)];
			}

			if (!empty($parentId)) {
				$row = (object) array_replace((array) $row, $parentId);
			}

			$csvRow = new CsvRow($this->headers[$type]);

			// TODO the $csvRow should ideally be created within the parseRow
			$this->parseRow($row, $csvRow, $type, $parentCols);

			$this->csvFiles[$safeType]->writeRow($csvRow->getRow());
		}
	}

	/**
	 * Parse a single row
	 * If the row contains an array, it's recursively parsed
	 *
	 * @param \stdClass $dataRow Input data
	 * @param string $type
	 * @param CsvRow $csvRow
	 * @param array $parentCols to inject parent columns, which aren't part of $this->struct
	 * @param string $outerObjectHash Outer object hash to distinguish different parents in deep nested arrays
	 * @return array
	 */
	public function parseRow(\stdClass $dataRow, CsvRow $csvRow, $type, array $parentCols = [], $outerObjectHash = null)
	{
		if ($this->struct[$type] == "NULL") {
			$this->log->log(
				"WARNING", "Encountered data where 'NULL' was expected from previous analysis",
				[
					'type' => $type,
					'data' => $dataRow
				]
			);
			$csvRow->setValue(self::DATA_COLUMN, json_encode($dataRow));
			return [self::DATA_COLUMN => json_encode($dataRow)];
		}

		// Generate parent ID for arrays
		$arrayParentId = $this->getPrimaryKeyValue(
			$dataRow,
			$type,
			$outerObjectHash
		);

		$row = [];
		foreach(array_merge($this->struct[$type], $parentCols) as $column => $dataType) {
			// TODO safeColumn should be associated with $this->struct[$type]
			// (and parentCols -> create in parse() where the arr is created)
			// Actually, the csvRow should REALLY have a pointer to the real name (not validated),
			// perhaps sorting the child columns on its own?
			// (because keys in struct don't contain child objects)
			$safeColumn = $this->createSafeSapiName($column);

			// skip empty objects & arrays to prevent creating empty tables
			// or incomplete column names
			if (
				!isset($dataRow->{$column})
				|| is_null($dataRow->{$column})
				|| (empty($dataRow->{$column}) && !is_scalar($dataRow->{$column}))
			) {
				// do not save empty objects to prevent creation of ["obj_name" => null]
				if ($dataType != 'object') {
					$row[$safeColumn] = null;
					$csvRow->setValue($safeColumn, null);
				}

				continue;
			}

			if ($this->autoUpgradeToArray && substr($dataType, 0, 11) == 'arrayOf') {
				if (!is_array($dataRow->{$column})) {
					$dataRow->{$column} = [$dataRow->{$column}];
				}
				$dataType = 'array';
			}

			if ($this->allowArrayStringMix && $dataType == 'stringOrArray') {
				$dataType = gettype($dataRow->{$column});
			}

			switch ($dataType) {
				case "array":
					$row[$safeColumn] = $arrayParentId;
					$csvRow->setValue($safeColumn, $arrayParentId);
					$this->parse($dataRow->{$column}, $type . "." . $column, $row[$safeColumn]);
					break;
				case "object":
					$childRow = new CsvRow($this->getHeader($type . "." . $column));
					$this->parseRow($dataRow->{$column}, $childRow, $type . "." . $column, [], $arrayParentId);

					$csvRow->setChildValues($safeColumn, $childRow);
					break;
				default:
					// If a column is an object/array while $struct expects a single column, log an error
					if (is_scalar($dataRow->{$column})) {
						$row[$safeColumn] = $dataRow->{$column};
						$csvRow->setValue($safeColumn, $dataRow->{$column});
					} else {
						$jsonColumn = json_encode($dataRow->{$column});

						$this->log->log(
							"ERROR",
							"Data parse error in '{$column}' - unexpected '"
								. gettype($dataRow->{$column})
								. "' where '{$dataType}' was expected!",
							[ "data" => $jsonColumn, "row" => json_encode($dataRow) ]
						);

						$row[$safeColumn] = $jsonColumn;
						$csvRow->setValue($safeColumn, $jsonColumn);
					}
					break;
			}
		}

		return $row;
	}

	/**
	 * @param \stdClass $dataRow
	 * @param string $type for logging
	 * @param string $outerObjectHash
	 * @return string
	 */
	protected function getPrimaryKeyValue(\stdClass $dataRow, $type, $outerObjectHash = null)
	{
		// Try to find a "real" parent ID
		if (!empty($this->primaryKeys[$this->createSafeSapiName($type)])) {
			$pk = $this->primaryKeys[$this->createSafeSapiName($type)];
			$pKeyCols = explode(',', $pk);
			$pKeyCols = array_map('trim', $pKeyCols);
			$values = [];
			foreach($pKeyCols as $pKeyCol) {
				if (empty($dataRow->{$pKeyCol})) {
					$values[] = md5(serialize($dataRow) . $outerObjectHash);
					$this->log->log(
						"WARNING", "Primary key for type '{$type}' was set to '{$pk}', but its column '{$pKeyCol}' does not exist! Using hash to link child objects instead.",
						[
							'row' => $dataRow,
							'hash' => $val
						]
					);
				} else {
					$values[] = $dataRow->{$pKeyCol};
				}
			}

			return $type . "_" . join(";", $values);
		} else {
			// Of no pkey is specified to get the real ID, use a hash of the row
			return $type . "_" . md5(serialize($dataRow) . $outerObjectHash);
		}
	}
/////////////////////////////////////////////////////////


	/**
	 * Returns an array of CSV files containing results
	 * @return Table[]
	 */
	public function getCsvFiles()
	{
		// parse what's in cache before returning results
		$this->processCache();

		foreach($this->primaryKeys as $table => $pk) {
			if (array_key_exists($table, $this->csvFiles)) {
				$this->csvFiles[$table]->setPrimaryKey($pk);
			}
		}

		return $this->csvFiles;
	}

	/**
	 * @return void
	 */
	protected function initCache()
	{
		if (empty($this->cache)) {
			$this->cache = new Cache();
		}
	}

	/**
	 * @return void
	 */
	protected function processCache()
	{
		if(!empty($this->cache)) {
			while ($batch = $this->cache->getNext()) {
				$this->parse($batch["data"], $batch["type"], $batch["parentId"]);
			}
		}
	}


	/**
	 * Read results of data analysis from $this->struct
	 * @return array
	 */
	public function getStruct()
	{
		return $this->struct;
	}

	/**
	 * Version of $struct array used in parser
	 * @return double
	 */
	public function getStructVersion()
	{
		return static::STRUCT_VERSION;
	}

	/**
	 * Returns (bool) whether the analyzer analyzed anything in this instance
	 * @return bool
	 */
	public function hasAnalyzed()
	{
		return (bool) $this->analyzed;
	}

	/**
	 * Initialize $this->temp
	 * @return Temp
	 */
	protected function getTemp()
	{
		if(!($this->temp instanceof Temp)) {
			$this->temp = new Temp("ex-parser-data");
		}
		return $this->temp;
	}

	/**
	 * Override the self-initialized Temp
	 * @param Temp $temp
	 */
	public function setTemp(Temp $temp)
	{
		$this->temp = $temp;
	}

	/**
	 * @param array $pks
	 */
	public function addPrimaryKeys(array $pks)
	{
		$this->primaryKeys += $pks;
	}

	/**
	 * Set whether scalars are treated as compatible
	 * within a field (default = false -> compatible)
	 * @param bool $strict
	 */
	public function setStrict($strict)
	{
		$this->strict = (bool) $strict;
	}

	/**
	 * If enabled, nested arrays will be saved as JSON strings instead
	 * @param bool $bool
	 */
	public function setNestedArrayAsJson($bool)
	{
		$this->nestedArrayAsJson = (bool) $bool;
	}

	/**
	 * Set maximum memory used before Cache starts using php://temp
	 * @param string|int $limit
	 */
	public function setCacheMemoryLimit($limit)
	{
		$this->initCache();

		return $this->cache->setMemoryLimit($limit);
	}
}
