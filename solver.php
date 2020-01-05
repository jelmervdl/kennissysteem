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

	public function infers($fact): bool
	{
		return $this->inferred_facts->contains($fact);
	}

	public function evaluate(KnowledgeState $state): TruthState
	{
		return $this->condition->evaluate($state);
	}

	public function __toString(): string
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

	public function addOption(Option $option): void
	{
		$this->options[] = $option;
	}

	public function infers($fact): bool
	{
		return $this->inferred_facts->contains($fact);
	}

	public function __toString(): string
	{
		return $this->description;
	}
}

class AskedQuestion
{
	public $question;

	public $skippable;

	public function __construct(Question $question, bool $skippable)
	{
		$this->question = $question;

		$this->skippable = $skippable;
	}

	public function __toString(): string
	{
		return sprintf('%s (%s)', $this->question->__toString(), $this->skippable ? 'skippable' : 'not skippable');
	}
}

/**
 * One of the multiple choice options to a question.
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

/**
 * Internal interface for all classes that behave like a condition.
 */
interface Condition
{
	public function evaluate(KnowledgeState $state): TruthState;

	public function asArray(): array;
}

/**
 * N of the conditions have to be true. WhenAll and WhenAny are both based on 
 * this class as WhenAll translates to N = len(conditions) and WhenAny to N=1.
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

	public function addCondition(Condition $condition): void
	{
		$this->conditions->push($condition);
	}

	public function evaluate(KnowledgeState $state): TruthState
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

	public function asArray(): array
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

	public function addCondition(Condition $condition): void
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

	public function evaluate(KnowledgeState $state): TruthState
	{
		return $this->condition->evaluate($state)->negate($this);
	}

	public function asArray(): array
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

	public function __construct(string $name, string $value, string $test = 'eq')
	{
		$this->name = trim($name);
		$this->value = trim($value);
		$this->test = $test;
	}

	public function evaluate(KnowledgeState $state): TruthState
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

		if ($this->compare($state_value, $test_value))
			return new Yes($this, [$state->reason($this->name)]);
		else
			return new No($this, [$state->reason($this->name)]);
	}

	protected function compare($lhs, $rhs): bool
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

	public function asArray(): array
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

	public function hasAnswers(): bool
	{
		return count($this->answers) > 0;
	}

	public function answer(KnowledgeState $state): ?Answer
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

	public function __toString(): string
	{
		return sprintf("[%s because: %s]",
			get_class($this),
			implode(', ', array_map('strval', $this->factors)));
	}

	abstract public function negate(object $reason): TruthState;
}

class Yes extends TruthState
{
	public function negate(object $reason): TruthState
	{
		return new No($reason, [$this]);
	}
}

class No extends TruthState
{
	public function negate(object $reason): TruthState
	{
		return new Yes($reason, [$this]);
	}
}

class Maybe extends TruthState 
{
	public function negate(object $reason): TruthState
	{
		return new Maybe($reason, [$this]);
	}

	public function unknownFacts(): array
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

	private function divideAmong(float $percentage, array $factors): Map
	{
		$effects = new class() extends Map {
			protected function makeDefaultValue($key) {
				return 0.0;
			}
		};

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

/**
 * To keep track of how we came to certain conclusions, we add reasons to each
 * derived KnowledgeItem (i.e. a derived fact). This helps with debugging and
 * with coming up with an explanation.
 *
 * The Reason interface is pretty simple: it just has to produce a string, a
 * human readable description of how it came to be. The HTMLFormatter may
 * provide more elaborate methods of displaying a reason, but through this
 * interface it can at least always provide a fallback reason.
 */
interface Reason
{
	public function __toString();
}

/**
 * A knowledge item is a derived value of a fact. It combines the derived value
 * and a hint (the reason) it came to this value.
 */
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

/**
 * Answering a question in a certain way can be a reason for a fact to become
 * a certain value. This class represent such reasons.
 */
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

/**
 * Inferring a rule in a certain way can be a reason for a fact to become a
 * certain value. This class represents these reasons. It remembers the rule
 * that was triggered, and the 'Yes' that came out of it as that TruthState
 * itself as well as that will have a reason for becoming yes. I.e. by going
 * through the reasons hidden inside the reason for the Yes you should be able
 * to derive exactly how that Yes came to be.
 */
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

/**
 * The solver expects certain values to be predefined (more specifically, the
 * fact 'undefined'). The reason for such knowledge items is then that they
 * are predefined. But because everything needs a reason, so do these predefined
 * facts.
 */
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

class FactMap extends Map
{
	protected function validate($key, $value): void
	{
		if (!is_string($key))
			throw new InvalidArgumentException('Stored facts can only be strings');

		if (KnowledgeState::is_variable($key))
			throw new InvalidArgumentException('Stored facts cannot have a variable as name');

		if (!($value instanceof KnowledgeItem))
			throw new InvalidArgumentException('Stored fact value has to be a KnowledgeItem');
	}
}


/**
 * KnowledgeState represents the knowledge base at a certain moment: asked
 * questions are removed, facts are added, current goal stack, etc.
 */
class KnowledgeState
{
	public $facts;

	public $questions;

	public $goalStack;

	public function __construct()
	{
		$this->facts = new FactMap();

		$this->facts['undefined'] = new KnowledgeItem(STATE_UNDEFINED,
				new PredefinedConstant('undefined is a built-in fact that is always undefined.'));

		$this->questions = new Set();

		$this->goalStack = new Stack();
	}

