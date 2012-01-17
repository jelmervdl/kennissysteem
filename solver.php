<?php

/**
 * Een rule waarmee een fact gevonden kan worden.
 *
 * <rule infers="">
 *     [<description>]
 *     <when|when_all|when_any/>
 *     <then/>
 * </rule>
 */
class Rule
{
	public $inferred_facts = array();

	public $description;

	public $condition;

	public $consequences = array();

	public $priority = 0;

	public $line_number;

	public function infers($fact)
	{
		return in_array($fact, $this->inferred_facts);
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
 * <question infers="">
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

	public function infers($fact)
	{
		return in_array($fact, $this->inferred_facts);
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
 * <when>
 *     <fact/>
 * </when>
 *
 * en
 *
 * <when_all>
 *     <when/>
 * </when_all>
 */
class WhenAllCondition implements Condition 
{
	private $conditions = array();

	public function addCondition(Condition $condition)
	{
		$this->conditions[] = $condition;
	}

	public function evaluate(KnowledgeState $state)
	{
		// assumptie: er moet ten minste één conditie zijn
		assert(count($this->conditions) > 0);

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

	public function asArray()
	{
		return array($this, array_map_method('asArray', $this->conditions));
	}
}

/**
 * <when_any>
 *     <when/>
 * </when_any>
 */
class WhenAnyCondition implements Condition
{
	private $conditions = array();

	public function addCondition(Condition $condition)
	{
		$this->conditions[] = $condition;
	}

	public function evaluate(KnowledgeState $state)
	{
		// assumptie: er moet ten minste één conditie zijn
		assert(count($this->conditions) > 0);

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

	public function asArray()
	{
		return array($this, array_map_method('asArray', $this->conditions));
	}
}

/**
 * <not>
 *     <when/>
 * </not>
 */
class NegationCondition implements Condition
{
	private $condtion;

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
		return array($this, array_map_method('asArray', $this->conditions));
	}
}

/**
 * <fact [value="true|false"]>fact_name</fact>
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

	public $answers = array();

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
		$causes = $this->divideAmong(1.0, $this->factors)->data();

		// grootst verantwoordelijk ontbrekend fact op top.
		asort($causes);

		return array_reverse($causes);
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

/**
 * Een knowledge base op een bepaald moment. De huidige implementatie
 * werkt maar op basis van één state, maar uiteindelijk moet ieder
 * antwoord op een vraag een nieuwe state opleveren.
 */
class KnowledgeState
{
	public $title;

	public $description;

	public $facts = array();

	public $rules = array();

	public $questions = array();

	public $goals = array();

	public $solved = array();

	public $goalStack;

	public function __construct()
	{
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
	 * @param Goal[] $goals lijst met op te lossen goals
	 * @return KnowledgeState eind-state
	 */
	public function solveAll(KnowledgeState $state)
	{
		// herhaal zo lang er goals op de goal stack zitten
		while (!$state->goalStack->isEmpty())
		{
			// probeer het eerste goal op te lossen
			$result = $this->solve($state, $state->goalStack->top());

			if ($result instanceof AskedQuestion)
			{
				return $result;
			}
			// meh, niet gelukt.
			elseif ($result instanceof Maybe)
			{
				// waarom niet?
				$causes = $result->causes();

				// echo '<pre>', print_r($causes, true), '</pre>';

				// er zijn facts die nog niet zijn afgeleid
				while (count($causes) > 0)
				{
					// neem het meest invloedrijke fact, leidt dat af
					$main_cause = key($causes);
					array_shift($causes);

					// meest invloedrijke fact staat al op todo-lijst?
					// sla het over.
					// TODO: misschien be ter om juist naar de top te halen?
					// en dan dat opnieuw proberen te bewijzen?
					if (iterator_contains($state->goalStack, $main_cause))
						continue;
					
					// Het kan niet zijn dat het al eens is opgelost. Dan zou hij
					// in facts moeten zitten.
					assert(!in_array($main_cause, $state->solved));

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
					
					$solved[] = $unsatisfied_goal;
				}
			}
			// Yes, het is gelukt om een Yes of No antwoord te vinden voor dit goal.
			// Mooi, dan kan dat van de te bewijzen stack af.
			else
			{
				// aanname: als het goal kon worden afgeleid, dan is het nu deel van
				// de afgeleide kennis.
				assert(isset($state->facts[$state->goalStack->top()]));

				// op naar het volgende goal.
				$state->solved[] = $state->goalStack->pop();
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
	 * @return array(KnowledgeState, TruthState)
	 */
	public function solve(KnowledgeState $state, $goal)
	{
		if (verbose())
			printf("Af te leiden: %s\n", $goal);
		
		// Kijk of het feit al afgeleid is en in de $facts lijst staat
		if (isset($state->facts[$goal]))
			return $state->facts[$goal];
		
		// Is er misschien een regel die we kunnen toepassen
		$relevant_rules = array_filter($state->rules,
			function($rule) use ($goal) { return $rule->infers($goal); });
		
		// Sowieso even kijken of ze daadwerkelijk toegepast kunnen worden.
		$relevant_rules = array_map(function($rule) use ($state) {
			return new Pair($rule, $rule->condition->evaluate($state));
		}, $relevant_rules);

		// Is er misschien een directe vraag die we kunnen stellen?
		$relevant_questions = array_filter($state->questions,
			function($question) use ($goal) { return $question->infers($goal); });

		if (verbose())
			printf("%d regels en %d vragen gevonden\n",
				count($relevant_rules), count($relevant_questions));

		// Sla ook alle resultaten op van de Maybe rules. Hier kunnen we later
		// misschien uit afleiden welk goal we vervolgens moeten afleiden om ze
		// te laten beslissen.
		$maybe_rules = array_filter($relevant_rules, function($pair) {
			return $pair->second instanceof Maybe;
		});

		// Controle: er moeten niet meerdere regels tegelijk waar zijn, dat zou raar zijn.
		// naja, tenzij ze dezelfde 
		if (verbose())
		{
			$applicapable_rules = array_filter($relevant_rules, function($pair) {
				return $pair->second instanceof Yes;
			});

			if (count($applicapable_rules) > 1)
				printf("<strong>Warning:</strong> Er zijn %d regels die iets zeggen over %s: %s",
					count($applicapable_rules), $goal,
					implode("\n", array_map(curry('pick', 'second'), $applicapable_rules)));
		}

		// Probeer alle mogelijk (relevante) regels, en zie of er eentje
		// nieuwe kennis afleidt.
		$n = 0;
		foreach ($relevant_rules as $pair)
		{
			list($rule, $rule_result) = $pair;

			if (verbose())
				printf("Regel %d (%s) levert %s op.\n",
					$n++, $rule->description, $rule_result);

			// als de regel kon worden toegepast, haal hem dan maar uit de
			// set van regels. Meerdere malen toepassen is niet logisch.
			if ($rule_result instanceof Yes or $rule_result instanceof No)
				$state->rules = array_filter($state->rules, curry('unequals', $rule));

			// regel heeft nieuwe kennis opgeleverd, update de $state, en we hebben
			// onze solve-stap voltooid.
			if ($rule_result instanceof Yes)
			{
				// als het antwoord ja was, bereken dan de gevolgen door in $state
				$state->apply($rule->consequences);
				
				return $rule_result;
			}
		}

		// Vraag gevonden!
		if (count($relevant_questions))
		{
			$question = current($relevant_questions);

			// deze vraag is alleen over te slaan als er nog regels open staan om dit feit
			// af te leiden of als er alternatieve vragen naast deze (of eerder gestelde,
			// vandaar $n++) zijn.
			$skippable = (count($relevant_questions) - 1) + count($maybe_rules);

			// haal de vraag hoe dan ook uit de mogelijk te stellen vragen. Het heeft geen zin
			// om hem twee keer te stellen.
			$state->questions = array_filter($state->questions, curry('unequals', $question));

			return new AskedQuestion($question, $skippable);
		}

		if (verbose())
			print_r(Maybe::because(array_map(curry('pick', 'second'), $maybe_rules))->causes());

		// $relevant_rules is leeg of leverde alleen maar Maybes op.

		// Geen enkele regel of vraag leverde direct een antwoord op $fact
		// Dus geven we de nieuwe state (zonder de al gestelde vragen) terug
		// en Maybe met alle redenen waarom de niet-volledig-afgeleide regels
		// niet konden worden afgeleid. Diegene die solve aanroept kan dan 
		return Maybe::because(array_map(curry('pick', 'second'), $maybe_rules));
	}
}
