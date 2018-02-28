<?php

/**
 * XML Reader die een knowledge base xml bestand inleest naar
 * een KnowledgeDomain object. Meer niet. Onbekende elementen
 * leveren een notice op, ontbrekende attributen en elementen
 * een error.
 *
 * Gebruik:
 * <code>
 *   $reader = new KnowledgeBaseReader;
 *   $kb = $reader->parse('knowledge.xml');
 *   assert($kb instanceof KnowledgeDomain);
 * </code>
 */
class KnowledgeBaseReader
{
	/**
	 * Leest een knowledge base in.
	 *
	 * @param string file bestandsnaam van knowledge.xml
	 * @return KnowledgeState
	 */
	public function parse($file)
	{
		$doc = new DOMDocument();

		$kb = new KnowledgeDomain();

		// backup-titel, een <title/>-element in het bestand zal dit overschrijven.
		$kb->title = basename($file, '.xml');

		if (!file_exists($file))
			throw new InvalidArgumentException('Cannot parse knowledge base: file does not exist');

		$doc->load($file, LIBXML_NOCDATA & LIBXML_NOBLANKS);

		if (!$doc->firstChild)
			return $this->logError('Could not parse xml document', E_USER_WARNING);

		$this->parseKnowledgeBase($doc->firstChild, $kb);

		return $kb;
	}

	/**
	 * Kijk of een knowledge base klopt en ingeladen kan worden.
	 *
	 * @param string file bestandsnaam van knowledge.xml
	 * @return object[]
	 */
	public function lint($file)
	{
		$errors = array();

		$previous_assert_state = assert_options(ASSERT_ACTIVE);
		assert_options(ASSERT_ACTIVE, true);

		$previous_assert_mode = assert_options(ASSERT_BAIL);
		assert_options(ASSERT_BAIL, false);
		
		set_error_handler(function($number, $message, $file, $line) use (&$errors) {
			if (preg_match('/^assert\(\): (.+?)$/', $message, $match))
				$message = html_entity_decode($match[1]);

			$errors[] = (object) compact('number', 'message', 'file', 'line');
		});

		try {
			$this->parse($file);
		} catch (Error $e) {
			$errors[] = (object) array(
				'number' => $e->getCode(),
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			);
		} catch (Exception $e) {
			$errors[] = (object) array(
				'number' => $e->getCode(),
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			);
		}

		assert_options(ASSERT_BAIL, $previous_assert_mode);
		assert_options(ASSERT_ACTIVE, $previous_assert_state);
		restore_error_handler();

		return $errors;
	}

	private function parseKnowledgeBase($node, $kb)
	{
		if ($node->nodeName != "knowledge")
			$this->logError('The document root node is not a <knowledge/> element', E_USER_WARNING);

		if ($node->hasAttribute('algorithm'))
		{
			if (!in_array($node->getAttribute('algorithm'), ['forward-chaining', 'backward-chaining']))
				$this->logError("Unknown inference algorithm. Please choose 'forward-chaining' or 'backward-chaining'.", E_USER_WARNING);
			else
				$kb->algorithm = $node->getAttribute('algorithm');
		}

		foreach ($this->childElements($node) as $childNode)
		{
			switch ($childNode->nodeName)
			{
				case 'rule':
					$rule = $this->parseRule($childNode);
					$kb->rules->push($rule);
					break;
				
				case 'question':
					$question = $this->parseQuestion($childNode);
					$kb->questions->push($question);
					break;
				
				case 'goal':
					$goal = $this->parseGoal($childNode);
					$kb->goals->push($goal);
					break;
				
				/*
				case 'constraint':
					$constraint = $this->parseConstraint($childNode);
					$kb->constraints[] = $constraint;
					break;
				*/

				case 'fact':
					list($name, $value) = $this->parseFact($childNode);
					$kb->facts[$name] = $value;
					break;

				case 'title':
					$kb->title = $this->parseText($childNode);
					break;
				
				case 'description':
					$kb->description = $this->parseText($childNode);
					break;

				default:
					$this->logError("KnowledgeBaseReader::parseKnowledgeBase: "
						. "Skipping unknown element {$childNode->nodeName}",
						E_USER_NOTICE);
					continue;
			}
		}
	}

