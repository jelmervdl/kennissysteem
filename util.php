<?php

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
			: $this->offsetSet($key, $this->default_value);
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
}

function verbose($state = null)
{
	static $verbose;
	return $state === null
		? $verbose
		: $verbose = $state;
}