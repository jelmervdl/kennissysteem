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

	public function infers($fact)
	{
		return $this->inferred_facts->contains($fact);
	}

	public function __toString()
	{
		return sprintf('[Question: %s]', $this->description);
	}
}

class AskedQuestion extends Question
{
	public $skippable;

	public function __construct(Question $question, $skippable)
	{
		$this->description = $question->description;

		$this->options = $question->options;

		$this->skippable = $skippable;
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
 * All conditions need to be true
 * 
 * <and>
 *     Conditions, e.g. <fact/>
 * </and>
 */
class WhenAllCondition implements Condition 
{
	public $conditions;

	public function __construct()
	{
		$this->conditions = new Set();
	}

	public function addCondition(Condition $condition)
	{
		$this->conditions->push($condition);
	}

	public function evaluate(KnowledgeState $state)
	{
		// Assumption: There has to be at least one condition
		assert(count($this->conditions) > 0);

		$values = array();
		foreach ($this->conditions as $condition)
			$values[] = $condition->evaluate($state);

		// If at least one of the values is No, we no this condition
		// if false (Maybe's don't even matter in that case anymore.)
		$nos = array_filter_type('No', $values);
		if (count($nos) > 0)
			return No::because($nos);

		// If there are maybes left in the values, we know that not yet
		// all conditions are Yes, so, the verdict for now is also Maybe.
		$maybes = array_filter_type('Maybe', $values);
		if (count($maybes) > 0)
			return Maybe::because($maybes);

		// And otherwise, everything evaluated to Yes, so Yes!
		return Yes::because($values);
	}

	public function asArray()
	{
		return array($this, array_map_method('asArray', $this->conditions));
	}
}

/**
 * Just one of the conditions has to be true
 * 
 * <or>
 *     Conditions, e.g. <fact/>
 * </or>
 */
class WhenAnyCondition implements Condition
{
	public $conditions;

	public function __construct()
	{
		$this->conditions = new Set();
	}

	public function addCondition(Condition $condition)
	{
		$this->conditions->push($condition);
	}

	public function evaluate(KnowledgeState $state)
	{
		// Assumption: There has to be at least one condition
		assert(count($this->conditions) > 0);

		$values = array();
		foreach ($this->conditions as $condition)
			$values[] = $condition->evaluate($state);
		
		// If threre is at least one Yes, then this condition is met!
		$yesses = array_filter_type('Yes', $values);
		if ($yesses)
			return Yes::because($yesses);
		
		// If there are still maybe's, then maybe there is still chance
		// for a Yes. So return Maybe.
		$maybes = array_filter_type('Maybe', $values);
		if ($maybes)
			return Maybe::because($maybes);

		// No yes, no maybe, only no's. So no.
		return No::because($values);
	}

	public function asArray()
	{
		return array($this, array_map_method('asArray', $this->conditions));
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

	public function __construct($name, $value)
	{
		$this->name = trim($name);
		$this->value = trim($value);
	}

	public function evaluate(KnowledgeState $state)
	{
		$state_value = $state->value($this->name);

		if ($state_value instanceof Maybe)
			return $state_value;

		return $state_value == $this->value
			? Yes::because([$this->name])
			: No::because([$this->name]);
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
		$state_value = $state->value($this->name);
		
		foreach ($this->answers as $answer)
		{
			$answer_value = $answer->value;

			// If this is the default option, return it always.
			if ($answer_value === null)
				return $answer;

			// If the value is a variable, try to resolve it.
			if (KnowledgeState::is_variable($answer_value))
				$answer_value = $state->resolve($answer_value);

			if ($state_value == $answer_value)
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

/**
 * Een knowledge base op een bepaald moment. Via KnowledgeState::apply kunnen er
 * nieuwe feiten aan de state toegevoegd worden (en wordt het stieken een nieuwe
 * state).
 */
class KnowledgeState
{
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
		$this->facts = array(
			'undefined' => STATE_UNDEFINED
		);

		$this->rules = new Set();

		$this->questions = new Set();

		$this->goals = new Set();

		$this->solved = new Set();

		$this->goalStack = new Stack();
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

	public function value($fact_name)
	{
		$fact_name = $this->resolve($fact_name);

		if (!isset($this->facts[$fact_name]))
			return Maybe::because([$fact_name]);

		return $this->resolve($this->facts[$fact_name]);
	}

	public function resolve($value)
	{
		$stack = array();

		while (self::is_variable($value))
		{
			if (in_array($value, $stack))
				throw new RuntimeException("Infinite recursion when trying to retrieve fact '$value' after I retrieved " . implode(', ', $stack) . ".");

			$stack[] = $value;

			if (isset($this->facts[self::variable_name($value)]))
				$value = $this->facts[self::variable_name($value)];
			else
				return self::variable_name($value);
		}

		return $value;
	}

	public function substitute_variables($text, $formatter = null)
	{
		$callback = function($match) use ($formatter) {
			$value = $this->value($match[1]);

			if ($value instanceof Maybe)
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
 * zijn. Gebruik Solver::solveAll(state) tot deze geen vragen meer teruggeeft.
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
	public function solveAll(KnowledgeState $state)
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
					$state->apply(array($unsatisfied_goal => STATE_UNDEFINED));

					// compute the effects of this change by applying the other rules
					$this->forwardChain($state);
					
					$state->solved->push($unsatisfied_goal);
				}
			}

			// Yes, het is gelukt om een Yes of No antwoord te vinden voor dit goal.
			// Mooi, dan kan dat van de te bewijzen stack af.
			else
			{
				$this->log('Inferred %s to be %s and removed it from the goal stack.', [$state->goalStack->top(), $result]);
				// aanname: als het goal kon worden afgeleid, dan is het nu deel van
				// de afgeleide kennis.
				assert(isset($state->facts[$state->goalStack->top()]));

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
	public function solve(KnowledgeState $state, $goal)
	{
		// Forward chain until there is nothing left to derive.
		$this->forwardChain($state);

		// Test whether the fact is already in the knowledge base and if not, if it is solely
		// unknown because we don't know the current goal we try to prove. Because, it could
		// have a variable as value which still needs to be resolved, but that might be a
		// different goal!
		$current_value = $state->value($goal);

		if (!($current_value instanceof Maybe && $current_value->factors == [$goal]))
			return $current_value;
		
		// Is er misschien een regel die we kunnen toepassen
		$relevant_rules = new CallbackFilterIterator($state->rules->getIterator(),
			function($rule) use ($goal) { return $rule->infers($goal); });
		
		// Assume that all relevant rules result in maybe's. If not, something went
		// horribly wrong in $this->forwardChain()!
		foreach ($relevant_rules as $rule)
			assert($rule->condition->evaluate($state) instanceof Maybe);

		// Is er misschien een directe vraag die we kunnen stellen?
		$relevant_questions = new CallbackFilterIterator($state->questions->getIterator(),
			function($question) use ($goal) { return $question->infers($goal); });

		$this->log("Found %d rules and %s questions", [iterator_count($relevant_rules),
			iterator_count($relevant_questions)], LOG_LEVEL_VERBOSE);

		// If this problem can be solved by a rule, use it!
		if (iterator_count($relevant_rules) > 0)
			return Maybe::because(new CallbackMapIterator($relevant_rules, function($rule) use ($state) {
				return $rule->condition->evaluate($state);
			}));

		// If not, but when we do have a question to solve it, use that instead.
		if (iterator_count($relevant_questions) > 0)
		{
			$question = iterator_first($relevant_questions);

			// deze vraag is alleen over te slaan als er nog regels open staan om dit feit
			// af te leiden of als er alternatieve vragen naast deze (of eerder gestelde,
			// vandaar $n++) zijn.
			$skippable = iterator_count($relevant_questions) - 1;

			// haal de vraag hoe dan ook uit de mogelijk te stellen vragen. Het heeft geen zin
			// om hem twee keer te stellen.
			$state->questions->remove($question);

			return new AskedQuestion($question, $skippable);
		}

		// We have no idea how to solve this. No longer our problem!
		// (The caller should set $goal to undefined or something.)
		return Maybe::because();
	}

	public function forwardChain(KnowledgeState $state)
	{
		while (!$state->rules->isEmpty())
		{
			foreach ($state->rules as $rule)
			{
				$rule_result = $rule->condition->evaluate($state);

				$this->log("Rule '%s' results in %s", [$rule->description, $rule_result],
					$rule_result instanceof Yes or $rule_result instanceof No ? LOG_LEVEL_INFO : LOG_LEVEL_VERBOSE);

				// If a rule could be applied, remove it to prevent it from being
				// applied again.
				if ($rule_result instanceof Yes or $rule_result instanceof No)
					$state->rules->remove($rule);

				// If the rule was true, add the consequences, the inferred knowledge
				// to the knowledge state and continue applying rules on the new knowledge.
				if ($rule_result instanceof Yes)
				{
					$this->log("Adding %s to the facts dictionary", [dict_to_string($rule->consequences)]);

					$state->apply($rule->consequences);
					continue 2;
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
