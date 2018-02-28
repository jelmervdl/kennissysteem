<?php

define('STATE_UNDEFINED', 'undefined');

/**
 * A rule which allows you to find a fact.
 *
 * <rule>
 *     [<description>]
 *     <if/>
 *     <then/>
 * </rule>
 */
class Rule
{
	public $inferred_facts;

	public $description;

	public $condition;

	public $consequences = array();

	public $priority = 0;

	public $line_number;

	public function __construct()
	{
		$this->inferred_facts = new Set();
	}

	public function infers($fact)
	{
		return $this->inferred_facts->contains($fact);
	}

	public function evaluate(KnowledgeState $state)
	{
		return $this->condition->evaluate($state);
	}

	public function __toString()
	{
		return sprintf('[Rule %s(line %d)]',
			$this->description ? sprintf('"%s" ', $this->description) : '',
			$this->line_number);
	}
}

/**
 * A question which can answer one of the $inferred_facts.
 *
 * <question>
 *     <description/>
 *     <option/>
 * </question>
 */
class Question
{
	public $inferred_facts;

	public $description;

	public $options = array();

	public $priority = 0;

	public $line_number;

	public function __construct()
	{
		$this->inferred_facts = new Set();
	}

	public function infers($fact)
	{
		return $this->inferred_facts->contains($fact);
	}

	public function __toString()
	{
		return $this->description;
	}
}

class AskedQuestion
{
	public $question;

	public $skippable;

	public function __construct(Question $question, $skippable)
	{
		$this->question = $question;

		$this->skippable = $skippable;
	}

	public function __toString()
	{
		return sprintf('%s (%s)', $this->question, $this->skippable ? 'skippable' : 'not skippable');
	}
}

/**
 * Een mogelijk antwoord op een Question.
 *
 * <option>
 *     <description/>
 *     <then/>
 * </option>
 */
class Option
{
	public $description;

	public $consequences = array();
}

interface Condition
{
	public function evaluate(KnowledgeState $state);

	public function asArray();
}

/**
 * N of the conditions have to be true
 * 
 * <some threshold="n">
 *     Conditions, e.g. <fact/>
 * </some>
 */
class WhenSomeCondition implements Condition
{
	public $conditions;

	public $threshold;

	public function __construct(int $threshold)
	{
		$this->conditions = new Set();

		$this->threshold = $threshold;
	}

	public function addCondition(Condition $condition)
	{
		$this->conditions->push($condition);
	}

	public function evaluate(KnowledgeState $state)
	{
		// Assumption: There has to be at least one condition
		assert(count($this->conditions) >= $this->threshold);

		$values = array();
		foreach ($this->conditions as $condition)
			$values[] = $condition->evaluate($state);
		
		// If threre is at least one Yes, then this condition is met!
		$yesses = array_filter_type('Yes', $values);
		if (count($yesses) >= $this->threshold)
			return Yes::because($yesses);
		
		// If there are still maybe's, then maybe there is still chance
		// for a Yes. So return Maybe.
		$maybes = array_filter_type('Maybe', $values);
		if (count($yesses) + count($maybes) >= $this->threshold)
			return Maybe::because($maybes);

		// Not enough yes, not enough maybe, too many no's. So no.
		return No::because($values);
	}

	public function asArray()
	{
		return array($this, array_map_method('asArray', $this->conditions));
	}
}

/**
 * All conditions need to be true
 * 
 * <and>
 *     Conditions, e.g. <fact/>
 * </and>
 */
class WhenAllCondition extends WhenSomeCondition
{
	public function __construct()
	{
		parent::__construct(1);
	}

	public function addCondition(Condition $condition)
	{
		parent::addCondition($condition);

		$this->threshold = count($this->conditions);
	}
}

/**
 * Just one of the conditions has to be true
 * 
 * <or>
 *     Conditions, e.g. <fact/>
 * </or>
 */
class WhenAnyCondition extends WhenSomeCondition
{
	public function __construct()
	{
		parent::__construct(1);
	}
}


