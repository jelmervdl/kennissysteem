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

	public function infers($fact)
	{
		return in_array($fact, $this->inferred_facts);
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

	public function infers($fact)
	{
		return in_array($fact, $this->inferred_facts);
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
}

/**
 * <fact [value="true|false"]>fact_name</fact>
 */
class FactCondition implements Condition
{
	private $name;

	private $value;

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
}

/**
 * Voor het gemak kan je ook goals in je knowledge base voor programmeren.
 * Als je dan main.php zonder te bewijzen goal aanroept gaat hij al deze
 * goals proberen af te leiden.
 *
 * <goal>
 *     <description/>
 *     <proof/>
 * </goal>
 */
class Goal
{
	public $description;

	public $proof;
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
		arsort($causes);

		return $causes;
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
	public $facts = array();

	public $rules = array();

	public $questions = array();

	public $goals = array();

	/**
	 * Past $consequences toe op de huidige $state, en geeft dat als nieuwe state terug.
	 * Alle $consequences krijgen $reason als reden mee.
	 * 
	 * @return KnowledgeState
	 */
	public function apply(array $consequences)
	{
		$new_state = clone $this;
		
		$new_state->facts = array_merge($this->facts, $consequences);

		return $new_state;
	}
}

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
	public function solveAll(KnowledgeState $knowledge, array $goals)
	{
		$goal_stack = new SplStack;

		$solved_goals = array();

		foreach ($goals as $goal)
			$goal_stack->push($goal->proof);

		// herhaal zo lang er goals op de goal stack zitten
		while (count($goal_stack) > 0)
		{
			// probeer het eerste goal op te lossen
			list($knowledge, $result) = $this->solve($knowledge, $goal_stack->top());

			// meh, niet gelukt.
			if ($result instanceof Maybe)
			{
				// waarom niet?
				$causes = $result->causes();

				// er zijn facts die nog niet zijn afgeleid
				while (count($causes) > 0)
				{
					// neem het meest invloedrijke fact, leidt dat af
					$main_cause = key($causes);
					array_shift($causes);

					// meest invloedrijke fact staat al op todo-lijst?
					// sla het over.
					// TODO: misschien beter om juist naar de top te halen?
					// en dan dat opnieuw proberen te bewijzen?
					if (iterator_contains($goal_stack, $main_cause))
						continue;
					
					// Het kan niet zijn dat het al eens is opgelost. Dan zou hij
					// in facts moeten zitten.
					assert(!in_array($main_cause, $solved_goals));

					// zet het te bewijzen fact bovenaan op de todo-lijst.
					$goal_stack->push($main_cause);

					// .. en spring terug naar volgende goal op goal-stack!
					continue 2; 
				}

				// Er zijn geen redenen waarom het goal niet afgeleid kon worden? Ojee!
				if (count($causes) == 0)
				{
					if (verbose())
						echo "Could not solve " . $goal_stack->top() . " because there "
						. "are no missing facts? Maybe there are no rules or questions "
						. "to infer " . $goal_stack->top() . ". Assuming the fact is "
						. "false.";
					
					// Haal het onbewezen fact van de todo-lijst
					$unsatisfied_goal = $goal_stack->pop();

					// en markeer hem dan maar als niet waar (closed-world assumption?)
					$knowledge = $knowledge->apply(array($unsatisfied_goal => 'no'));
					
					$solved_goals[] = $unsatisfied_goal;
				}
			}
			// Yes, het is gelukt om een Yes of No antwoord te vinden voor dit goal.
			// Mooi, dan kan dat van de te bewijzen stack af.
			else
			{
				// aanname: als het goal kon worden afgeleid, dan is het nu deel van
				// de afgeleide kennis.
				assert(isset($knowledge->facts[$goal_stack->top()]));

				// op naar het volgende goal.
				$solved_goals[] = $goal_stack->pop();
			}
		}

		return $knowledge;
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
			return array($state, $state->facts[$goal]);
		
		// Is er misschien een regel die we kunnen toepassen
		$relevant_rules = array_filter($state->rules,
			function($rule) use ($goal) { return $rule->infers($goal); });
		
		// Is er misschien een directe vraag die we kunnen stellen?
		$relevant_questions = array_filter($state->questions,
			function($question) use ($goal) { return $question->infers($goal); });
		
		if (verbose())
			printf("%d regels en %d vragen gevonden\n",
				count($relevant_rules), count($relevant_questions));

		// Sla ook alle resultaten op van de Maybe rules. Hier kunnen we later
		// misschien uit afleiden welk goal we vervolgens moeten afleiden om ze
		// te laten beslissen.
		$maybes = array();

		// Probeer alle mogelijk (relevante) regels, en zie of er eentje
		// nieuwe kennis afleidt.
		$n = 0;
		foreach ($relevant_rules as $rule)
		{
			// evalueer of de regel waar is (of het when-gedeelte Yes oplevert)
			$rule_result = $rule->condition->evaluate($state);

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
				$state = $state->apply($rule->consequences);
				
				return array($state, $rule_result);
			}

			// De regel is nog niet Yes, maar zou het wel kunnen worden zodra we
			// meer feitjes weten. Onthoud even dat het een maybe was, maar probeer
			// ook eerst even de andere regels.
			elseif ($rule_result instanceof Maybe)
			{
				$maybes[] = $rule_result;
			}
		}

		// $relevant_rules is leeg of leverde alleen maar Maybes op.

		// Vraag gevonden!
		$n = 1;
		foreach ($relevant_questions as $question)
		{
			// deze vraag is alleen over te slaan als er nog regels open staan om dit feit
			// af te leiden of als er alternatieve vragen naast deze (of eerder gestelde,
			// vandaar $n++) zijn.
			$skippable = (count($relevant_questions) - $n++) + count($maybes);

			$answer = $this->ask($question, $skippable);
			
			// haal de vraag hoe dan ook uit de mogelijk te stellen vragen. Het heeft geen zin
			// om hem twee keer te stellen.
			$state->questions = array_filter($state->questions, curry('unequals', $question));

			// vraag geeft nuttig antwoord. Neem gevolgen mee, en probeer een antwoord te vinden
			if ($answer instanceof Option)
			{
				$state = $state->apply($answer->consequences,
					Yes::because("User answered '{$answer->description}' to '{$question->description}'"));

				// aanname: de vraag had beslissende gevolgen voor het $goal dat we proberen op te lossen.
				return array($state, new Yes);
			}
			
			// vraag was niet nuttig (overgeslagen), helaas.
		}

		// Geen enkele regel of vraag leverde direct een antwoord op $fact
		// Dus geven we de nieuwe state (zonder de al gestelde vragen) terug
		// en Maybe met alle redenen waarom de niet-volledig-afgeleide regels
		// niet konden worden afgeleid. Diegene die solve aanroept kan dan 
		return array($state, Maybe::because($maybes));
	}

	/**
	 * Vraag een Question. Eventueel met oversla-optie.
	 *
	 * @param Question $question de vraag
	 * @param bool $skipable of de vraag over te slaan is
	 * @return Option|Maybe
	 */
	public function ask(Question $question, $skippable)
	{
		$answer = cli_ask($question, $skippable);

		return $answer instanceof Option
			? $answer
			: Maybe::because("User skipped {$question->description}");
	}	
}

