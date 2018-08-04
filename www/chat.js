class Chat {
	constructor(root, knowledgeBase) {
		this.root = root;
		this.knowledgeBase = knowledgeBase;
		this.domain = null;
		this.state = null;
		this.log = null;

		this.chatContainer = document.createElement('div');
		this.root.appendChild(this.chatContainer);

		this.optionContainer = document.createElement('div');
		this.root.appendChild(this.optionContainer);
	}

	get url() {
		return 'webfrontend.php?kb=' + encodeURIComponent(this.knowledgeBase);
	}

	start() {
		return fetch(this.url).then(response => response.json()).then(this.onResponse.bind(this));
	}

	request(consequences) {
		const body = new FormData();
		body.append('state', this.state);
		body.append('answer', consequences);

		const config = {
			method: 'POST',
			body: body
		};

		return fetch(this.url, config)
			.then(response => response.json())
			.then(this.onResponse.bind(this), this.onError.bind(this));
	}

	onResponse(data) {
		if ('state' in data)
			this.state = data.state;

		if ('domain' in data)
			this.domain = data.domain;

		if ('log' in data)
			this.log = data.log;

		if ('question' in data)
			this.onQuestion(data.question);
		else if ('goals' in data)
			this.onConclusion(data.goals);
		else if ('error' in data)
			this.onError(data.error);
		else
			throw new Error('Unknown response state');
	}

	onQuestion(question) {
		this.addQuestion(question.description)
		this.setOptions(question.options);
	}

	onAnswer(option) {
		this.optionContainer.innerHTML = '';
		this.addBubble(option.description, 'me');
		this.request(option.consequences);
	}

	onConclusion(goals) {
		goals.forEach(goal => {
			this.addBubble(goal);
		});
	}

	onError(error) {
		this.addNotice(error.toString());
	}

	addQuestion(question) {
		this.addBubble(question, 'they');
	}

	addNotice(text, className) {
		const notice = document.createElement('div');
		const span = document.createElement('span');
		span.textContent = text;
		notice.appendChild(span);
		notice.classList.add('chat-notice', className);
		this.chatContainer.appendChild(notice);
	}

	addBubble(text, className) {
		const bubble = document.createElement('div');
		const span = document.createElement('span');
		span.innerHTML = text;
		bubble.appendChild(span);
		bubble.classList.add('chat-bubble', className);
		this.chatContainer.appendChild(bubble);
	}

	setOptions(options) {
		this.optionContainer.innerHTML = '';
		options.forEach(option => {
			const button = document.createElement('button');
			this.optionContainer.appendChild(button);
			button.className = 'chat-option';
			button.innerHTML = option.description;
			button.addEventListener('click', e => {
				this.onAnswer(option);
			});
		});
	}
}