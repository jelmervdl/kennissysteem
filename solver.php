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
		return sprintf('[Rule "%s" (line %d)]',
			$this->description,
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

	public function addOption(Option $option)
	{
		$this->options[] = $option;
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
		return sprintf('%s (%s)', $this->question->__toString(), $this->skippable ? 'skippable' : 'not skippable');
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
			return new Yes($this, $yesses);
		
		// If there are still maybe's, then maybe there is still chance
		// for a Yes. So return Maybe.
		$maybes = array_filter_type('Maybe', $values);
		if (count($yesses) + count($maybes) >= $this->threshold)
			return new Maybe($this, $maybes);

		// Not enough yes, not enough maybe, too many no's. So no.
		return new No($this, $values);
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
		return $this->condition->evaluate($state)->negate($this);
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
			return new Maybe($this, [$this->name]);

		// If it is partially in the knowledge base (i.e. it is the value of a
		// variable, but we don't know the value of that variable yet)
		if (KnowledgeState::is_variable($state_value))
			return new Maybe($this, [KnowledgeState::variable_name($state_value)]);

		// if the value is a variable, this will resolve it to a value
		// (or a variable if that isn't known yet to the kb!)
		$test_value = $state->resolve($this->value);

		if ($test_value instanceof Maybe)
			return $test_value;

		return $this->compare($state_value, $test_value)
			? new Yes($this, [$state->reason($this->name)])
			: new No($this, [$state->reason($this->name)]);
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
	
	public $reason; // The thing that generated the value, e.g. a rule, condition, etc.

	public function __construct($reason = null, iterable $factors = null)
	{
		if (is_object($factors))
			$factors = iterator_to_array($factors);

		if (is_null($factors))
			$factors = [];

		foreach ($factors as $factor)
			assert(
				($factor instanceof TruthState)
				or ($factor instanceof Reason)
				or is_string($factor)
			, 'factor is a ' . gettype($factor));

		$this->factors = $factors;
		
		$this->reason = $reason;
	}

	public function __toString()
	{
		return sprintf("[%s because: %s]",
			get_class($this),
			implode(', ', array_map('strval', $this->factors)));
	}

	abstract public function negate(object $reason);
}

class Yes extends TruthState
{
	public function negate(object $reason)
	{
		return new No($reason, [$this]);
	}
}

class No extends TruthState
{
	public function negate(object $reason)
	{
		return new Yes($reason, [$this]);
	}
}

class Maybe extends TruthState 
{
	public function negate(object $reason)
	{
		return new Maybe($reason, [$this]);
	}

	public function causes()
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
			if ($factor instanceof TruthState)
				foreach ($this->divideAmong($percentage_per_factor, $factor->factors) as $factor_name => $effect)
					$effects[$factor_name] += $effect;
			else {
				// Not every factor is a truth state: at the end of the tree
				// of factors are fact names.
				$effects[$factor] += $percentage_per_factor;
			}
		}

		return $effects;
	}
}

interface Reason
{
	public function __toString();
}

class KnowledgeItem
{
	public $value;

	public $reason;

	public function __construct($value, Reason $reason)
	{
		$this->value = $value;

		$this->reason = $reason;
	}
}

class AnsweredQuestion implements Reason
{
	public $question;

	public $answer;

	public function __construct(Question $question, Option $answer)
	{
		$this->question = $question;

		$this->answer = $answer;
	}

	public function __toString()
	{
		return sprintf('User answered "%s" when asked "%s"', $this->answer->description, $this->question->description);
	}
}

class InferredRule implements Reason
{
	public $rule;

	public $truthValue;

	public function __construct(Rule $rule, Yes $truthValue)
	{
		$this->rule = $rule;

		$this->truthValue = $truthValue;
	}

	public function __toString()
	{
		return sprintf('Rule "%s" evaluated to true because %s', $this->rule, $this->truthValue);
	}
}

class PredefinedConstant implements Reason
{
	public function __construct($explanation)
	{
		$this->explanation = $explanation;
	}

	public function __toString()
	{
		return $this->explanation;
	}
}

/**
 * KnowledgeState represents the knowledge base at a certain moment: used rules
 * are removed, facts are added, etc.
 */
class KnowledgeState
{
	public $algorithm;

	public $title;

	public $description;

	public $facts;

	public $rules;

	public $questions;

	public $goals;

	public $solved;

	public $goalStack;

