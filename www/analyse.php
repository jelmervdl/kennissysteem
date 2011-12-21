<?php

include '../util.php';
include '../solver.php';
include '../reader.php';

if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.xml$/i', $_GET['kb']))
	die('Doe eens niet!');

$reader = new KnowledgeBaseReader;
$state = $reader->parse('../knowledgebases/' . $_GET['kb']);

class FactStatistics
{
	public $name;

	public $values;

	public function __construct($name)
	{
		$this->name = $name;

		$this->values = new Map(function() {
			return new FactValueStatistics;
		});
	}
}

class FactValueStatistics
{
	public $inferringRules;

	public $dependingRules;

	public $inferringQuestions;

	public function __construct()
	{
		$this->inferringRules = new Set;

		$this->dependingRules = new Set;

		$this->inferringQuestions = new Set;
	}
}

$stats = new Map(function($fact_name) {
	return new FactStatistics($fact_name);
});

foreach ($state->rules as $rule)
{
	$fact_conditions = array_filter_type('FactCondition',
		array_flatten($rule->condition->asArray()));
	
	foreach ($fact_conditions as $condition)
		$stats[$condition->name]
			->values[$condition->value]
			->dependingRules
			->push($condition->value);
	
	foreach ($rule->consequences as $fact_name => $value)
		$stats[$fact_name]
			->values[$value]
			->inferringRules
			->push($rule);
}

foreach ($state->questions as $question)
	foreach ($question->options as $option)
		foreach ($option->consequences as $fact_name => $value)
			$stats[$fact_name]
				->values[$value]
				->inferringQuestions
				->push($question);

foreach ($stats as $fact)
{
	printf('
		<h3>%s</h3>
		<table>
			<thead>
				<tr>
					<th>Waarde</th>
					<th>Afleidende vragen</th>
					<th>Afleindende regels</th>
					<th>Afleidende regels + vragen</th>
					<th>Testende regels</th>
				</tr>
			</thead>
			<tbody>', $fact->name);
	foreach ($fact->values as $value => $value_stats)
	{
		printf('
			<tr>
				<td>%s</td>
				<td>%d</td>
				<td>%d</td>
				<td><strong>%d</strong></td>
				<td><strong>%d</strong></td>
			</tr>',
				$value,
				count($value_stats->inferringQuestions),
				count($value_stats->inferringRules),
				count($value_stats->inferringQuestions)
				+ count($value_stats->inferringRules),
				count($value_stats->dependingRules));
	}
	print('
		</tbody>
	</table>');
}
