<?php

error_reporting(E_ALL);
ini_set('display_errors', true);
ini_set('assert.exception', '1');


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

function compare($a, $b)
{
    if ($a == $b)
        return 0;

    return $a < $b ? -1 : 1;
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

function iterator_first(Iterator $it)
{
	$it->rewind();

	if (!$it->valid())
		throw new RuntimeException("Iterator has no valid elements");

	return $it->current();
}

function iterator_map(Iterator $it, Callable $callback)
{
	return new CallbackMapIterator($it, $callback);
}

/**
 * Filter anything iterable (array, iterator, stack, list, etc) using a callback.
 * Does not preserve keys.
 */
function filter($iterable, Callable $callback)
{
	$filtered = [];

	foreach ($iterable as $key => $value)
		if (call_user_func($callback, $value, $key))
			$filtered[] = $value;

	return $filtered;
}

function unequals($a, $b)
{
	return $a != $b;
}

function pick($property, $object)
{
	return $object->$property;
}

class CallbackMapIterator extends IteratorIterator
{
	protected $callback;

	public function __construct(Traversable $iterator, Callable $callback)
	{
		parent::__construct($iterator);

		$this->callback = $callback;
	}

	public function current(): mixed
	{
		return call_user_func($this->callback, parent::current(), parent::key());
	}
}

/**
 * @template TKey
 * @template TVal
 * @implements ArrayAccess<TKey,TVal>
 * @implements IteratorAggregate<TVal>
 */
class Map implements ArrayAccess, IteratorAggregate
{
	private mixed $default_value;

	private array $data = array();

	/**
	 * @param TVal | callable(string):TVal | null $default_value
	 */
	public function __construct(mixed $default_value = null)
	{
		$this->default_value = $default_value;
	}

	public function offsetExists(mixed $key): bool
	{
		if (!is_scalar($key))
			throw new InvalidArgumentException('$key can only be of a scalar type');

		return isset($this->data[$key]);
	}

	public function offsetUnset(mixed $key): void
	{
		if (!is_scalar($key))
			throw new InvalidArgumentException('$key can only be of a scalar type');

		unset($this->data[$key]);
	}

	public function offsetGet(mixed $key): mixed
	{
		if (!is_scalar($key))
			throw new InvalidArgumentException('$key can only be of a scalar type');

		if (!isset($this->data[$key]))
			$this->offsetSet($key, $this->makeDefaultValue($key));

		return $this->data[$key];
	}

	public function offsetSet(mixed $key, mixed $value): void
	{
		if (!is_scalar($key))
			throw new InvalidArgumentException('$key can only be of a scalar type');

		$this->data[$key] = $value;
	}

	public function getIterator(): ArrayIterator
	{
		return new ArrayIterator($this->data);
	}

	public function data(): array
	{
		return $this->data;
	}

	protected function makeDefaultValue(string $key): mixed
	{
		return is_callable($this->default_value)
			? call_user_func($this->default_value, $key)
			: $this->default_value;
	}
}

/**
 * @template TVal
 * @implements IteratorAggregate<int,TVal>
 */
class Set implements IteratorAggregate, Countable
{
	private array $values;

	public function __construct()
	{
		$this->values = array();
	}

	/**
	 * @param TVal $value
	 */
	public function contains(mixed $value): bool
	{
		return in_array($value, $this->values);
	}

	/**
	 * @param TVal $value
	 */
	public function push(mixed $value): void
	{
		if (!$this->contains($value))
			$this->values[] = $value;
	}

	/**
	 * @param Traversable<TVal> $values
	 */
	public function pushAll(iterable $values): void
	{
		foreach ($values as $value)
			$this->push($value);
	}

	/**
	 * @param TVal $value
	 * @return array<TVal> | false
	 */
	public function remove(mixed $value): array | bool
	{
		$index = array_search($value, $this->values);

		return $index !== false
			? array_splice($this->values, $index, 1)
			: false;
	}

	/**
	 * @return Traversable<TVal>
	 */
	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->values);
	}

	/**
	 * @template RVal
	 * @param callable(TVal): RVal $callback
	 * @return Traversable<RVal>
	 */
	public function map(Callable $callback): Traversable
	{
		return new CallbackMapIterator($this->getIterator(), $callback);
	}

	public function count(): int
	{
		return count($this->values);
	}

	public function isEmpty(): bool
	{
		return $this->count() === 0;
	}
}

/**
 * In oudere versies van PHP is het serializen van SplStack niet goed
 * geïmplementeerd. Dus dan maar zelf implementeren :)
 */
class Stack extends SplStack implements Serializable
{
	public function serialize(): string
	{
		$items = iterator_to_array($this);
		return serialize($items);
	}