	public function __construct()
	{
		$this->algorithm = 'backward-chaining';

		$this->facts = array(
			'undefined' => new KnowledgeItem(STATE_UNDEFINED,
				new PredefinedConstant('Undefined is defined as undefined'))
		);

		$this->rules = new Set();

		$this->questions = new Set();

		$this->goals = new Set();

		$this->solved = new Set();

		$this->goalStack = new Stack();
	}

	public function applyAnswer(Question $question, Option $answer)
	{
		foreach ($answer->consequences as $name => $value)
			$this->facts[$name] = new KnowledgeItem($value, new AnsweredQuestion($question, $answer));
	}

	public function applyRule(Rule $rule, Yes $evaluation)
	{
		foreach ($rule->consequences as $name => $value)
			$this->facts[$name] = new KnowledgeItem($value, new InferredRule($rule, $evaluation));
	}

	public function markUndefined($fact_name)
	{
		$this->facts[$fact_name] = new KnowledgeItem(STATE_UNDEFINED,
			new PredefinedConstant("There was no rule or question left to find a value for $fact_name"));
	}

	public function initializeGoalStack()
	{
		foreach ($this->goals as $goal)
		{
			$this->goalStack->push($goal->name);

			// Also push any answer values that are variables as goals to be solved.
			foreach ($goal->answers as $answer)
				if (KnowledgeState::is_variable($answer->value))
					$this->goalStack->push(KnowledgeState::variable_name($answer->value));
		}
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
		if (self::is_variable($fact_name))
			throw new RuntimeException('Called KnowledgeState::value with variable');

		if (!isset($this->facts[$fact_name]))
			return null;

		assert($this->facts[$fact_name] instanceof KnowledgeItem);

		return $this->resolve($this->facts[$fact_name]->value);
	}

	public function reason($fact_name)
	{
		return $this->facts[$fact_name]->reason;
	}

