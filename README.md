# What is this?
This school project is a knowledge system that could guide you through the Dutch laws and guidelines regarding the safety of buildings. It can solve goals by trying to infer rules and questions that say something about the goals. Note that this system is incomplete.

Because the course is given in Dutch and everyone working on the project speaks Dutch, comments and some parts of the code are in Dutch.

# Usage
Currently only a CLI version is implemented. This can be started by calling

	main.php [-v] knowledge-base [goal]

Example:
	
	main.php knowledge.xml regels_van_toepassing

When no goal is provided, it will try to solve all the goals as described in the knowledge base.