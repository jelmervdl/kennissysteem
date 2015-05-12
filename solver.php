<?php

/**
 * Een rule waarmee een fact gevonden kan worden.
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
 * Een vraag waarmee een antwoord op $inferred_facts kan worden gevonden.
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

	public function negate(KnowledgeDomain $domain);

	public function simplify();

	public function asArray();
}

/**
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
		// assumptie: er moet ten minste één conditie zijn
		assert('count($this->conditions) > 0');

		$values = array();
		foreach ($this->conditions as $condition)
			$values[] = $condition->evaluate($state);

		// Als er minstens één Nee bij zit, dan iig niet.
		$nos = array_filter_type('No', $values);
		if (count($nos) > 0)
			return No::because($nos);

		// Als er een maybe in zit, dan nog steeds onzeker.
		$maybes = array_filter_type('Maybe', $values);
		if (count($maybes) > 0)
			return Maybe::because($maybes);

		return Yes::because($values);
	}

	public function negate(KnowledgeDomain $domain)
	{
		$negation = new WhenAnyCondition();

		foreach ($this->conditions as $condition)
			$negation->addCondition($condition->negate($domain));

		return $negation;
	}

	public function simplify()
	{
		return count($this->conditions) === 1
			? current($this->conditions)
			: $this;
	}

	public function asArray()
	{
		return array($this, array_map_method('asArray', $this->conditions));
	}
}

/**
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
		// assumptie: er moet ten minste één conditie zijn
		assert('count($this->conditions) > 0');

		$values = array();
		foreach ($this->conditions as $condition)
			$values[] = $condition->evaluate($state);
		
		// Is er een ja, dan is dit zeker goed.
		$yesses = array_filter_type('Yes', $values);
		if ($yesses)
			return Yes::because($yesses);
		
		// Is er een misschien, dan zou dit ook goed kunnen zijn
		$maybes = array_filter_type('Maybe', $values);
		if ($maybes)
			return Maybe::because($maybes);

		// Geen ja's, geen misschien's, dus alle condities gaven No terug.
		return No::because($values);
	}

	public function negate(KnowledgeDomain $domain)
	{
		$negation = new WhenAllCondition();

		foreach ($this->conditions as $condition)
			$negation->addCondition($condition->negate($domain));

		return $negation;
	}

	public function simplify()
	{
		return count($this->conditions) === 1
			? current($this->conditions)
			: $this;
	}

	public function asArray()
	{
		return array($this, array_map_method('asArray', $this->conditions));
	}
}

/**
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

	public function negate(KnowledgeDomain $domain)
	{
		return $this->condition;
	}

	public function simplify()
	{
		return $this->condition instanceof NegationCondition
			? $this->condition->condition
			: $this;
	}

	public function asArray()
	{
		return array($this, $this->condition->asArray());
	}
}

/**
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
		if (isset($state->facts[$this->name]))
			return $state->facts[$this->name] == $this->value
				? Yes::because($this->name)
				: No::because($this->name);
		
		else
			return Maybe::because($this->name);
	}

	public function negate(KnowledgeDomain $domain)
	{
		// NOTE: The logic of this negation is UNSOUND!
		$negation = new WhenAnyCondition();

		foreach ($domain->values[$this->name] as $possible_value)
			if ($possible_value != $this->value)
				$negation->addCondition(new FactCondition($this->name, $possible_value));

		return $negation;
	}

	public function simplify()
	{
		return $this;
	}

	public function asArray()
	{
		return array($this);
	}
}

/**
 * Voor het gemak kan je ook goals in je knowledge base voor programmeren.
 * Als je dan main.php zonder te bewijzen goal aanroept gaat hij al deze
 * goals proberen af te leiden.
 *
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

	public function answer(KnowledgeState $state)
	{
		$value = isset($state->facts[$this->name])
			? $state->facts[$this->name]
			: null;

		foreach ($this->answers as $answer)
			if ($answer->value == $value || $answer->value === null)
				return $answer;
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

abstract class TruthState
{
	public $factors;

	public function __construct(array $factors = array())
	{
		$this->factors = $factors;
	}

	public function __toString()
	{
		return sprintf("[%s because %s]",
			get_class($this),
			implode(' and ', array_map('strval', $this->factors)));
	}

	abstract public function negate();

	static public function because($factors)
	{
		$called_class = get_called_class();
		return new $called_class((array) $factors);
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
		// Hier wordt de volgorde van de vragen effectief bepaald!
		// We kijken naar alle factoren die ervoor zorgden dat de vraag niet
		// beantwoord kon worden, welke het meest invloedrijk is, en sorteren
		// daarop om te zien waar we verder mee moeten.
		// (Deze implementatie is zeker voor verbetering vatbaar.)
		$causes = $this->divideAmong(1.0, $this->factors)->data();

		// grootst verantwoordelijk ontbrekend fact op top.
		asort($causes);

		$causes = array_reverse($causes);

		return array_keys($causes);
	}

	private function divideAmong($percentage, array $factors)
	{
		$effects = new Map(0.0);

		// als er geen factors zijn, dan heeft het ook geen zin
		// de verantwoordelijkheid per uit te rekenen.
		if (count($factors) == 0)
			return $effects;
		
		// iedere factor op hetzelfde niveau heeft evenveel invloed.
		$percentage_per_factor = $percentage / count($factors);

		foreach ($factors as $factor)
		{
			// recursief de hoeveelheid invloed doorverdelen en optellen bij het totaal per factor.
			if ($factor instanceof TruthState)
				foreach ($this->divideAmong($percentage_per_factor, $factor->factors) as $factor_name => $effect)
					$effects[$factor_name] += $effect;
			else
				$effects[$factor] += $percentage_per_factor;
		}

		return $effects;
	}
}

class KnowledgeDomain
{
	public $values;

	public function __construct()
	{
		$this->values = new Map(function() {
			return new Set();
		});
	}

	static public function deduceFromState(KnowledgeState $state)
	{
		$domain = new self();

		// Obtain all the FactConditions from the rules that test
		// or conclude some value.
		foreach ($state->rules as $rule)
		{
			$fact_conditions = array_filter_type('FactCondition',
				array_flatten($rule->condition->asArray()));

			foreach ($fact_conditions as $condition)
				$domain->values[$condition->name]->push($condition->value);

			foreach ($rule->consequences as $fact_name => $value)
				$domain->values[$fact_name]->push($value);
		}

		// Obtain all the possible answers from the questions
		foreach ($state->questions as $question)
			foreach ($question->options as $option)
				foreach ($option->consequences as $fact_name => $value)
					$domain->values[$fact_name]->push($value);

		return $domain;
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
		$this->facts = array();

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
}

define('STATE_UNDEFINED', 'undefined');

/**
 * Solver is een forward & backward chaining implementatie die op basis van
 * een knowledge base (een berg regels, mogelijke vragen en gaandeweg feiten)
 * blijft zoeken, regels toepassen en vragen kiezen totdat alle goals opgelost
 * zijn. Gebruik Solver::solveAll(state) tot deze geen vragen meer teruggeeft.
 */