	public function resolve($fact_name)
	{
		$stack = array();

		while (self::is_variable($fact_name))
		{
			if (in_array($fact_name, $stack))
				throw new RuntimeException("Infinite recursion when trying to retrieve fact '$fact_name' after I retrieved " . implode(', ', $stack) . ".");

			$stack[] = $fact_name;

			if (isset($this->facts[self::variable_name($fact_name)]))
				$fact_name = $this->facts[self::variable_name($fact_name)]->value;
			else
				return new Maybe(null, [KnowledgeState::variable_name($fact_name)]);
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

	static public function is_default_fact($fact_name)
	{
		$empty_state = new self();
		return isset($empty_state->facts[$fact_name]);
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
	public function backwardChain(KnowledgeState $state)
	{
		// herhaal zo lang er goals op de goal stack zitten
		while (!$state->goalStack->isEmpty())
		{
			$this->log('Trying to solve %s', [$state->goalStack->top()]);

			// probeer het eerste goal op te lossen
			$result = $this->solve($state, $state->goalStack->top());

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
				$causes = $result->causes();

				$this->log('But I cannot, because the facts %s are not known yet', [implode(', ', $causes)]);

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
					
					// Het kan niet zijn dat het al eens is opgelost. Dan zou hij
					// in facts moeten zitten.
					assert(!$state->solved->contains($main_cause));

					// zet het te bewijzen fact bovenaan op de todo-lijst.
					$state->goalStack->push($main_cause);

					$this->log('I added %s to the goal stack. The stack is now %s', [$main_cause, $state->goalStack]);

					// .. en spring terug naar volgende goal op goal-stack!
					continue 2; 
				}

				// Er zijn geen redenen waarom het goal niet afgeleid kon worden? Ojee!
				if (count($causes) == 0)
				{
					// Haal het onbewezen fact van de todo-lijst
					$unsatisfied_goal = $state->goalStack->pop();

					$this->log('I mark %s as a STATE_UNDEFINED because I do not know its value ' .
						'but there are also no rules or questions which I can use to infer it.', [$unsatisfied_goal], LOG_LEVEL_WARNING);
					
					// en markeer hem dan maar als niet waar (closed-world assumption?)
					$state->markUndefined($unsatisfied_goal);

					$state->solved->push($unsatisfied_goal);
				}
			}

			// Yes, het is gelukt om een waarde te vinden voor dit goal.
			// Mooi, dan kan dat van de te bewijzen stack af.
			else
			{
				$this->log('Inferred %s to be %s and removed it from the goal stack.', [$state->goalStack->top(), $result]);
				// aanname: als het goal kon worden afgeleid, dan is het nu deel van
				// de afgeleide kennis.
				assert(!($state->resolve($state->goalStack->top()) instanceof Maybe));

				// op naar het volgende goal.
				$state->solved->push($state->goalStack->pop());

				$this->log('The goal stack is now %s', [$state->goalStack]);
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
	public function solve(KnowledgeState $state, $goal_name)
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
		$relevant_rules = new CallbackFilterIterator($state->rules->getIterator(),
			function($rule) use ($goal) { return $rule->infers($goal); });

		// Also keep a list of rules that were undecided, as we can use these
		// later on to decide which goal to solve first
		$maybes = [];
		
		foreach ($relevant_rules as $rule)
		{
			$rule_result = $rule->evaluate($state);

			$this->log("Rule '%s' results in %s", [$rule, $rule_result],
				$rule_result instanceof Maybe ? LOG_LEVEL_VERBOSE : LOG_LEVEL_INFO);

			// If it was decided as true, add the antecedent to the state
			if ($rule_result instanceof Yes)
			{
				$this->log("Adding %s to the facts dictionary", [dict_to_string($rule->consequences)]);

				// Update the knowledge state
				$state->applyRule($rule, $rule_result);

				// Remove the rule from this knowlege state so that we don't try
				// to evaluate it again.
				// TODO: Is this safe? We are still iterating over $state->rules here
				$state->rules->remove($rule);

				// no need to look to further rules, this one was true, right?
				return $state->value($goal);
			}

			// If this rule is decided, just remove it. Once it is decided it
			// won't magically turn to Yes after new knowledge comes in.
			else if ($rule_result instanceof No)
				$state->rules->remove($rule);

			else
				$maybes[] = $rule_result;
		}

		// Is there a question that might lead us to solving this goal?
		$relevant_questions = new CallbackFilterIterator($state->questions->getIterator(),
			function($question) use ($goal) { return $question->infers($goal); });

		$this->log("Found %d rules and %s questions", [iterator_count($relevant_rules),
			iterator_count($relevant_questions)], LOG_LEVEL_VERBOSE);

		// If this problem can be solved by a rule, use it!
		if (count($maybes) > 0)
			return new Maybe(null, $maybes);

		// If not, but when we do have a question to solve it, use that instead.
		if (iterator_count($relevant_questions) > 0)
		{
			$question = iterator_first($relevant_questions);

			// deze vraag is alleen over te slaan als er nog regels open staan om dit feit
			// af te leiden of als er alternatieve vragen naast deze (of eerder gestelde,
			// vandaar $n++) zijn.
			$skippable = iterator_count($relevant_questions) - 1;

			return new AskedQuestion($question, $skippable);
		}

		// We have no idea how to solve this. No longer our problem!
		// (The caller should set $goal to undefined or something.)
		return new Maybe();
	}

	public function forwardChain(KnowledgeState $state)
	{
		while (!$state->rules->isEmpty())
		{
			foreach ($state->rules as $rule)
			{
				$rule_result = $rule->evaluate($state);

				$this->log("Rule '%s' results in %s", [$rule, $rule_result],
					$rule_result instanceof Maybe ? LOG_LEVEL_VERBOSE : LOG_LEVEL_INFO);

				// If the rule was true, add the consequences, the inferred knowledge
				// to the knowledge state and continue applying rules on the new knowledge.
				if ($rule_result instanceof Yes)
				{
					$state->rules->remove($rule);

					$this->log("Adding %s to the facts dictionary", [dict_to_string($rule->consequences)]);

					$state->applyRule($rule, $rule_result);
					continue 2;
				}

				// If the rule could not be decided due to missing facts, and that
				// fact can be asked through a question, ask that question!
				if ($rule_result instanceof Maybe)
				{
					foreach ($state->questions as $question)
						foreach ($rule_result->causes() as $factor)
							if ($question->infers($factor))
								return new AskedQuestion($question, false);
				}
			}

			// None of the rules changed the state: stop trying.
			break;
		}
	}

	public function step(KnowledgeState $state)
	{
		switch ($state->algorithm)
		{
			case 'backward-chaining':
				return $this->backwardChain($state);

			case 'forward-chaining':
				return $this->forwardChain($state);

			default:
				throw new RuntimeException("Unknown inference algorithm. Please choose 'forward-chaining' or 'backward-chaining'.");
		}
	}

	protected function log($format, array $arguments = [], $level = LOG_LEVEL_INFO)
	{
		if (!$this->log)
			return;

		$this->log->write($format, $arguments, $level);
	}
}
