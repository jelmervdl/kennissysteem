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
		return $this->condition->evaluate($state)->inverse();
	}
}

/**
 * <fact [value="true|false"]>fact_name</fact>
 */
class FactCondition implements Condition
{
	private $fact_name;

	public function __construct($fact_name)
	{
		$this->fact_name = $fact_name;
	}

	public function evaluate(KnowledgeState $state)
	{
		// vraag de knowledge base om de status van dit feitje.
		return $state->infer($this->fact_name);
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

	static public function because($factors)
	{
		$called_class = get_called_class();
		return new $called_class((array) $factors);
	}
}

class Yes extends TruthState
{
	public function inverse()
	{
		return new No($this->factors);
	}
}

class No extends TruthState
{
	public function inverse()
	{
		return new Yes($this->factors);
	}
}

class Maybe extends TruthState 
{
	public function inverse()
	{
		return new Maybe($this->factors);
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
	 * Leidt de truth-value van een fact af indien mogelijk.
	 * (de implementatie nu stelt ook vragen indien hij het 
	 * niet alleen met regels kan afleiden.)
	 *
	 * @param string $fact naam van het feitje
	 * @return bool|null waarheidswaarde van het feitje.
	 */
	public function infer($fact)
	{
		debug("Inferring $fact");

		// als het stapje al eens is afgeleid, dan kunnen we het antwoord
		// zo uit de kb halen. Geen opnieuw afleiden nodig.
		if (isset($this->facts[$fact]))
			return $this->facts[$fact];

		$maybes = array();
		$nos = array();

		// fact is nog niet bekend, probeer regels:
		foreach ($this->rules as $rule)
		{
			if (!$rule->infers($fact))
				continue;
			
			$result = $rule->condition->evaluate($this);

			if ($result instanceof Yes)
			{

				debug("$fact is waar want {$rule->description}");
				
				$this->apply($rule->consequences, $result);

				// nu nemen we aan dat de regels consequenties inderdaad
				// iets zegt over het feit dat we zochten.
				assert(isset($this->facts[$fact]));

				return $this->facts[$fact];
			}
			elseif ($result instanceof Maybe)
			{
				// Deze regel heeft potentie, maar het is nog niet helemaal zeker
				// of hij ook van toepassing is.
				$maybes[] = $result;
			}
			else 
			{
				// Deze regel levert geen nieuwe facts op. Laat maar schieten.
				assert($result instanceof No);
				$nos[] = $result;
			}
		}

		if ($maybes)
			return Maybe::because($maybes);
		
		// Geen regels die ook maar de potentie hebben om dit fact waar
		// te maken: Definitief een No.

		elseif ($nos)
			return No::because($nos);
		
		else
			return No::because("no rules to infer {$fact}");
			

		/*
		// Geen van de regels gaf een duidelijk TRUE terug, dan maar proberen te vragen?
		foreach ($this->questions as $question)
		{
			if (!$question->infers($fact))
				continue;
			
			// stel de vraag, en we krijgen een keuze terug.
			$option = cli_ask($question);

			// stop de gevolgen van de gekozen optie in de knowledge base
			$this->apply($option->consequences);

			// aanname: een van de consequenties zegt tenminste iets over het
			// fact waar we naar op zoek waren.
			assert(isset($this->facts[$fact]));

			return $this->facts[$fact];
		}
		*/
		debug("Could not infer {$fact}");

		// als we het echt niet weten, return NULL.
		return new Maybe;
	}

	/**
	 * Pas $consequences toe op de $facts die we al hebben.
	 *
	 * @param array $consequences key-value paren met facts en hun truth-value.
	 */
	public function apply(array $consequences, TruthState $factor)
	{
		foreach ($consequences as $fact => $value)
		{
			// assumptie: consequenties zijn alleen facts, geen twijfels.
			assert($value instanceof Yes or $value instanceof No);

			// assumptie: regels spreken elkaar niet tegen
			assert(
				!isset($this->facts[$fact])
				or $this->facts[$fact] instanceof $value);

			$value = clone $value;
			$value->factors[] = $factor;

			$this->facts[$fact] = $value;
		}
	}
}

// print geen debug gedoe
function debug($msg) {}

/**
 * Stelt een vraag op de terminal, en blijf net zo lang wachten totdat
 * we een zinnig antwoord krijgen.
 * 
 * @return Option
 */
function cli_ask(Question $question)
{
	echo $question->description . "\n";

	for ($i = 0; $i < count($question->options); ++$i)
		printf("%2d) %s\n", $i + 1, $question->options[$i]->description);
	
	do {
		$response = fgetc(STDIN);

		$choice = @intval(trim($response));

		if ($choice > 0 && $choice <= count($question->options))
			return $question->options[$choice - 1];

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