	public function unserialize(string $data): void
	{
		foreach (unserialize($data) as $item)
			$this->unshift($item);
	}

	public function __toString(): string
	{
		return sprintf('[%s]', implode(', ', iterator_to_array($this)));
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

	public function __construct(mixed $first = null, mixed $second = null)
	{
		$this->first = $first;

		$this->second = $second;
	}

	public function offsetExists(mixed $n): bool
	{
		return $n == 0 || $n == 1;
	}

	public function offsetGet(mixed $n): mixed
	{
		if ($n == 0)
			return $this->first;

		elseif ($n == 1)
			return $this->second;

		else
			throw new Exception('Index out of bounds exception');
	}

	public function offsetSet(mixed $n, mixed $value): void
	{
		if ($n == 0)
			$this->first = $value;

		elseif ($n == 1)
			$this->second = $value;

		else
			throw new Exception('Index out of bounds exception');
	}

	public function offsetUnset(mixed $n): void
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

	private $__PARENT__;

	private $__BLOCK__;

	public function __construct(string $file, array $data = [])
	{
		$this->__TEMPLATE__ = $file;
		$this->__DATA__ = $data;
	}

	public function __set(string $key, mixed $value)
	{
		$this->__DATA__[$key] = $value;
	}

	public function render(): string
	{
		ob_start();
		extract($this->__DATA__);
		include $this->__TEMPLATE__;

		if ($this->__PARENT__) {
			ob_end_clean();
			return $this->__PARENT__->render();
		} else {
			return ob_get_clean();
		}
	}

	protected function extends(string $template): void
	{
		if ($this->__PARENT__)
			throw new LogicException('Cannot call Template::extend twice from the same template');

		$this->__PARENT__ = new Template(dirname($this->__TEMPLATE__) . '/' . $template, $this->__DATA__);
	}

	protected function begin(string $block_name): void
	{
		if (!$this->__PARENT__)
			throw new LogicException('You cannot begin a block while not extending a parent template');

		if ($this->__BLOCK__)
			throw new LogicException('You cannot have a block inside a block in templates');

		$this->__BLOCK__ = $block_name;
		ob_start();
	}

	protected function end(): void
	{
		if (!$this->__BLOCK__)
			throw new LogicException('Calling Template::end while not in a block. Template::begin missing?');

		$this->__PARENT__->__set($this->__BLOCK__, ob_get_clean());
		$this->__BLOCK__ = null;
	}

	static public function html(string $data): string
	{
		return htmlspecialchars($data, ENT_COMPAT, 'utf-8');
	}

	static public function attr(string $data): string
	{
		return htmlspecialchars($data, ENT_QUOTES, 'utf-8');
	}

	static public function id(string $data): string
	{
		return preg_replace('/[^a-z0-9_]/i', '_', $data);
	}

	static public function format_plain_text(string $text): string
	{
		$plain_paragraphs = new ArrayIterator(preg_split("/\r?\n\r?\n/", $text));

		$formatted_paragraphs = iterator_map($plain_paragraphs,
			function($plain_paragraph) {
				return sprintf('<p>%s</p>', nl2br(self::html(trim($plain_paragraph))));
			});

		return implode("\n", iterator_to_array($formatted_paragraphs));
	}

	static public function format_code(string $code, ?int $line_no_offset = null): string
	{
		static $line_no = 1;

		if ($line_no_offset !== null)
			$line_no = $line_no_offset;

		$wrapped_lines = array();

		foreach (explode("\n", $code) as $line)
			$wrapped_lines[] = sprintf('<pre data-lineno="%d">%s</pre>',
				$line_no++, self::html($line));

		return implode("\n", $wrapped_lines);
	}
}

function first_found_path(array $possible_paths): mixed
{
	foreach ($possible_paths as $path)
		if (file_exists($path))
			return $path;

	return null;
}

function to_debug_string(mixed $value): string
{
	if ($value instanceof Traversable)
		$value = iterator_to_array($value);

	if (is_array($value))
		return implode(', ', array_map('to_debug_string', $value));

	return strval($value);
}

function dict_to_string(array $dict, string $pair_format = '%s => %s', string $dict_format = '[%s]'): string
{
	return sprintf($dict_format, implode(', ', array_map(function($key, $value) use ($pair_format) {
		return sprintf($pair_format, $key, $value);
	}, array_keys($dict), array_values($dict))));
}

define('LOG_LEVEL_WARNING', 3);

define('LOG_LEVEL_INFO', 2);

define('LOG_LEVEL_VERBOSE', 1);

interface Logger
{
	public function write(string $format, array $arguments, int $level);
}