/**
 * Evaluates to the opposite of the condition:
 *   Yes -> No
 *   No -> Yes
 *   Maybe -> Maybe
 * 
 * <not>
 *     Condition, e.g. <fact/>
 * </not>
 */
class NegationCondition implements Condition
{
	public $condition;

	public function __construct(Condition $condition)
	{
		$this->condition = $condition;
	}

	public function evaluate(KnowledgeState $state)
	{
		return $this->condition->evaluate($state)->negate();
	}

	public function asArray()
	{
		return array($this, $this->condition->asArray());
	}
}

/**
 * Check whether a fact has a certain value:
 *   Fact is known and value is the same -> Yes
 *   Fact is known but value is different -> No
 *   Fact is not known -> Maybe
 * 
 * <fact name="fact_name">value</fact>
 */
class FactCondition implements Condition
{
	public $name;

	public $value;

	public $test;

	public function __construct($name, $value, $test = 'eq')
	{
		$this->name = trim($name);
		$this->value = trim($value);
		$this->test = $test;
	}

	public function evaluate(KnowledgeState $state)
	{
		$fact_name = $state->resolve($this->name);

		if ($fact_name instanceof Maybe)
			return $fact_name;

		$state_value = $state->value($fact_name);

		// If the fact is not in the kb, say we can't know whether this condition is true
		if ($state_value === null)
			return Maybe::because([$fact_name]);

		if ($state_value instanceof Maybe)
			return $state_value;

		// if the value is a variable, this will resolve it to a value
		// (or a variable if that isn't known yet to the kb!)
		$test_value = $state->resolve($this->value);

		if ($test_value instanceof Maybe)
			return $test_value;

		return $this->compare($state_value, $test_value)
			? Yes::because([$this->name])
			: No::because([$this->name]);
	}

	protected function compare($lhs, $rhs)
	{
		switch ($this->test)
		{
			case 'gt':
				return intval($lhs) > intval($rhs);

			case 'gte':
				return intval($lhs) >= intval($rhs);
			
			case 'lt':
				return intval($lhs) < intval($rhs);

			case 'lte':
				return intval($lhs) <= intval($rhs);

			case 'eq':
				return $lhs === $rhs;

			default:
				throw new RuntimeException("Unknown test '{$this->test}'. Use gt, gte, lt, lte or eq.");
		}
	}

	public function asArray()
	{
		return array($this);
	}
}

/**
 * A goal as defined in the knowledge base. Besides the name
 * of the fact that is the goal, it can also contain possible
 * Answers, which are descriptions that should be displayed if
 * the fact has the value associcated with the answer. The
 * description of the goal is used in the interface as the
 * question asked. E.g:
 * <goal name="wheater">
 *   <description>What is the weather like?</description>
 *   <answer value="rain">It rains</answer>
 *   <answer value="sunshine">The sun shines</answer>
 *   <answer>Something else</answer>
 * </goal>
 *
 * Syntax in knowledge base:
 * <goal name="">
 *     <description/>
 *	   <answer/>
 * </goal>
 */
class Goal
{
	public $name;
	
	public $description;

	public $answers;

	public function __construct()
	{
		$this->answers = new Set();
	}

	public function hasAnswers()
	{
		return count($this->answers) > 0;
	}

	public function answer(KnowledgeState $state)
	{
		$name = $state->resolve($this->name);

		$state_value = $state->value($name);
		
		foreach ($this->answers as $answer)
		{
			// If this is the default option, return it always.
			if ($answer->value === null)
				return $answer;

			// In case the value in the xml was a variable, resolve it first
			$answer_value = $state->resolve($answer->value);

			if ($state_value === $answer_value)
				return $answer;
		}

		// We didn't find an appropriate answer :O
		return null;
	}
}

/**
 * <answer value="value">message</answer>
 */
class Answer
{
	public $value;

	public $description;
}


