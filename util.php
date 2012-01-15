<?php

function get_error_enum($errno)
{
	$enums = explode(' ', 'E_ERROR E_WARNING E_PARSE E_NOTICE E_CORE_ERROR E_CORE_WARNING E_WARNING E_COMPILE_ERROR E_COMPILE_WARNING E_USER_ERROR E_USER_WARNING E_USER_NOTICE E_STRICT E_RECOVERABLE_ERROR E_DEPRECATED E_USER_DEPRECATED E_ALL');

	foreach ($enums as $enum)
		if (constant($enum) == $errno)
			return $enum;
	
	return $errno;
}

if (PHP_SAPI === 'cli')
{
	set_error_handler(function($errno, $errstr, $errfile, $errline) {
		// text colour
		echo chr(27) . "[1;37;";
		// background colour
		echo ($errno == E_NOTICE || $errno == E_WARNING ? "43" : "41") . "m";

		// show error message and line
		$errenum = get_error_enum($errno);
		echo "\n$errenum: $errstr\n $errfile:$errline\n";

		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		array_shift($trace); // remove the reference to this callback

		// print the backtrace
		foreach ($trace as $i => $step)
			printf("#%2d %s%s%s() called at [%s:%d]\n", $i,
				isset($step['class']) ? $step['class'] : '',
				isset($step['type']) ? $step['type'] : '',
				$step['function'],
				basename($step['file']), // should be relative path to $errfile
				$step['line']);
		
		// stop colours
		echo chr(27) . "[00m;\n";
	});
}

/**
 * Bind some arguments already to a function. The arguments which whom the function
 * finally is called are appended after the already bound arguments.
 * e.g. $a = curry('implode', ':') will return a function $a which then can be called
 * with the remaining missing arguments (an array in this example) and then will call
 * implode(':', supplied-array) eventually. Very handy in combination with array_map
 * and array_filter.
 * 
 * @param callable $function function
 * @param mixed $arg,... one or more arguments
 * @return callable
 */
function curry($function, $arg)
{
	$bound_arguments = func_get_args();
	array_shift($bound_arguments);

	return function() use ($function, $bound_arguments) {
		$call_arguments = func_get_args();
		return call_user_func_array($function,
			array_merge($bound_arguments, $call_arguments));
	};
}

function array_filter_type($type, $array)
{
	$hits = array();

	foreach ($array as $element)
		if ($element instanceof $type)
			$hits[] = $element;
	
	return $hits;
}

function array_flatten($array)
{
	$values = array();

	foreach ($array as $item)
		if (is_array($item))
			$values = array_merge($values, array_flatten($item));
		else
			$values[] = $item;
	
	return $values;
}

function array_map_method($method, $array)
{
	$values = array();

	foreach ($array as $key => $value)
		$values[$key] = call_user_func(array($value, $method));
	
	return $values;
}

function iterator_contains(Iterator $it, $needle)
{
	foreach ($it as $el)
		if ($el == $needle)
			return true;
	
	return false;
}

function unequals($a, $b)
{
	return $a != $b;
}

function pick($property, $object)
{
	return $object->$property;
}

class Map implements ArrayAccess, IteratorAggregate
{
	private $default_value;

	private $data = array();

	public function __construct($default_value = null)
	{
		$this->default_value = $default_value;
	}
	
	public function offsetExists($key)
	{
		return isset($this->data[$key]);
	}

	public function offsetUnset($key)
	{
		unset($this->data[$key]);
	}

	public function offsetGet($key)
	{
		return isset($this->data[$key])
			? $this->data[$key]
			: $this->offsetSet($key, $this->makeDefaultValue($key));
	}

	public function offsetSet($key, $value)
	{
		return $this->data[$key] = $value;
	}

	public function getIterator()
	{
		return new ArrayIterator($this->data);
	}

	public function data()
	{
		return $this->data;
	}

	protected function makeDefaultValue($key)
	{
		return is_callable($this->default_value)
			? call_user_func($this->default_value, $key)
			: $this->default_value;
	}
}

class Set implements IteratorAggregate, Countable
{
	private $values;

	public function __construct()
	{
		$this->values = array();
	}

	public function push($value)
	{
		if (!in_array($value, $this->values))
			$this->values[] = $value;
	}

	public function getIterator()
	{
		return new ArrayIterator($this->values);
	}

	public function count()
	{
		return count($this->values);
	}
}

/**
 * In oudere versies van PHP is het serializen van SplStack niet goed
 * geïmplementeerd. Dus dan maar zelf implementeren :)
 */
class Stack extends SplStack implements Serializable
{
	public function serialize()
	{
		$items = iterator_to_array($this);
		return serialize($items);
	}

	public function unserialize($data)
	{
		foreach (unserialize($data) as $item)
			$this->unshift($item);
	}
}

/**
 * Dit makeshift datatype lijkt nogal op C++'s pair class. Het is gewoon handig.
 * ArrayAccess interface is geïmplementeerd zodat je hem in combinatie met list($a, $b)
 * kan gebruiken.
 */
class Pair implements ArrayAccess
{
	public $first;

	public $second;

	public function __construct($first = null, $second = null)
	{
		$this->first = $first;

		$this->second = $second;
	}

	public function offsetExists($n)
	{
		return $n == 0 || $n == 1;
	}

	public function offsetGet($n)
	{
		if ($n == 0)
			return $this->first;
		
		elseif ($n == 1)
			return $this->second;
		
		else
			throw new Exception('Index out of bounds exception');
	}

	public function offsetSet($n, $value)
	{
		if ($n == 0)
			$this->first = $value;
		
		elseif ($n == 1)
			$this->second = $value;
		
		else
			throw new Exception('Index out of bounds exception');
	}

	public function offsetUnset($n)
	{
		if ($n == 0)
			$this->first = null;
		
		elseif ($n == 1)
			$this->second = null;
		
		else
			throw new Exception('Index out of bounds exception');
	}
}

class Template
{
	private $__TEMPLATE__;

	private $__DATA__;

	public function __construct($file)
	{
		$this->__TEMPLATE__ = $file;

		$this->__DATA__ = array();
	}

	public function __set($key, $value)
	{
		$this->__DATA__[$key] = $value;
	}

	public function render()
	{
		ob_start();
		extract($this->__DATA__);
		include $this->__TEMPLATE__;
		return ob_get_clean();
	}

	protected function html($data)
	{
		return htmlspecialchars($data, ENT_COMPAT, 'utf-8');
	}

	protected function attr($data)
	{
		return htmlspecialchars($data, ENT_QUOTES, 'utf-8');
	}
}


function verbose($state = null)
{
	static $verbose;
	return $state === null
		? $verbose
		: $verbose = $state;
}