<main id="chatapp">

<h1>Buddy Testarea</h1>

<div class="col-2 box mb">

	<section>Input:
		<textarea v-model="input" class="input"></textarea>
	</section>

	<section>Output:
		<textarea v-model="output" class="output"></textarea>
	</section>

</div>

<button class="button" :disabled="loading" @click="send">Absenden</button>

<div class="fright" v-if="loading">Loading</div>
<div class="fright small" v-if="responseSeconds > 0">Time: {{ responseSeconds }}s</div>

<hr>

<a href="/debug" target="_blank">Debug</a>

<div>
	<pre v-html="debuginfo"></pre>
</div>


</main>