/**
 * A truth state works a bit like a boolean, except we can have as many values
 * as we want (in this system we have three: Yes, No and Maybe).
 * The added value of a truth value above a simple boolean or enum is that it
 * can also contain information about how it came to that value, which other
 * facts in this case where responsible for the result.
 */ 
abstract class TruthState
{
	public $factors;

	public function __construct(array $factors)
	{
		$this->factors = $factors;
	}

	public function __toString()
	{
		return sprintf("[%s because: %s]",
			get_class($this),
			implode(', ', array_map('strval', $this->factors)));
	}

	abstract public function negate();

	static public function because($factors = null)
	{
		if (is_null($factors))
			$factors = [];

		elseif (is_scalar($factors))
			$factors = [$factors];

		elseif (is_object($factors) && $factors instanceof Traversable)
			$factors = iterator_to_array($factors);

		assert(is_array($factors));

		$called_class = get_called_class();
		return new $called_class($factors);
	}
}

class Yes extends TruthState
{
	public function negate()
	{
		return new No($this->factors);
	}
}

class No extends TruthState
{
	public function negate()
	{
		return new Yes($this->factors);
	}
}

class Maybe extends TruthState 
{
	public function negate()
	{
		return new Maybe($this->factors);
	}

	public function unknownFacts()
	{
		// This is where the order of the questions is effectively determined.
		// It does this by dividing an "amount of contribution" (1.0 here) among
		// all the causes of this Maybe. Note that the causes (factors) are
		// sometimes nested, e.g. one of the factors may be another Maybe
		// that itself has factors.
		//
		// Example: this maybe has 3 factors, and one of the factors is itself
		// a Maybe with three factors. So first, this 1.0 will be divided among
		// all causes, so every cause will have 0.33. Then, the cause that is 
		// also a Maybe with three factors will divide the 0.33 among its own
		// factors, which will all receive 0.11 (0.33 / 3). Finally, all the
		// factors will be summed: I.e. if the fact 'math_level' occurred
		// multiple times in this tree, all the "contribution" values will be
		// summed.
		$causes = $this->divideAmong(1.0, $this->factors)->data();

		// Then, these causes (a associative array with
		// fact name => contributing weight) will be sorted from large to small
		// contribution value.
		arsort($causes);

		// Now return the fact names (the keys of the map)
		return array_keys($causes);
	}

	private function divideAmong($percentage, array $factors)
	{
		$effects = new Map(0.0);

		// Only count factors that can change (raw fact names and Maybes)
		$factors = array_filter($factors, function($factor) {
			return ($factor instanceof Maybe) or !($factor instanceof TruthState);
		});
		
		// If there are no factors, just return that empty map
		if (count($factors) == 0)
			return $effects;

		// Every factor has the same amount of effect at this level
		// (but this changes as soon as it is also found deeper nested)
		$percentage_per_factor = $percentage / count($factors);

		foreach ($factors as $factor)
		{
			// Recursively divide the "contribution" among each of the factors,
			// and store how much effect each fact has in total (when it occurs)
			// multiple times
			if ($factor instanceof Maybe) {
				foreach ($this->divideAmong($percentage_per_factor, $factor->factors) as $factor_name => $effect)
					$effects[$factor_name] += $effect;
			} else {
				// Not every factor is a truth state: at the end of the tree
				// of factors the leafs are the actual fact names: strings.
				$effects[$factor] += $percentage_per_factor;
			}
		}

		return $effects;
	}
}

class KnowledgeState
{
	public $facts;

	public $goalStack;

	public function __construct()
	{
		$this->facts = [
			'undefined' => STATE_UNDEFINED
		];

		$this->goalStack = new Stack();
	}

	static public function initializeForDomain(KnowledgeDomain $domain)
	{
		$state = new static();

		$state->facts = array_merge($state->facts, $domain->facts);

		foreach ($domain->goals as $goal)
		{
			// Also push any answer values that are variables as goals to be solved.
			foreach ($goal->answers as $answer)
				if (static::is_variable($answer->value))
					$state->goalStack->push(static::variable_name($answer->value));

			// Design decision: first solve the goal, then solve which answer
			// to present.
			$state->goalStack->push($goal->name);
		}

		return $state;
	}

