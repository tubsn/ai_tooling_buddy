const {createApp} = Vue

createApp({
data() {
	return {
		input : null,
		output : null,
		model: null,
		eventSource: null,		
		loading : false,
		stopWatchStartTime: null,
		responsetime: 0,
		usage: [],
		history: null,
		historyExpanded: true,
		errormessages: null,
		sseFinalOutput: [],
		sseProgress: [],
	}
},

components: {
	//'section-selector': SectionSelectorComponent,
},

computed: {
	chars() {
		if (!this.output) {return 0}
		return this.output.length
	},
},

watch: {
	input(content) {sessionStorage.input = content},
	historyExpanded(value) {localStorage.historyExpanded = value;},
},

mounted() {
	if (sessionStorage.input) {this.input = sessionStorage.input}
	this.fetchHistory()
	this.getUserSettings()
},

methods: {

	send() {
		this.errormessages = null
		this.sseFinalOutput = []
		this.sseProgress = []
		this.createStreamRequest()
	},

	getUserSettings() {
		if (localStorage.historyExpanded == 'true') {this.historyExpanded = true}
		else {this.historyExpanded = false}

		if (localStorage.model) {this.model = localStorage.model}
		if (localStorage.userSelectedModel) {this.userSelectedModel = localStorage.userSelectedModel}
	},

	async fetchHistory() {
		let response = await fetch('/stream/session')
		if (!response.ok) {return}
		let json = await response.json()
		this.history = json
	},

	filterInstructions(node) {
		// Removes OpenAI instrucational Arrays e.g. for Vision Uploads
		if (node[0].text) {return node[0].text}
		else {return node}
	},

	clearLogs() {
		this.sseFinalOutput = ''
		this.sseProgress = ''
	},

	async removeHistory() {
		const url = '/stream/killsession';
		const response = await fetch(url);
		this.history = null
		this.clearLogs()
	},

	async createStreamRequest() {

		const requestURL = '/stream'
		let payload = {input : this.input}

		const response = await fetch(requestURL, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		});

		if (!response.ok) throw new Error('Kanal-Erstellung fehlgeschlagen');
		
		const data = await response.json()
		const streamURL = data.url

		this.stream(streamURL)
	},


	async stream(url) {

		this.startClock()
		this.output = ''
		this.loading = true

		if (!url) {url = '/stream'}

		this.eventSource = new EventSource(url, { withCredentials: true });
		this.eventSource.addEventListener('message', (event) => {
			let data = JSON.parse(event.data)

			if (data.type == 'progress') {
				this.sseProgress.push(data.content)
			}

			if (data.type == 'completed') {
				this.sseFinalOutput.push(data.content)
				this.usage = data.content.usage
			}

			if (data.text) {this.output += data.text}

		})

		this.eventSource.addEventListener('done', (event) => {this.stopStream()})
		this.eventSource.addEventListener('stop', (event) => {this.stopStream()})
		this.eventSource.addEventListener("error", (event) => {
			if (event.data) {
				this.errormessages = event.data
				this.output += event.data
			}
			this.stopStream()
		});

		document.removeEventListener("keydown", this.stopStreamOnEscape);
		document.addEventListener("keydown", this.stopStreamOnEscape);

	},

	autofocus() {
		if (!this.$refs.autofocusElement) {return}
		Vue.nextTick(() => {this.$refs.autofocusElement.focus()})
	},

	stopStreamOnEscape(event) {
		if (event.key === "Escape") {
			this.stopStream()
		}
	},

	stopStream() {
		this.eventSource.close()
		this.stopClock()
		this.autofocus()
		this.fetchHistory()
		this.loading = false
	},

	startClock() {this.stopWatchStartTime = Date.now(); this.responsetime = 0},
	stopClock() {this.responsetime = this.elapsedTime()},
	elapsedTime() {
		if (!this.stopWatchStartTime) {return 0}
		return (Date.now() - this.stopWatchStartTime) / 1000
	},

}, // End of Methods

}).mount('#chatapp')