	private function parseRule($node)
	{
		$rule = new Rule;

		$rule->line_number = $node->getLineNo();

		if ($node->hasAttribute('priority'))
			$rule->priority = intval($node->getAttribute('priority'));

		foreach ($this->childElements($node) as $childNode)
		{
			switch ($childNode->nodeName)
			{
				case 'description':
					$rule->description = $this->parseText($childNode);
					break;
				
				case 'if':
					$rule->condition = $this->parseRuleCondition($childNode);
					break;
				
				case 'then':
					$rule->consequences = $this->parseConsequences($childNode);
					break;
				
				default:
					$this->logError("KnowledgeBaseReader::parseRule: "
						. "Skipping unknown element {$childNode->nodeName}",
						E_USER_NOTICE);
					continue;
			}
		}

		if ($rule->condition === null)
			$this->logError("KnowledgeBaseReader::parseRule: "
				. "'rule' node on line " . $node->getLineNo()
				. " has no condition (missing or empty 'if' node)",
				E_USER_WARNING);

		if ($rule->consequences === null || count($rule->consequences) === 0)
			$this->logError("KnowledgeBaseReader::parseRule: "
				. "'rule' node on line " . $node->getLineNo()
				. " has no consequences (missing or empty 'then' node)",
				E_USER_WARNING);

		$rule->inferred_facts->pushAll(array_keys($rule->consequences));

		return $rule;
	}

	private function parseQuestion($node)
	{
		$question = new Question;

		$question->line_number = $node->getLineNo();

		if ($node->hasAttribute('priority'))
			$question->priority = intval($node->getAttribute('priority'));

		foreach ($this->childElements($node) as $childNode)
		{
			switch ($childNode->nodeName)
			{
				case 'description':
					$question->description = $this->parseText($childNode);
					break;
				
				case 'option':
					$question->options[] = $this->parseOption($childNode);
					break;
				
				default:
					$this->logError("KnowledgeBaseReader::parseQuestion: "
						. "Skipping unknown element '{$childNode->nodeName}'",
						E_USER_NOTICE);
					continue;
			}
		}

		if ($question->description === null)
			$this->logError("KnowledgeBaseReader::parseQuestion: "
				. "'question' node on line " . $node->getLineNo()
				. " is missing a 'description' element",
				E_USER_WARNING);

		if (count($question->options) === 0)
			$this->logError("KnowledgeBaseReader::parseQuestion: "
				. "'question' node on line " . $node->getLineNo()
				. " has no possible answers (no 'option' elements)",
				E_USER_WARNING);

		if (count($question->options) === 1)
			$this->logError("KnowledgeBaseReader::parseQuestion: "
				. "'question' node on line " . $node->getLineNo()
				. " has only one possible answer",
				E_USER_NOTICE);

		foreach ($question->options as $option)
			foreach (array_keys($option->consequences) as $inferred_fact)
				$question->inferred_facts->push($inferred_fact);
		
		return $question;
	}

	private function parseGoal($node)
	{
		$goal = new Goal;

		$goal->name = $node->getAttribute('name');

		foreach ($this->childElements($node) as $childNode)
		{
			switch ($childNode->nodeName)
			{
				case 'description':
					$goal->description = $this->parseText($childNode);
					break;
				
				case 'answer':
					$goal->answers->push($this->parseAnswer($childNode));
					break;
				
				default:
					$this->logError("KnowledgeBaseReader::parseGoal: "
						. "Skipping unknown element '{$childNode->nodeName}'",
						E_USER_NOTICE);
					continue;
			}
		}

		return $goal;
	}