	/**
	 * Past $consequences toe op de huidige $state, en geeft dat als nieuwe state terug.
	 * Alle $consequences krijgen $reason als reden mee.
	 * 
	 * @return KnowledgeState
	 */
	public function apply(array $consequences)
	{
		$this->facts = array_merge($this->facts, $consequences);
	}

	/**
	 * Returns the value of a fact, or null if not found. Do not call with
	 * variables as fact_name. If $fact_name is or could be a variable, first
	 * use KnowledgeState::resolve on it.
	 * 
	 * @param string $fact_name
	 * @return mixed
	 */
	public function value($fact_name)
	{
		if (static::is_variable($fact_name))
			throw new RuntimeException('Called KnowledgeState::value with variable');

		if (!isset($this->facts[$fact_name]))
			return null;

		return $this->resolve($this->facts[$fact_name]);
	}

	public function resolve($fact_name)
	{
		$stack = array();

		while (static::is_variable($fact_name))
		{
			if (in_array($fact_name, $stack))
				throw new RuntimeException("Infinite recursion when trying to retrieve fact '$fact_name' after I retrieved " . implode(', ', $stack) . ".");

			$stack[] = $fact_name;

			if (isset($this->facts[static::variable_name($fact_name)]))
				$fact_name = $this->facts[static::variable_name($fact_name)];
			else
				return Maybe::because([static::variable_name($fact_name)]);
		}

		return $fact_name;
	}

	public function substitute_variables($text, $formatter = null)
	{
		$callback = function($match) use ($formatter) {
			$value = $this->value($match[1]);

			if ($value === null)
				return $match[0];

			if ($formatter)
				$value = call_user_func_array($formatter, [$value]);

			return $value;
		};

		return preg_replace_callback('/\$([a-z][a-z0-9_]*)\b/i', $callback, $text);
	}

	static public function is_variable($fact_name)
	{
		return substr($fact_name, 0, 1) == '$';
	}

	static public function variable_name($fact_name)
	{
		return substr($fact_name, 1); // strip of the $
	}
}

/**
 * KnowledgeState represents the knowledge base at a certain moment: used rules
 * are removed, facts are added, etc.
 */
class KnowledgeDomain
{
	public $algorithm;

	public $title;

	public $description;

	public $facts;

	public $rules;

	public $questions;

	public $goals;

	public function __construct()
	{
		$this->algorithm = 'backward-chaining';

		$this->facts = [];

		$this->rules = new Set();

		$this->questions = new Set();

		$this->goals = new Set();	
	}
}

/**
 * Solver is een forward & backward chaining implementatie die op basis van
 * een knowledge base (een berg regels, mogelijke vragen en gaandeweg feiten)
 * blijft zoeken, regels toepassen en vragen kiezen totdat alle goals opgelost
 * zijn. Gebruik Solver::backwardChain(state) tot deze geen vragen meer teruggeeft.
 */
class Solver
{
	protected $log;

	public function __construct(Logger $log = null)
	{
		$this->log = $log;
	}

