<main id="chatapp">

<h1>Buddy Testarea</h1>
<p v-if="errormessages">{{ errormessages }}</p>

<section class="box mb">
	<div class="col-2 mb">

		<section>Input:
			<textarea autofocus ref="autofocusElement" tabindex="1" v-model="input" class="input"></textarea>
		</section>

		<section class="output">Output:
			<span class="output-info" v-if="reasoning">(nutze Reasoning)</span>
			<textarea v-model="output" tabindex="0" class="output"></textarea>
		</section>

	</div>

	<button class="button" tabindex="2" :disabled="loading" @click="send">Absenden</button>
	&ensp;
	<button class="button light" tabindex="0" :disabled="loading" @click="removeHistory">Verlauf löschen</button>


	<div class="fright small">
		<span class="ml loading-wrapper" v-if="loading">
			<div class="loadIndicator"><div></div><div></div><div></div></div> generiere - abbrechen <b>[ESC]</b>
			<!--<img class="mini-robot" src="/styles/img/ai-buddy.svg">-->
		</span>
		<span v-if="responsetime" class="ml">Antwortzeit: <b>{{ responsetime }}&thinsp;s</b> | Tokens: <b>{{ usage.input_tokens }} / {{ usage.output_tokens }} </b> | Zeichen: <b>{{ chars }}</b></span>
	</div>
</section>

<details v-if="history" :open="historyExpanded">
<summary @click.self.prevent="historyExpanded = !historyExpanded">Chatverlauf einblenden</summary>
	<table class="fancy history wide">
		<tr :class="entry.role.toLowerCase()" v-for="entry,index in history"> 
			<td class="ucfirst">{{entry.role}}</td>
			<td><pre>{{filterInstructions(entry.content)}}</pre></td>
			<td class="text-right nowrap">
				<!--
				<span title="Eintrag kopieren">
				<img class="icon-copy" src="/styles/img/copy-icon.svg">
				</span>&nbsp;<span @click="removeHistoryEntry(index)" title="Eintrag löschen">
				<img class="icon-delete" src="/styles/flundr/img/icon-delete-black.svg">
				</span>
				-->
			</td>
		</tr>
	</table>
</details>



<div class="col-2">
	<label>MCP/Tooling Events:
	<textarea v-if="sseProgress.length>0" tabindex="0">{{ sseProgress }}</textarea>
	<textarea v-else tabindex="0"></textarea>
	</label>
	<label>Output Events:
	<textarea v-if="sseFinalOutput.length>0" tabindex="0">{{ sseFinalOutput }}</textarea>
	<textarea v-else tabindex="0"></textarea>
	</label>
</div>
</main>