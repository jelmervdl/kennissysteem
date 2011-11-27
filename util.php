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
	array_shift($arguments);

	return function() use ($function, $bound_arguments) {
		$call_arguments = func_get_args();
		return call_user_func_array($function,
			array_merge($bound_arguments, $call_arguments));
	};
}

function curry_r($function, $arg)
{
	$bound_arguments = func_get_args();
	array_shift($arguments);

	return function() use ($function, $bound_arguments) {
		$call_arguments = func_get_args();
		return call_user_func_array($function,
			array_merge($call_arguments, $bound_arguments));
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