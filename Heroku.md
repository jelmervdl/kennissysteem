# Deploying to Heroku

1. [Fork this repository if you want a public repository](https://help.github.com/articles/fork-a-repo/) or [duplicate the repository if you want a private repository](https://help.github.com/articles/duplicating-a-repository/#platform-mac)
2. Make an account at [Heroku](https://signup.heroku.com/), with a free account a Dyno (basically a little server) [can run for 550 hours](https://devcenter.heroku.com/articles/free-dyno-hours). A dyno shuts down after 30 minutes of inactivity so this should be enough! 
3. Make a new application in your Heroku dashboard, or just follow [this link](https://dashboard.heroku.com/new-app)
4. Go to 'Deploy' on the page of your app, select GitHub as a deployment method
5. Authorise your GitHub account with Heroku
6. Type in your repository name and click search. Once the results show up click connect for the correct repository. 
7. Click 'Enable Automatic Deploys', this enables Heroku to deploy your app at each push to the master branch of your repository. 
8. Do a first manual deployment with the 'Deploy Branch' button
9. Click on 'Open App' at the top of the page to view your freshly setup app! The URL can always be reached, but it may take some time to boot if the Dyno is sleeping.