const {createApp} = Vue

createApp({
data() {
	return {
		input : null,
		output : null,
		model: null,
		eventSource: null,		
		loading : false,
		reasoning : false,
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
	},

	async fetchHistory() {
		let response = await fetch('/stream/session')
		if (!response.ok) {return}
		let json = await response.json()
		this.history = json
	},

	clearLogs() {
		this.sseFinalOutput = ''
		this.sseProgress = ''
	},

	async removeHistory() {
		const url = '/stream/killsession'
		const response = await fetch(url)
		this.history = null
		this.clearLogs()
		this.output = ''
	},

	async createStreamRequest() {

		const requestURL = '/stream'
		let payload = {input : this.input}
		// The Payload could be extended with other parameters like the ai model

		const response = await fetch(requestURL, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		});

		if (!response.ok) throw new Error('SSE Setup failed');
		
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
		
		// We can use different handlers for each chunk type

		// the default message chunk contains Json which is processed in handleStream
		this.eventSource.addEventListener('message', (event) => {this.handleStream(JSON.parse(event.data))})
		
		// Events that might stop the stream
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

	// Process some of the openAI responsetypes (delta is the default and contains text chunks)
	handleStream(chunk) {
		switch (chunk.type) {
			case 'delta': {
				this.output += chunk.content
				break
			}

			case 'progress': {
				this.sseProgress.push(chunk.content)
				break
			}

			case 'reasoning': {
				if (chunk.content == 'start') {this.reasoning = true}
				if (chunk.content == 'done') {this.reasoning = false}
				break
			}

			case 'completed': {
				this.sseFinalOutput.push(chunk.content)
				this.usage = chunk.content.usage
				break
			}
		}
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

		marked.use({breaks: true, mangle:false, headerIds: false,});
		this.output = marked.parse(this.output)

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