	/**
	 * Probeer gegeven een initiële $knowledge state en een lijst van $goals
	 * zo veel mogelijk $goals op te lossen. Dit doet hij door een stack met
	 * goals op te lossen. Als een goal niet op te lossen is, kijkt hij naar
	 * de meest primaire reden waarom (Maybe::$factors) en voegt hij die factor
	 * op top van de goal stack.
	 * Als een goal niet op te lossen is omdat er geen vragen/regels meer voor 
	 * zijn geeft hij een Notice en gaat hij verder met de andere goals op de
	 * stack.
	 *
	 * @param KnowledgeState $knowledge begin-state
	 * @return AskedQuestion | null
	 */
	public function backwardChain(KnowledgeDomain $domain, KnowledgeState $state)
	{
		// herhaal zo lang er goals op de goal stack zitten
		while (!$state->goalStack->isEmpty())
		{
			$goal = $state->goalStack->top();

			$this->log('Trying to solve %s', [$goal]);

			// probeer het eerste goal op te lossen
			$result = $this->solve($domain, $state, $goal);

			// Oh, dat resulteerde in een vraag. Stel hem (of geef hem terug om 
			// de interface hem te laten stellen eigenlijk.)
			if ($result instanceof AskedQuestion)
			{
				$this->log('This resulted in the question %s', [$result]);

				return $result;
			}

			// Goal is niet opgelost, het antwoord is nog niet duidelijk.
			elseif ($result instanceof Maybe)
			{
				// waarom niet? $causes bevat een lijst van facts die niet
				// bekend zijn, dus die willen we proberen op te lossen.
				$causes = $result->unknownFacts();

				$this->log('Cannot solve %s because %s are not known yet', [$goal, $causes]);

				// echo '<pre>', print_r($causes, true), '</pre>';

				// er zijn facts die nog niet zijn afgeleid
				while (count($causes) > 0)
				{
					// neem het meest invloedrijke fact, leidt dat af
					$main_cause = array_shift($causes);

					// meest invloedrijke fact staat al op todo-lijst?
					// sla het over.
					// TODO: misschien beter om juist naar de top te halen?
					// en dan dat opnieuw proberen te bewijzen?
					if (iterator_contains($state->goalStack, $main_cause))
						continue;
					
					// zet het te bewijzen fact bovenaan op de todo-lijst.
					$state->goalStack->push($main_cause);
					$this->log('Added %s to the goal stack; the stack is now %s', [$main_cause, $state->goalStack], LOG_LEVEL_VERBOSE);

					// .. en spring terug naar volgende goal op goal-stack!
					continue 2; 
				}

				// Er zijn geen redenen waarom het goal niet afgeleid kon worden? Ojee!
				if (count($causes) == 0)
				{
					// Remove the unsatisfied goal from our todo-list as there is nothing to be done
					$unsatisfied_goal = $state->goalStack->pop();
					$this->log('Removing %s from the goal stack', [$unsatisfied_goal], LOG_LEVEL_VERBOSE);

					// Mark it as UNDEFINED so that there will be no further tries to solve it.
					$state->apply(array($unsatisfied_goal => STATE_UNDEFINED));
					$this->log('Mark %s as a STATE_UNDEFINED as there are no options to come to a value', [$unsatisfied_goal], LOG_LEVEL_WARNING);
				}
			}

			// Yes, het is gelukt om een Yes of No antwoord te vinden voor dit goal.
			// Mooi, dan kan dat van de te bewijzen stack af.
			else
			{
				$this->log('Found %s to be %s', [$state->goalStack->top(), $result]);
				
				// Assumption: the solved goal is now part of the knowledge state, and when asking
				// its value it will not return maybe.
				assert(!($state->resolve($state->goalStack->top()) instanceof Maybe));

				// Remove it from the goal stack.
				$removed_goal = $state->goalStack->pop();
				$this->log('Removing %s from the goal stack; the stack is now %s', [$removed_goal, $state->goalStack], LOG_LEVEL_VERBOSE);
			}
		}
	}

