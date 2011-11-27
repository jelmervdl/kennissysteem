<?php

/**
 * XML Reader die een knowledge base xml bestand inleest naar
 * een KnowledgeState object. Meer niet. Onbekende elementen
 * leveren een notice op, ontbrekende attributen en elementen
 * een error.
 *
 * Gebruik:
 * <code>
 *   $reader = new KnowledgeBaseReader;
 *   $kb = $reader->parse('knowledge.xml');
 *   assert($kb instanceof KnowledgeState);
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

		$kb = new KnowledgeState;

		$doc->load($file, LIBXML_NOCDATA & LIBXML_NOBLANKS);

		$this->parseKnowledgeBase($doc->firstChild, $kb);

		return $kb;
	}

	private function parseKnowledgeBase($node, $kb)
	{
		assert($node->nodeName == 'knowledge');

		foreach ($this->childElements($node) as $childNode)
		{
			switch ($childNode->nodeName)
			{
				case 'rule':
					$rule = $this->parseRule($childNode);
					$kb->rules[] = $rule;
					break;
				
				case 'question':
					$question = $this->parseQuestion($childNode);
					$kb->questions[] = $question;
					break;
				
				case 'goal':
					$goal = $this->parseGoal($childNode);
					$kb->goals[] = $goal;
					break;
				
				case 'fact':
					list($name, $value) = $this->parseFact($childNode);
					$kb->facts[$name] = $value;
					break;

				default:
					trigger_error("KnowledgeBaseReader::parseKnowledgeBase: "
						. "Skipping unknown element {$childNode->nodeName}",
						E_USER_NOTICE);
					continue;
			}
		}
	}

	private function parseRule($node)
	{
		$rule = new Rule;

		$rule->inferred_facts = explode(' ', $node->getAttribute('infers'));

		foreach ($this->childElements($node) as $childNode)
		{
			switch ($childNode->nodeName)
			{
				case 'description':
					$rule->description = $childNode->firstChild->data;
					break;
				
				case 'when':
				case 'when_all':
				case 'when_any':
					$rule->condition = $this->parseConditionSet($childNode);
					break;
				
				case 'then':
					$rule->consequences = $this->parseConsequences($childNode);
					break;
				
				default:
					trigger_error("KnowledgeBaseReader::parseRule: "
						. "Skipping unknown element {$childNode->nodeName}",
						E_USER_NOTICE);
					continue;
			}
		}

		return $rule;
	}

	private function parseQuestion($node)
	{
		$question = new Question;

		$question->inferred_facts = explode(' ', $node->getAttribute('infers'));

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
					trigger_error("KnowledgeBaseReader::parseQuestion: "
						. "Skipping unknown element {$childNode->nodeName}",
						E_USER_NOTICE);
					continue;
			}
		}
		
		return $question;
	}

	private function parseGoal($node)
	{
		$goal = new Goal;

		foreach ($this->childElements($node) as $childNode)
		{
			switch ($childNode->nodeName)
			{
				case 'description':
					$goal->description = $this->parseText($childNode);
					break;
				
				case 'proof':
					$goal->proof = $this->parseText($childNode);
					break;
				
				default:
					trigger_error("KnowledgeBaseReader::parseGoal: "
						. "Skipping unknown element {$childNode->nodeName}",
						E_USER_NOTICE);
					continue;
			}
		}

		return $goal;
	}

	private function parseConditionSet($node)
	{
		switch ($node->nodeName)
		{
			case 'when':
			case 'when_all':
				$condition = new WhenAllCondition;
				break;
			
			case 'when_any':
				$condition = new WhenAnyCondition;
				break;
		}

		assert($condition instanceof Condition);

		foreach ($this->childElements($node) as $childNode)
		{
			$childCondition = $this->parseCondition($childNode);

			if ($childCondition)
				$condition->addCondition($childCondition);
		}

		return $condition;
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
			
			case 'when':
			case 'when_all':
			case 'when_any':
				$condition = $this->parseConditionSet($node);
				break;

			default:
				trigger_error("KnowledgeBaseReader::parseCondition: "
					. "Skipping unknown element {$childNode->nodeName}",
					E_USER_NOTICE);
				continue;
		}

		return $condition;
	}

	private function parseFactCondition($node)
	{
		$fact_name = trim($node->firstChild->data);
		return new FactCondition($fact_name);
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
				$value = $node->hasAttribute('value')
					? $node->getAttribute('value')
					: 'true';
				
				$name = $this->parseText($node);

				$truth_value_type = $this->parseTruthValueType($value);

				return array($name, new $truth_value_type(array($name)));
				break;
			
			default:
				trigger_error("KnowledgeBaseReader::parseFact: "
					. "Skipping unknown element {$node->nodeName}",
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
					trigger_error("KnowledgeBaseReader::parseOption: "
						. "Skipping unknown element {$childNode->nodeName}",
						E_USER_NOTICE);
					continue;
			}
		}

		return $option;
	}

	private function parseText($node)
	{
		return trim($node->firstChild->data);
	}

	private function parseTruthValueType($value)
	{
		switch ($value)
		{
			case 'true':
				return 'Yes';
			
			case 'false':
				return 'No';
			
			case 'null':
				return 'Maybe';

			default:
				trigger_error("KnowledgeBaseReader::parseTruthValueType: "
					. "Unknown value: {$value}",
					E_USER_NOTICE);
				break;
		}
	}

	public function stringifyTruthValue($value)
	{
		if ($value instanceof Yes)
			return 'yes';
		
		if ($value instanceof No)
			return 'no';
		
		if ($value instanceof Maybe)
			return 'maybe';
		
		else
			return '[invalid value]';
	}

	private function firstElement($node)
	{
		while ($node && $node->nodeType != XML_ELEMENT_NODE)
			$node = $node->nextSibling;
		
		return $node;
	}

	private function childElements($node)
	{
		return new DOMElementIterator(new DOMNodeIterator($node->childNodes));
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