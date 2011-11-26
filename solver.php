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

		foreach ($this->conditions as $condition)
		{
			$result = $condition->evaluate($state);
			
			if ($result === false)
				return false;
			
			if ($result === null)
				return null;
		}

		return true;
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

		$n_undetermined_conditions = 0;

		foreach ($this->conditions as $condition)
		{
			$result = $condition->evaluate($state);
			
			if ($result === true)
				return true;
			
			if ($result === null)
				$n_undetermined_conditions++;

			else
				// assumptie: als de conditie niet "waar" en ook niet
				// "onbekend" is dan moet hij wel "niet waar" zijn.
				assert($result === false);
		}

		return $n_undetermined_conditions > 0
			? null
			: false;
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
		$result = $this->condition->evaluate($state);

		return $result === null
			? null
			: !$result;
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
		
		// fact is nog niet bekend, probeer regels:
		foreach ($this->rules as $rule)
		{
			if (!$rule->infers($fact))
				continue;
			
			$result = $rule->condition->evaluate($this);

			if ($result === true)
			{

				debug("$fact is" . ($rule->consequences[$fact] ? ' ' : ' niet ') . "waar want {$rule->description}");
				
				$this->apply($rule->consequences);

				// nu nemen we aan dat de regels consequenties inderdaad
				// iets zegt over het feit dat we zochten.
				assert(isset($this->facts[$fact]));

				return $this->facts[$fact];
			}
			else
			{
				// probeer een goeie vraag te vinden of een andere regel?
				continue;
			}
		}

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

		debug("Could not infer {$fact}");

		// als we het echt niet weten, return NULL.
		return null;
	}

	/**
	 * Pas $consequences toe op de $facts die we al hebben.
	 *
	 * @param array $consequences key-value paren met facts en hun truth-value.
	 */
	public function apply(array $consequences)
	{
		foreach ($consequences as $fact => $value)
		{
			// assumptie: consequenties zijn alleen facts, geen twijfels.
			assert($value === true || $value === false);

			// assumptie: regels spreken elkaar niet tegen
			assert(!isset($this->facts[$fact]) || $this->facts[$fact] === $value);

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