/**
 * Stelt een vraag op de terminal, en blijf net zo lang wachten totdat
 * we een zinnig antwoord krijgen.
 * 
 * @return Option
 */
function cli_ask(Question $question, $skippable = false)
{
	echo $question->description . "\n";

	for ($i = 0; $i < count($question->options); ++$i)
		printf("%2d) %s\n", $i + 1, $question->options[$i]->description);
	
	if ($skippable)
		printf("%2d) weet ik niet\n", ++$i);
	
	do {
		$response = fgetc(STDIN);

		$choice = @intval(trim($response));

		if ($choice > 0 && $choice <= count($question->options))
			return $question->options[$choice - 1];
		
		if ($skippable && $choice == $i)
			return null;

	} while (true);
}

/*
function test_solver()
{
	$state = new KnowledgeState();

	// regel: als het regent, zijn de straten nat.
	$r = new Rule;
	$r->description = 'als het regent, zijn de straten nat.';
	$r->inferred_facts[] = 'straat_is_nat';
	$r->consequences['straat_is_nat'] = true;
	$r->condition = new FactCondition('het_regent');
	$state->rules[] = $r;

	// regel: als de luchtdruk laag is, regent het
	$r = new Rule;
	$r->description = 'als de luchtdruk laag is, regent het';
	$r->inferred_facts[] = 'het_regent';
	$r->consequences['het_regent'] = true;
	$r->condition = new FactCondition('lage_luchtdruk');
	$state->rules[] = $r;

	// regel: als de luchtdruk niet laag is, dan regent het niet.
	$r = new Rule;
	$r->description = 'als de luchtdruk niet laag is, dan regent het niet';
	$r->inferred_facts[] = 'het_regent';
	$r->consequences['het_regent'] = false;
	$r->condition = new NegationCondition(new FactCondition('lage_luchtdruk'));
	$state->rules[] = $r;

	// regel: als de zon schijnt en het regent niet, zijn de straten niet nat
	$r = new Rule;
	$r->description = 'als de zon schijnt en het regent niet, zijn de straten niet nat';
	$r->inferred_facts[] = 'straat_is_nat';
	$r->consequences['straat_is_nat'] = false;
	$r->condition = new WhenAllCondition;
	$r->condition->addCondition(new FactCondition('de_zon_schijnt'));
	$r->condition->addCondition(new NegationCondition(new FactCondition('het_regent')));
	$state->rules[] = $r;

	$state->facts['lage_luchtdruk'] = true;
	$state->facts['de_zon_schijnt'] = true;

	return $state->infer('straat_is_nat');
}

test_solver();

*/