	/**
	 * Solve probeert één $goal op te lossen door regels toe te passen of
	 * relevante vragen te stellen. Als het lukt een regel toe te passen of
	 * een vraag te stellen geeft hij een nieuwe $state terug. Ook geeft hij
	 * de TruthState voor $goal terug. In het geval van Maybe kan dat gebruikt
	 * worden om af te leiden welk $goal als volgende moet worden afgeleid om
	 * verder te komen.
	 *
	 * @param KnowledgeState $state huidige knowledge state
	 * @param string goal naam van het fact dat wordt afgeleid
	 * @return TruthState | AskedQuestion
	 */
	public function solve(KnowledgeDomain $domain, KnowledgeState $state, $goal_name)
	{
		// First make sure that if goal_name is a variable, we resolve it to a
		// value (a real goal name).
		$goal = $state->resolve($goal_name);

		// If we can't get to the real goal name because it depends on a variable
		// being known, KnowledgeState::resolve will give us a Maybe that tells
		// us where to look. backwardChain will add it to the goal stack.
		if ($goal instanceof Maybe)
			return $goal;

		// Test whether the fact is already in the knowledge base and if not, if it is solely
		// unknown because we don't know the current goal we try to prove. Because, it could
		// have a variable as value which still needs to be resolved, but that might be a
		// different goal!
		$current_value = $state->value($goal);

		if ($current_value !== null)
			return $current_value;

		// Search the rules for rules that may help us get to our goal
		$relevant_rules = filter($domain->rules,
			function($rule) use ($goal) { return $rule->infers($goal); });

		// Is there a question that might lead us to solving this goal?
		$relevant_questions = filter($domain->questions,
			function($question) use ($goal) { return $question->infers($goal); });

		$this->log("Found %s rules and %s questions",
			[count($relevant_rules), count($relevant_questions)], LOG_LEVEL_VERBOSE);

		// Also keep a list of rules that were undecided, as we can use these
		// later on to decide which goal to solve first
		$maybes = [];
		
		foreach ($relevant_rules as $rule)
		{
			$rule_result = $rule->evaluate($state);

			$this->log("Rule %s results in %s", [$rule, $rule_result],
				$rule_result instanceof Maybe ? LOG_LEVEL_VERBOSE : LOG_LEVEL_INFO);

			// If it was decided as true, add the antecedent to the state
			if ($rule_result instanceof Yes)
			{
				$this->log("Adding %s to the facts dictionary", [dict_to_string($rule->consequences)]);

				// Update the knowledge state
				$state->apply($rule->consequences);

				// no need to look to further rules, this one was true, right?
				return $state->value($goal);
			}
			else if ($rule_result instanceof Maybe)
				$maybes[] = $rule_result;
		}

		// If this problem can be solved by a rule, use it!
		if (count($maybes) > 0)
			return Maybe::because($maybes);

		// If not, but when we do have a question to solve it, use that instead.
		if (count($relevant_questions) > 0)
		{
			$question = current($relevant_questions);

			// deze vraag is alleen over te slaan als er nog regels open staan om dit feit
			// af te leiden of als er alternatieve vragen naast deze (of eerder gestelde,
			// vandaar $n++) zijn.
			$skippable = count($relevant_questions) > 1;

			return new AskedQuestion($question, $skippable);
		}

		// We have no idea how to solve this. No longer our problem!
		// (The caller should set $goal to undefined or something.)
		return Maybe::because([]);
	}

	public function forwardChain(KnowledgeDomain $domain, KnowledgeState $state)
	{
		$rules = clone $domain->rules;

		while (!$rules->isEmpty())
		{
			foreach ($rules as $rule)
			{
				$rule_result = $rule->evaluate($state);

				$this->log("Rule %s results in %s", [$rule, $rule_result],
					$rule_result instanceof Maybe ? LOG_LEVEL_VERBOSE : LOG_LEVEL_INFO);

				// If the rule was true, add the consequences, the inferred knowledge
				// to the knowledge state and continue applying rules on the new knowledge.
				if ($rule_result instanceof Yes)
				{
					$rules->remove($rule);

					$this->log("Adding %s to the facts dictionary", [dict_to_string($rule->consequences)]);

					$state->apply($rule->consequences);
					continue 2;
				}

				// If the rule could not be decided due to missing facts, and that
				// fact can be asked through a question, ask that question!
				if ($rule_result instanceof Maybe)
				{
					foreach ($domain->questions as $question)
						foreach ($rule_result->causes() as $factor)
							if ($question->infers($factor))
								return new AskedQuestion($question, false);
				}
			}

			// None of the rules changed the state: stop trying.
			break;
		}
	}

	protected function log($format, array $arguments = [], $level = LOG_LEVEL_INFO)
	{
		if (!$this->log)
			return;

		$this->log->write($format, $arguments, $level);
	}
}
