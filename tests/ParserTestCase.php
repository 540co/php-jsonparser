<?php
// namespace Keboola\Json;

class ParserTestCase extends \PHPUnit_Framework_TestCase
{
	protected static function callMethod($obj, $name, array $args = [])
	{
		$class = new \ReflectionClass($obj);
		$method = $class->getMethod($name);
		$method->setAccessible(true);

		return $method->invokeArgs($obj, $args);
	}

// 	protected static function getProperty($obj, $name)
// 	{
// 		$class = new \ReflectionClass($obj);
// 		$property = $class->getProperty($name);
// 		$property->setAccessible(true);
// 		return $property->getValue($obj);
// 	}
	protected function getLogger($name = 'test', $null = false)
	{
		return new \Monolog\Logger(
			$name,
			$null ? [new \Monolog\Handler\NullHandler()] : []
		);
	}

}