	private function parseConstraint($node)
	{
		//
	}

	private function parseRuleCondition($node)
	{
		$childNodes = iterator_to_array($this->childElements($node));
		
		if (count($childNodes) !== 1)
			$this->logError("KnowledgeBaseReader::parseRuleCondition: "
				. "'" . $node->nodeName . "' node on line " . $node->getLineNo()
				. " does not contain exactly one condition (a 'and'/'or'/'not'/'fact' node).",
				E_USER_WARNING);

		return $this->parseCondition(current($childNodes));
	}

	private function createContainer($containerClass, \DOMElement $node)
	{
		// Look at the constructor of the container class to see which attributes
		// are available, e.g. the threshold parameter for the <some> condition. 
		$refl = new \ReflectionClass($containerClass);
		$constructor = $refl->getConstructor();

		if ($constructor !== null)
		{
			$values = [];

			foreach ($constructor->getParameters() as $param)
			{
				if ($node->hasAttribute($param->getName()))
					$values[] = $node->getAttribute($param->getName());
				elseif ($param->isDefaultValueAvailable())
					$values[] = $param->getDefaultValue();
				else {
					$this->logError("KnowledgeBaseReader::createContainer: "
						. "'" . $node->nodeName . "' node on line " . $node->getLineNo()
						. " requires an attribute named '" . $param->getName() . "'",
						E_USER_WARNING);
					$values[] = null;
				}
			}
		}
		else
			$values = [];
		
		return $refl->newInstanceArgs($values);
	}

	private function parseConditionSet($node, $containerClass)
	{
		$container = $this->createContainer($containerClass, $node);

		foreach ($this->childElements($node) as $childNode)
		{
			$childCondition = $this->parseCondition($childNode);

			if ($childCondition)
				$container->addCondition($childCondition);
		}

		if (count($container->conditions) === 0)
			$this->logError("KnowledgeBaseReader::parseConditionSet: "
				. "'" . $node->nodeName . "' node on line " . $node->getLineNo()
				. " has no child conditions (missing 'and'/'or'/'not'/'fact' nodes)",
				E_USER_WARNING);

		return $container;
	}

	private function parseCondition($node)
	{
		switch ($node->nodeName)
		{
			case 'fact':
				$condition = $this->parseFactCondition($node);
				break;
			
			case 'not':
				$condition = $this->parseNegationCondition($node);
				break;
			
			case 'some':
				$condition = $this->parseConditionSet($node, WhenSomeCondition::class);
				break;

			case 'and':
				$condition = $this->parseConditionSet($node, WhenAllCondition::class);
				break;

			case 'or':
				$condition = $this->parseConditionSet($node, WhenAnyCondition::class);
				break;

			default:
				$this->logError("KnowledgeBaseReader::parseCondition: "
					. "Skipping unknown element '{$node->nodeName}'",
					E_USER_NOTICE);
				$condition = null;
				continue;
		}

		return $condition;
	}

	private function parseFactCondition($node)
	{
		if (!$node->hasAttribute('name'))
			$this->logError("KnowledgeBaseReader::parseFactCondition: "
				. "'fact' node is missing a 'name' attribute.",
				E_USER_WARNING);

		$name = $node->getAttribute('name');
		$value = $this->parseText($node);

		$test = $node->hasAttribute('test')
			? $node->getAttribute('test')
			: 'eq';

		return new FactCondition($name, $value, $test);
	}

	private function parseNegationCondition($node)
	{
		$condition = $this->parseCondition($this->firstElement($node->firstChild));
		return new NegationCondition($condition);
	}

	private function parseConsequences($node)
	{
		$consequences = array();

		foreach ($this->childElements($node) as $childNode)
		{
			list($name, $value) = $this->parseFact($childNode);
			$consequences[$name] = $value;
		}

		return $consequences;
	}