class Solver
{

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
			// probeer het eerste goal op te lossen
			$result = $this->solve($state, $state->goalStack->top());

			// Oh, dat resulteerde in een vraag. Stel hem (of geef hem terug om 
			// de interface hem te laten stellen eigenlijk.)
			if ($result instanceof AskedQuestion)
			{
				return $result;
			}

			// Goal is niet opgelost, het antwoord is nog niet duidelijk.
			elseif ($result instanceof Maybe)
			{
				// waarom niet? $causes bevat een lijst van facts die niet
				// bekend zijn, dus die willen we proberen op te lossen.
				$causes = $result->causes();

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
					assert('!$state->solved->contains($main_cause)');

					// zet het te bewijzen fact bovenaan op de todo-lijst.
					$state->goalStack->push($main_cause);

					// .. en spring terug naar volgende goal op goal-stack!
					continue 2; 
				}

				// Er zijn geen redenen waarom het goal niet afgeleid kon worden? Ojee!
				if (count($causes) == 0)
				{
					if (verbose())
						echo "Could not solve " . $state->goalStack->top() . " because "
						. "there are no missing facts? Maybe there are no rules or questions "
						. "to infer " . $state->goalStack->top() . ". Assuming the fact is "
						. "false.";
					
					// Haal het onbewezen fact van de todo-lijst
					$unsatisfied_goal = $state->goalStack->pop();

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
				// aanname: als het goal kon worden afgeleid, dan is het nu deel van
				// de afgeleide kennis.
				assert('isset($state->facts[$state->goalStack->top()])');

				// op naar het volgende goal.
				$state->solved->push($state->goalStack->pop());
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
		if (verbose())
			printf("Af te leiden: %s\n", $goal);
		
		// Forward chain until there is nothing left to derive.
		$this->forwardChain($state);

		// Kijk of het feit al afgeleid is en in de $facts lijst staat
		if (isset($state->facts[$goal]))
			return $state->facts[$goal];
		
		// Is er misschien een regel die we kunnen toepassen
		$relevant_rules = new CallbackFilterIterator($state->rules->getIterator(),
			function($rule) use ($goal) { return $rule->infers($goal); });
		
		// Is er misschien een directe vraag die we kunnen stellen?
		$relevant_questions = new CallbackFilterIterator($state->questions->getIterator(),
			function($question) use ($goal) { return $question->infers($goal); });

		if (verbose())
			printf("%d regels en %d vragen gevonden\n",
				iterator_count($relevant_rules), iterator_count($relevant_questions));

		foreach ($relevant_rules as $rule)
			assert('$rule->condition->evaluate($state) instanceof Maybe');

		// Vraag gevonden!
		foreach ($relevant_questions as $question)
		{
			// deze vraag is alleen over te slaan als er nog regels open staan om dit feit
			// af te leiden of als er alternatieve vragen naast deze (of eerder gestelde,
			// vandaar $n++) zijn.
			$skippable = (iterator_count($relevant_questions) - 1) + iterator_count($relevant_rules);

			// haal de vraag hoe dan ook uit de mogelijk te stellen vragen. Het heeft geen zin
			// om hem twee keer te stellen.
			$state->questions->remove($question);

			return new AskedQuestion($question, $skippable);
		}

		// $relevant_rules is leeg of leverde alleen maar Maybes op.

		// Geen enkele regel of vraag leverde direct een antwoord op $fact
		// Dus geven we de nieuwe state (zonder de al gestelde vragen) terug
		// en Maybe met alle redenen waarom de niet-volledig-afgeleide regels
		// niet konden worden afgeleid. Diegene die solve aanroept kan dan 
		return Maybe::because(array_map(function($rule) use ($state) {
				return $rule->condition->evaluate($state);
			}, iterator_to_array($relevant_rules)));
	}

	public function forwardChain(KnowledgeState $state)
	{
		while (!$state->rules->isEmpty())
		{
			foreach ($state->rules as $rule)
			{
				$rule_result = $rule->condition->evaluate($state);

				if (verbose())
					printf("Regel %d (%s) levert %s op.\n",
						isset($n) ? ++$n : $n = 1, $rule->description, $rule_result);

				// If a rule could be applied, remove it to prevent it from being
				// applied again.
				if ($rule_result instanceof Yes or $rule_result instanceof No)
					$state->rules->remove($rule);

				// If the rule was true, add the consequences, the inferred knowledge
				// to the knowledge state and continue applying rules on the new knowledge.
				if ($rule_result instanceof Yes)
				{
					$state->apply($rule->consequences);
					continue 2;
				}
			}

			// None of the rules changed the state: stop trying.
			break;
		}
	}
}