	static public function initializeForDomain(KnowledgeDomain $domain): self
	{
		$state = new static();

		$state->questions = clone $domain->questions;

		foreach ($domain->facts as $name => $value)
			$state->facts[$name] = new KnowledgeItem($value, new PredefinedConstant("The value for $name was predefined in the knowledge base"));

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

	public function removeQuestion(Question $question): void
	{
		$this->questions->remove($question);
	}

	public function applyAnswer(Question $question, Option $answer): void
	{
		foreach ($answer->consequences as $name => $value)
			$this->facts[$name] = new KnowledgeItem($value, new AnsweredQuestion($question, $answer));
	}

	public function applyRule(Rule $rule, Yes $evaluation): void
	{
		foreach ($rule->consequences as $name => $value)
			$this->facts[$name] = new KnowledgeItem($value, new InferredRule($rule, $evaluation));
	}

	public function markUndefined(string $fact_name): void
	{
		$this->facts[$fact_name] = new KnowledgeItem(STATE_UNDEFINED,
			new PredefinedConstant("There was no rule or question left to find a value for $fact_name"));
	}

	/**
	 * Returns the value of a fact, or null if not found. Do not call with
	 * variables as fact_name. If $fact_name is or could be a variable, first
	 * use KnowledgeState::resolve it.
	 */
	public function fact(string $fact_name): ?KnowledgeItem
	{
		if (static::is_variable($fact_name))
			throw new InvalidArgumentException('Called KnowledgeState::fact with variable');

		if (!isset($this->facts[$fact_name]))
			return null;

		return $this->facts[$fact_name];
	}

	public function value($fact_name)
	{
		if (($fact = $this->fact($fact_name)) === null)
			return null;

		return $this->resolve($fact->value);
	}

	public function reason(string $fact_name): ?Reason
	{
		// Todo: KnowledgeState::value returns the resolved fact, but this returns
		// the reason of only the resolved fact. Maybe we should create an explanation
		// for the resolution process and present that as the reason.
		if (($fact = $this->fact($fact_name)) === null)
			return null;

		// In case the fact is a variable and refers to some other fact, passing
		// along the $fact variable as it will be kept up-to-date on the resolved one.
		$fact_name = $this->resolve($fact->value, $fact);

		return $fact->reason;
	}

	public function resolve($fact_name, &$item = null)
	{
		$stack = array();

		while (static::is_variable($fact_name))
		{
			if (in_array($fact_name, $stack))
				throw new RuntimeException("Infinite recursion when trying to retrieve fact '$fact_name' after I retrieved " . implode(', ', $stack) . ".");

			$stack[] = $fact_name;

			if (isset($this->facts[self::variable_name($fact_name)])) {
				$item = $this->facts[self::variable_name($fact_name)];
				$fact_name = $this->facts[self::variable_name($fact_name)]->value;
			}
			else
				return new Maybe(null, [KnowledgeState::variable_name($fact_name)]);
		}

		return $fact_name;
	}

	/**
	 * Helper function to substitute variables in text (e.g. the description of
	 * a rule) with the values of facts.
	 */
	public function substitute_variables(string $text, $formatter = null): string
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

	static public function is_variable($fact_name): bool
	{
		return is_string($fact_name) && substr($fact_name, 0, 1) == '$';
	}

	static public function variable_name(string $fact_name): string
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
 * The Solver object is an implementation of forward and backward chaining. It
 * takes a KnowledgeState (rules, derived facts, possible questions) and
 * produces either a conclusion (ThruthState) or a question that can be asked
 * (AskedQuestion).
 *
 * Usage for backward chaining:
 *   Keep calling backwardChain until it no longer returns AskedQuestions. Then
 *   use the KnowledgeState to query the value of each fact you initially added
 *   to your goal stack (i.e. KnowledgeDomain::$goals)
 *
 * Usage for forward chaining:
 *   Same as backward chaining, but call forwardChain() instead.
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
	public function backwardChain(KnowledgeDomain $domain, KnowledgeState $state): ?AskedQuestion
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
					$state->markUndefined($unsatisfied_goal);

					$this->log('Mark %s as a STATE_UNDEFINED as there are no options to come to a value', [$unsatisfied_goal], LOG_LEVEL_WARNING);
				}
			}

			// Yes, het is gelukt om een waarde te vinden voor dit goal.
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

		return null;
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
	public function solve(KnowledgeDomain $domain, KnowledgeState $state, string $goal_name)
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

		// Is there a question (remaining) that might lead us to solving this goal?
		$relevant_questions = filter($state->questions,
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
				$state->applyRule($rule, $rule_result);

				// no need to look to further rules, this one was true, right?
				return $state->value($goal);
			}
			else if ($rule_result instanceof Maybe)
				$maybes[] = $rule_result;
		}

		// If trying to apply rules yielded unknowns, stop here. Note: If you
		// want to prioritize questions over trying to apply rules, move this
		// after the next if-statement about $relevant_questions.
		if (count($maybes) > 0)
			return new Maybe(null, $maybes);

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
		return new Maybe();
	}

	public function forwardChain(KnowledgeDomain $domain, KnowledgeState $state): ?AskedQuestion
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

					$state->applyRule($rule, $rule_result);
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

		return null; // Necessary because otherwise it returns 'none', whatever
		             // PHP determines that to be. Blame PHP.
	}

	public function step(KnowledgeState $state): ?AskedQuestion
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

	protected function log(string $format, array $arguments = [], $level = LOG_LEVEL_INFO): void
	{
		if ($this->log)
			$this->log->write($format, $arguments, $level);
	}
}