	private function parseFact($node)
	{
		switch ($node->nodeName)
		{
			case 'fact':
				if (!$node->hasAttribute('name'))
					$this->logError("KnowledgeBaseReader::parseFact: "
						. "'fact' node missing 'name' attribute",
						E_USER_WARNING);

				$name = $node->getAttribute('name');
				$value = $this->parseText($node);
				return array($name, $value);
							
			default:
				$this->logError("KnowledgeBaseReader::parseFact: "
					. "Skipping unknown element '{$node->nodeName}'",
					E_USER_NOTICE);
				continue;
		}
	}

	private function parseOption($node)
	{
		$option = new Option;

		foreach ($this->childElements($node) as $childNode)
		{
			switch ($childNode->nodeName)
			{
				case 'description':
					$option->description = $this->parseText($childNode);
					break;
				
				case 'then':
					$option->consequences = $this->parseConsequences($childNode);
					break;
				
				default:
					$this->logError("KnowledgeBaseReader::parseOption: "
						. "Skipping unknown element '{$childNode->nodeName}'",
						E_USER_NOTICE);
					continue;
			}
		}

		if ($option->description == '')
			$this->logError("KnowledgeBaseReader::parseOption: "
				. "'option' node on line " . $node->getLineNo()
				. " has no description (missing or empty 'description' node)",
				E_USER_WARNING);

		if (count($option->consequences) === 0)
			$this->logError("KnowledgeBaseReader::parseOption: "
				. "'option' node on line " . $node->getLineNo()
				. " has no consequences (missing 'then' node)",
				E_USER_WARNING);

		return $option;
	}

	private function parseAnswer($node)
	{
		$answer = new Answer;

		$answer->value = $node->hasAttribute('value')
			? $node->getAttribute('value')
			: null;
		
		$answer->description = $this->parseText($node);

		return $answer;
	}

	private function parseText(DOMNode $node)
	{
		return $node->firstChild ? trim($node->firstChild->data) : '';
	}

	private function firstElement($node)
	{
		while ($node && $node->nodeType != XML_ELEMENT_NODE)
			$node = $node->nextSibling;
		
		return $node;
	}

	private function childElements($node)
	{
		assert($node instanceof DOMElement,
			'$node is not an element that can have child nodes');

		return new DOMElementIterator(new DOMNodeIterator($node->childNodes));
	}

	private function logError($message, $error_level)
	{
		trigger_error($message, $error_level);
	}
}

/**
 * Waardeloos van PHP: DOMNodeList is itereerbaar, maar is niet
 * een array (dus ArrayIterator valt af) en ook niet een Iterator
 * (dus IteratorIterator valt af). Dan maar zelf een Iterator maken.
 */
class DOMNodeIterator implements Iterator
{
	private $nodeList;

	private $position = 0;

	public function __construct(DOMNodeList $nodeList)
	{
		$this->nodeList = $nodeList;
	}

	function rewind()
	{
		$this->position = 0;
	}

	function current()
	{
		return $this->nodeList->item($this->position);
	}

	function key()
	{
		return $this->position;
	}

	function next()
	{
		++$this->position;
	}

	function valid()
	{
		return $this->position < $this->nodeList->length;
	}
}

/**
 * Itereert alleen over XML Elementen, slaat text-nodes e.d. over.
 */
class DOMElementIterator extends FilterIterator
{
	public function accept()
	{
		return self::current()->nodeType == XML_ELEMENT_NODE;
	}
}

/**
 * PHP 5 compatibility
 */
if (!class_exists('Error')) {
	class Error extends Exception {}
}

/*
function test_reader()
{
	include_once 'solver.php';

	$reader = new KnowledgeBaseReader();

	$kb = $reader->parse('regen.xml');

	foreach ($kb->goals as $goal)
	{
		$result = $kb->infer($goal->proof);

		printf("%s: %s\n",
			$goal->description,
			$reader->stringifyTruthValue($result));
	}
}

test_reader();
*/
