# What is this?
This school project is a knowledge system that could guide you through the Dutch laws and guidelines regarding the safety of buildings. It can solve goals by trying to infer rules and questions that say something about the goals. Note that this system is incomplete.

Because the course is given in Dutch and everyone working on the project speaks Dutch, comments and some parts of the code are in Dutch.

# Usage
## Web based version
The version in this repository is hosted at http://kat.ikhoefgeen.nl/ but it is easy to set up a small webserver and host your own code. Any webserver with PHP >= 5.4 should do. If you are taking the Knowledge Technology Practical I can arrange hosting for you.

Put your knowledge base in the 'knowledgebases' folder (or upload them through the web interface) and point your browser to the www/index.php file to get started.

## CLI-version
This can be started by calling

	main.php [-v] knowledge-base

Example:
	
	main.php knowledge.xml

# Knowledge base format
The knowledge base can contain rules, questions and goals to infer and is written using XML. See `www/knowledge-base-example.xml` for an example.

## Knowledge file
	<knowledge>
		<title>Title of the knowledge base</title>
		<description>A short description of what your knowledge base can infer</description>

		<!-- rules, questions and goals go here -->
	</knowledge>

## Rules
	<rule>
		<description>A description of the rule</description>

		<!-- conditions of when the rule can be applied -->
		<if>
			<!-- testing the value value of a fact -->
			<fact name="pressure">high</fact>
			
			<!-- You can nest conditions -->
			<and>
				<fact name="pressure">low</fact>

				<!-- negations -->
				<not>
					<fact name="state_machine">on</fact>
				</not>
			</and>
		</iff>

		<!-- consequences of the rule -->
		<then>
			<fact name="alarm">yes</fact>
		</then>
	</rule>

## Questions
	<question>
		<description>Is the machine turned on?</description>

		<!-- each possible answer is encoded in an option -->
		<option>
			<description>The machine is powered on</description>
			<then>
				<fact name="state_machine">on</fact>
			</then>
		</option>
		<option>
			<description>The machine is powered off</description>
			<then>
				<fact name="state_machine">off</fact>
			</then>
		</option>
	</question>

## Goals
	<goal name="alarm">
		<description>Should the alarm be triggered?</description>
		<!-- See, you can use HTML :) -->
		<answer value="yes"><![CDATA[<span style="color:red">PANIC!</span>]]></answer>
		<answer value="no">Keep calm and carry on</answer>
		<answer value="undefined">... and you are asking me?!</answer>
	</goal>

# Features
- Simple rule inference using backward chaining.
- Use HTML to make your questions and answers pretty
- Questions are optional: you can use both rules and questions to get to an answer. You can use this to ask complex questions to expert users and allow the less expert user to skip the question (to be bombarded with more but simpler questions.)
- Tool to analyse knowledge base which helps you find uncovered cases.

# Suggested improvements
This system works but is no where near feature-complete. The following improvements are things I thought of while trying to model certain problems.

## Forward chaining
The current implementation relies solely on backward chaining, adding fact names to the goal stack while trying to find rules that can determine the value of a certain fact.

## Better ordering of questions asked
Currently this is implemented by simply counting which fact is used most in the rules that can be applied to reach a goal, trying to take into account the nesting of that rule. This could be improved by also taking the operator into account (e.g. 'not') or adding more weight to asking questions.

## Support for open ended questions
In stead of asking someone his age using multiple choice, you could ask them to enter it in a number field. If you do this, you probably want to implement the operators greater-than and less-than as well.

## Discrete set of possible values for facts
E.g. if something is not 'yes', it has to be 'no'. Currently it just becomes 'undefined'.

## More OWL-like domain modeling
This is a bit of a more complicated implementation of the previous suggestion. For example, if you have to choose a body part that hurts, one could say 'nose'. And if something in the head hurts, we may need paracetamol. But in stead of naming all the parts of the head (ear, nose, mouth, tongue, etc.) we may want to just say 'head' and the system should infer that 'nose' is a part of 'head'. This could look something like this:

	<fact name="place of pain">
		<option value="head">
			<option value="ear"/>
			<option value="nose"/>
		</option>
		<option value="body">
			<option value="stomach">
				<option value="upper stomach"/>
				<option value="lower stomach"/>
			</option>
		</option>
	</fact>

Rules would need new operators, like 'part-of':

	<rule>
		<if>
			<fact name="place of pain" operator="part-of">head</fact>
		</if>
		<then>
			<fact name="medicine">paracetamol</fact>
		</then>
	</rule>

Questions could have a negation in the consequence:

	<question>
		<description>Does your nose hurt?</description>
		<option>
			<description>Yes</description>
			<then>
				<fact name="place of pain">nose</fact>
			</then>
		</option>
		<option>
			<description>No</description>
			<then>
				<not>
					<fact name="place of pain">nose</fact>
				</not>
			</then>
		</option>
	</question>

This can all be modeled with the current implementation, but it would need many more facts and rules and you would be polluting your knowledge base with basic human inference knowledge.
	
	
