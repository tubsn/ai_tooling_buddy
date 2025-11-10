const {createApp} = Vue

createApp({
data() {
	return {
		input : null,
		output : null,
		eventSource: null,		
		loading : false,
		stopWatchStartTime: null,
		responseSeconds: 0,
		debuginfo: null,
	}
},

components: {
	//'section-selector': SectionSelectorComponent,
},

computed: {},

watch: {},

mounted() {
	this.init()
},

methods: {

	init() {
		this.getDebugInfo()

	},

	async getDebugInfo() {
		try {

			this.loading = true
			let url = '/debug'

			let postData = new FormData();

			// postData.append('input', this.input);
			// options = {method: 'POST', body: postData}

			const response = await fetch(url);
			if (!response.ok) {throw new Error('Network Error')}

			const data = await response.json()
			console.log(data);
			this.debuginfo = data

		} catch (error) {
			console.error('Fetch Error:', error)
		}

		this.loading = false

	},

	send() {
		this.stream()

	},



	async stream(conversionID) {

		this.startClock()
		this.output = ''
		this.loading = true

		let url = '/stream'

		this.eventSource = new EventSource(url);

		this.eventSource.addEventListener('message', (event) => {
			let data = JSON.parse(event.data)
			console.log(data)
			if (data.text) {
				this.output += data.text
			}
		})

		this.eventSource.addEventListener('stop', (event) => {
			this.stopStream()
		})

		this.eventSource.addEventListener("error", (event) => {
			this.errormessages = event.data
			this.stopStream()
		});

		document.removeEventListener("keydown", this.stopStreamOnEscape);
		document.addEventListener("keydown", this.stopStreamOnEscape);

	},

	stopStreamOnEscape(event) {
		if (event.key === "Escape") {
			this.stopStream()
		}
	},

	stopStream() {
		this.eventSource.close()
		this.stopClock()
		this.loading = false
	},

	startClock() {this.stopWatchStartTime = Date.now(); this.responseSeconds = 0},
	stopClock() {this.responseSeconds = this.elapsedTime()},
	elapsedTime() {
		if (!this.stopWatchStartTime) {return 0}
		return (Date.now() - this.stopWatchStartTime) / 1000
	},

}, // End of Methods

}).mount('#chatapp')