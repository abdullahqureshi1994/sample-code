<script setup>
import { ref, onMounted, onUnmounted, nextTick, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3'
import Typewriter from 'typewriter-effect/dist/core';
import { useToast } from "vue-toastification"
import { parse, use } from 'marked'
import hljs from 'highlight.js'
import { useDisplay } from 'vuetify'
import { constructURL, getURLQueryParams } from '@/Helpers'
import CustomGPTLayout from '@/Layouts/CustomGPTLayout.vue';
import TextInput from '@/Components/CustomGPT/TextInput.vue';
import ProjectShareModal from '@/Components/CustomGPT/ProjectShareModal.vue';
import ProgressBar from '@/Components/CustomGPT/ProgressBar.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ChatBotTextArea from '@/Components/CustomGPT/ChatBotTextArea.vue'
import ConversationList from '@/Components/CustomGPT/ConversationList.vue';

const toast = useToast()

const promptRules = [
	(v) => !!v || 'Prompt is required',
	(v) => v.length <= 1000 || 'Prompt must be less than 1000 characters',
]

const props = defineProps({
	session_id: String,
	project: Object,
	canChangeShare: Boolean,
	user: Object,
	translated_messages: Object,
});

const getProjectExampels = computed(() => props.project.settings.example_questions ?? [])
const getUserBg = computed(() => `url(${props.project.settings.chat_bot_bg})`)
const getChatBotAvatar = computed(() => props.project.settings.chat_bot_avatar)
const getUserAvatar = computed(() => props.user?.profile_photo_url ?? null)
const getUserDefaultPrompt = computed(() => props.project?.settings?.default_prompt ?? 'Ask Me Anything ...')
const getTranslatedMessages = computed(() => props.translated_messages ?? null)
const getWaitPromptResponseTextTranslation = computed(() => getTranslatedMessages.value?.hang_in_there ?? "Hang in there! I'm thinking ..")
const getFullPoweredByUrl = computed(() => {
	const queryParams = {
		fpr: getURLQueryParams('affid'),
	}

	return constructURL(siteUrl.value, queryParams)
})

const hideProgressbar = ref(true)
const promptQuery = ref('')
const typingSpeed = ref(25)
const messages = ref([])
const currentConversation = ref(null)
const conversations = ref([])
const typewriters = ref([])
const typewriterBoxes = ref([])
const totalDeviceHeight = ref(useDisplay())
const isActiveTab = ref(0)
const siteUrl = ref(import.meta.env.VITE_APP_URL)
const siteName = ref(import.meta.env.VITE_APP_NAME)
const isConversationListOpened = ref(false)
const typeWriterMutationObserver = ref(null)
const messagesContainerRef = ref(null)
const citationsData = ref(null)
const visibleCite = ref(0)

const typeText = (index) => {
	nextTick(() => {
		typewriters.value[index] = new Typewriter(typewriterBoxes.value[index], {
			wrapperClassName: "typewritter-wrapper",
			cursorClassName: "d-none",
			cursor: "",
			delay: typingSpeed.value,
			deleteSpeed: 0,
			loop: false,
			autoStart: false,
			stringSplitter: (string) => {
				return string.replace(/\&amp;/g, "&").replace(/\&gt;/g, ">").replace(/\&lt;/g, "<").split("")
			},
		})

		typewriters.value[index].deleteAll().typeString(messages.value[index].openai_response).start()
		autoScroll(index)
	})
}

const disconnectTheObserver = () => {
	typeWriterMutationObserver.value?.disconnect()
	typeWriterMutationObserver.value = null
}

const scrollToRefView = () => {
	nextTick(() => {
		messagesContainerRef.value?.scrollTo({
			top: messagesContainerRef.value.scrollHeight,
			behavior: 'instant',
		})
	})
}

const autoScroll = (index) => {
	disconnectTheObserver()
	new MutationObserver((mutations, observer) => {
		typeWriterMutationObserver.value = observer
		mutations.forEach((mutation) => {
			if (mutation.type === "childList" && mutation.addedNodes.length > 0) {
				scrollToRefView()
			}
		})
	})?.observe(typewriterBoxes.value[index], {
		childList: true,
		subtree: true,
	})
}

const buildRoute = (url) => {
	const queryParams = {
		...getURLQueryParams(),
		shareable_slug: props.project.shareable_slug,
		output: getURLQueryParams('output'),
		embed: getURLQueryParams('embed'),
		lang: getURLQueryParams('lang'),
		affid: getURLQueryParams('affid'),
		session_id: props.session_id ?? null,
	}

	return constructURL(url, queryParams)
}

const createConversation = (callback) => {
	const url = buildRoute(route('projects.conversations.create', { 'id': props.project.id }))
	axios.post(url, {
		prompt: promptQuery.value,
	})
		.then(({ data }) => {
			updateActiveConversation(data)
			conversations.value.unshift(data)
			callback && callback()
		})
		.catch(error => {
			toast.error(error?.response?.data?.message ?? 'There is error during create conversation', {
				timeout: 3000
			})
		})
}

const sendMessage = () => {
	citationsData.value = null;
	let url = buildRoute(route('projects.conversations.message', { 'project': props.project.id, 'conversation': currentConversation.value?.session_id }))

	hideProgressbar.value = false
	messages.value.map(message => {
		message.is_queried = false
		return message
	})

	messages.value.push({
		'user_query': promptQuery.value,
		'openai_response': getWaitPromptResponseTextTranslation.value,
		'is_queried': true
	})

	typewriters.value.push(null)
	let messageIndex = messages.value.length - 1
	typeText(messageIndex)

	const prompt = promptQuery.value
	promptQuery.value = ''
	axios.put(url, { 'prompt': prompt })
		.then(({ data }) => {
			messages.value[messageIndex].id = data.id
			messages.value[messageIndex].created_at = data.created_at
			messages.value[messageIndex].hasCitations = data.hasCitations;
			messages.value[messageIndex].session_id = currentConversation.value.session_id;
			messages.value[messageIndex].openai_response = parse(data.response, {
				gfm: true,
				breaks: true,
				smartLists: true,
				smartypants: true,
				xhtml: true,
			}).trim()
			typeText(messageIndex)

			if (!currentConversation.value) {
				conversations.value.unshift(data.conversation)
				updateActiveConversation(data.conversation)
			}
		})
		.catch(error => {
			messages.value.splice(messageIndex, 1)
			toast.error(error?.response?.data?.message ?? 'There is error during send your message', {
				timeout: 3000
			})
		})
		.finally(response => {
			hideProgressbar.value = true
		})
}

const submit = () => {
	if (!hideProgressbar.value || !promptQuery.value.trim().length) return

	if (!currentConversation.value) {
		createConversation(sendMessage)
	} else {
		sendMessage()
	}
}

/**
 * Send prompt from url query params if exists
 * @details Check if prompt query params exists, if exists then remove it from url query params and send it as a message
 *
 * @return void
 */
const sendPromptFromUrl = () => {
	const urlQueryParams = new URLSearchParams(window.location.search)

	if (urlQueryParams.has('prompt') && urlQueryParams.get('prompt').trim().length) {
		promptQuery.value = urlQueryParams.get('prompt')
		updateUrlWithParams(currentConversation.value?.session_id, ['prompt'])
		submit()
	}
}

const loadMessages = () => {
	messages.value = []
	if (!currentConversation.value) return;
	hideProgressbar.value = false
	axios.get(buildRoute(route('conversations.show', currentConversation.value)))
		.then(response => {
			console.log('resp--', response);
			messages.value = response.data
			messages.value.map(item => {
				item.openai_response = parse(item.openai_response, {
					gfm: true,
					breaks: true,
					smartLists: true,
					smartypants: true,
					xhtml: true,
				}).trim()
				item.session_id = currentConversation.value.session_id
			})
			hideProgressbar.value = true
			typewriters.value = Array(messages.value.length)
			scrollToRefView()
			sendPromptFromUrl()
		})
		.catch(error => {
			console.log(error)
			if (error.response.status == 404) {
				createConversation(loadMessages)
			} else {
				toast.error(error?.response?.data?.message ?? 'There is error during loading chat history', {
					timeout: 3000
				})
			}
		})
		.finally(response => {
			hideProgressbar.value = true
		})
}

const loadConversations = () => {
	if (!props.user) {
		return
	}
	hideProgressbar.value = false
	axios.get(buildRoute(route('projects.conversations.index', props.project)))
		.then(response => {
			conversations.value = response.data
		})
		.catch(error => {
			toast.error(error?.response?.data?.message ?? 'There is error during loading chat history', {
				timeout: 3000
			})
		})
		.finally(response => {
			hideProgressbar.value = true
		})
}

const inputPrompt = (prompt) => {
	promptQuery.value = prompt
	submit()
}

const changeActiveTab = (index) => isActiveTab.value = index
const slidePrevious = () => {
	changeActiveTab(isActiveTab.value - 1 >= 0 ? isActiveTab.value - 1 : getProjectExampels.value.length - 1)
}

const slideNext = () => {
	changeActiveTab(isActiveTab.value + 1 < getProjectExampels.value.length ? isActiveTab.value + 1 : 0)
}

const calculateMaxHeight = () => {
	const navBar = document.getElementById("layout-navbar").offsetHeight
	const projectTitle = document.getElementById("ProjectTitle").offsetHeight
	const amaContent = document.getElementById("amaContent")
	amaContent.style.height = `${totalDeviceHeight.value.height - projectTitle - navBar}px`
}

const onMessagesContainerScroll = () => {
	const hasUserScrolledUp = Boolean(messagesContainerRef.value.scrollTop < messagesContainerRef.value.scrollHeight - messagesContainerRef.value.clientHeight - 1)
	hasUserScrolledUp && disconnectTheObserver()
}

watch(messagesContainerRef, (newVal) => {
	if (!newVal) return

	messagesContainerRef.value?.addEventListener('scroll', onMessagesContainerScroll)
})

onMounted(() => {
	const renderer = {
		code: (code, infostring, escaped) => {
			return `<div class="hljs hl-code"><pre><code>${hljs.highlightAuto(code).value}</code></pre></div>`
		}
	}

	use({ renderer })
	if (props.session_id) {
		changeConversation({ 'session_id': props.session_id })
	} else {
		sendPromptFromUrl()
	}

	loadConversations()
	calculateMaxHeight()
	window.addEventListener('resize', calculateMaxHeight)
})

onUnmounted(() => {
	window.removeEventListener('resize', calculateMaxHeight)
	messagesContainerRef.value?.removeEventListener('scroll', onMessagesContainerScroll)
})

/**
 * Change the active conversation and load its messages
 *
 * @param {Array} conversation The active conversation object
 */
const changeConversation = (conversation) => {
	updateActiveConversation(conversation, [])
	loadMessages()
}

const getCitations = (prompt, session_id) => {
	console.log('prompt--', promptQuery.value, props.project);
	const completeURL = new URL(route('conversations.citations.show', { conversation:session_id, prompt}));
	axios.get(completeURL).then((resp) => {
		if (resp.data.status == 'success' && resp.data && resp.data.response && resp.data.response.length) {
			citationsData.value = resp.data.response;
		}
		else if (resp.data.status == 'success' && resp.data.response && resp.data.response.length <= 0) {
			toast.info('No citation found', {
				timeout: 3000
			});
		}
		else {
			toast.error(resp.data?.response ?? 'Unexpected system error!', {
				timeout: 3000
			});
		}
	}).catch((error) => {
		toast.error(error.message, {
			timeout: 3000
		});
	});
}
const visibleCiteData = computed(() => {
  if(citationsData.value){
    const length = citationsData.value.length
    return citationsData.value.map((item, index) => ({
      ...item,
      visible: index === Math.abs(visibleCite.value) % length
    }))
  }
  else{
    return [];
  }
});

const next = () => {
  visibleCite.value++
}

const prev = () => {
  visibleCite.value--
}
/**
 * Update the active conversation and update the url with session id
 *
 * @param {Array} conversation The active conversation object
 */
const updateActiveConversation = (conversation, query = []) => {
	isConversationListOpened.value = false
	currentConversation.value = conversation
	updateUrlWithParams(conversation?.session_id, query)
}

/**
 * Update the url with session id and query params
 * @param {String} sessionId The conversation's session id
 * @param {Object} query The query params to be removed from url query params
 */
const updateUrlWithParams = (sessionId, queryParams = []) => {
	const newUrl = new URL(route('projects.ask-me-anything', { id: props.project.id, customgpt_session_id: sessionId ?? '' }))
	const urlQueryParams = new URLSearchParams(window.location.search)
	queryParams.forEach(queryParam => urlQueryParams.delete(queryParam))
	newUrl.search = urlQueryParams.toString()
	router.replace(newUrl.href)
}
</script>

<template>
	<CustomGPTLayout title="Ask Me Anything" :currentProjectId=project.id :shareableSlug=project.shareable_slug>
		<template #content="{ embed }">
			<div class="flex-grow-1 d-flex flex-column"
				:class="{ 'pt-0 pb-0': embed, 'container-xxl container-p-y': !embed }">
				<div class="d-flex align-items-center justify-content-between" v-if="!embed" id="ProjectTitle">
					<h4 class="fw-bold mb-2">
						<span class="text-muted fs-1-5 fw-light">Ask Me Anything! / {{ project.project_name }}</span>
					</h4>
					<div class="d-flex flex-row gap-1">
						<ProjectShareModal :project="project" :canChangeShare="canChangeShare"
							:route="route('projects.ask-me-anything', project.id)" />
					</div>
				</div>
				<ConversationList :showOuterHeader="!!($vuetify.display.smAndDown && !embed && user)"
					v-model:isDrawerOpen="isConversationListOpened">
					<template #outer-header>
						<v-btn @click.prevent="changeConversation(null)" variant="outlined">
							<i class="ti ti-plus" />
							<span class="text-truncate">New conversation</span>
						</v-btn>
					</template>
					<template #list>
						<div class="gap-1 card shadow-none">
							<div class="px-4 py-4 mx-auto">
								<v-btn class="border" @click.prevent="changeConversation(null)" variant="text">
									<i class="ti ti-plus" />
									<span class="text-truncate">New conversation</span>
								</v-btn>
							</div>
							<hr class="mt-0 mb-0" />
							<div class="card-body pt-2 overflow-y-auto overflow-x-hidden">
								<div class="text-truncate cursor-pointer px-3 py-2 rounded"
									@click.prevent="changeConversation(item)" v-for="item in conversations" :key="item.id"
									:class="{ 'border': currentConversation?.id == item.id }">
									<i class="ti ti-message-dots me-2" />
									<span v-text="item.name" />
								</div>
							</div>
						</div>
					</template>
					<template #body>
						<div class="d-flex align-items-stretch justify-content-between gap-2 flex-grow-1 overflow-hidden"
							id="amaContent">
							<div class="card flex-shrink-0 conversation-list gap-1"
								v-if="!embed && props.user && !$vuetify.display.mdAndDown">
								<div class="px-4 py-4 mx-auto">
									<v-btn @click.prevent="changeConversation(null)" variant="outlined">
										<i class="ti ti-plus" />
										<span class="text-truncate">New conversation</span>
									</v-btn>
								</div>
								<hr class="mt-0 mb-0" />
								<div class="card-body pt-2 overflow-y-auto overflow-x-hidden">
									<div class="text-truncate cursor-pointer px-3 py-2 rounded"
										@click.prevent="changeConversation(item)" v-for="item in conversations"
										:key="item.id" :class="{ 'border': currentConversation?.id == item.id }">
										<i class="ti ti-message-dots me-2" />
										<span v-text="item.name" />
									</div>
								</div>
							</div>
							<div class="card chatbot-pane py-4 flex-grow-1 overflow-hidden"
								:style="{ backgroundImage: getUserBg }"
								:class="{ 'rounded-0': embed, 'align-items-center justify-content-center': !currentConversation }">
								<template v-if="currentConversation">
									<div class="card-body overflow-y-auto pb-6" ref="messagesContainerRef">
										<div v-for="message in messages" :key="message.id">
											<div class="container-fluid p-0 mb-5">
												<div class="row">
													<div class="col-2 col-sm-1">
														<div class="avatar avatar-md">
															<img v-if="getUserAvatar" :src="getUserAvatar"
																class="h-100 w-100 rounded-circle" />
															<span v-else
																class="avatar-initial rounded-circle avatar-bg-green">
																<i class="ti ti-user-check fs-3" />
															</span>
														</div>
													</div>
													<div class="col-10">
														<div class="prompt-bubble">
															<p class="mb-0 fs-6 text-white">{{ message.user_query }}</p>
															<!-- <p class="mb-0 fst-italic fs-6" v-if="message.created_at">
																														{{ message.created_at }}
																													</p> -->
														</div>
													</div>
												</div>
											</div>
											<div class="container-fluid p-0 mb-5">
												<div class="row">
													<div class="col-2 col-sm-1">
														<div class="avatar avatar-md">
															<img v-if="getChatBotAvatar" :src="getChatBotAvatar"
																class="rounded-circle" />
															<span v-else
																class="avatar-initial rounded-circle avatar-bg-green">
																<i class="ti ti-user-check fs-3" />
															</span>
														</div>
													</div>
													<div class="col-10">
														<div class="prompt-reply text-black bg-white">
															<p v-html="message.openai_response" ref="typewriterBoxes"
																dir="auto" />
														</div>
														<div class="prompt-inner-info" v-if="visibleCiteData && visibleCiteData.length"  >
															<div class="sources-count" :set="count = (Math.abs(visibleCite) % visibleCiteData.length )+1" >
															<p>Sources {{ count }}/{{ citationsData.length }}</p>
															</div>
															<div :class="`prompt-user-reply cite-${count} align-items-center`" v-for="(cite, index) in visibleCiteData" :key="cite.id" :style="{ display: cite.visible ? 'flex' : 'none' }" >
															<div class="prompt-user-control left non-mobile" @click="prev()" >
																<img src="/assets/imgs/left-arrow.svg">
															</div>
															<div class="prompt-user-img" v-if="cite.favicon" >
																<img :src="cite.favicon"  @error="imageLoadError">
															</div>
															<div class="prompt-user-text">
																<h3>{{ count }}.
																	<span v-if="cite.type=='s3'">
																		<a :href="cite.url" target="_blank" >{{ cite.title }}</a>
																	</span>
																	<span v-else>
																		{{ cite.title }}
																	</span>
																</h3>
																<p v-if="cite.description">{{ cite.description }} <a class="cite-link" :href="cite.url" target="_blank" >{{ cite.url }}</a></p>
															</div>
															<div class="d-flex inline-control">
																<div class="prompt-user-control left mobile" @click="prev()" >
																	<img src="/assets/imgs/left-arrow.svg">
																</div>
																<div class="prompt-user-control right" @click="next()" >
																	<img src="/assets/imgs/right-arrow.svg">
																</div>
															</div>
															</div>
														</div>
														<div class="prompt-inner-info" v-if="message.hasCitations && !citationsData">
															<div class="prompt-user-reply get-citations" @click="getCitations(message.user_query, message.session_id)" >
																<!-- <div class="prompt-user-img no-cite"><img src="/assets/imgs/info-circle.svg"></div> -->
																<div class="prompt-user-text">
																	<p>Where did this answer come from?</p>
																</div>
															</div>
														</div>
													</div>
												</div>
											</div>
											<ProgressBar :status="!message.is_queried || hideProgressbar" />
										</div>
									</div>
								</template>
								<div v-if="false" class="card-body d-flex align-items-center justify-center w-100">
									<div class="mt-6 w-100">
										<div class="text-center fs-5 fw-medium mb-4 d-none d-md-show">
											I'm here, ready, and raring to go. Ask me<br> anything!
										</div>
										<div class="avatar avatar-md rounded-0 mx-auto flex-shrink-0 d-md-none d-none"
											style="min-width: 225px; height: fit-content;">
											<img src="/logo_brand_spark.svg" alt class="h-auto" />
										</div>
									</div>
								</div>
								<div class="card-footer pt-3 w-100">
									<ChatBotTextArea v-model="promptQuery" :rules="promptRules"
										:placeholder="getUserDefaultPrompt" @submit="submit" :disabled="!hideProgressbar">
										<template #inner-icon>
											<div class="cursor-pointer" @click.prevent="submit">
												<i class="ti ti-send text-muted" />
											</div>
										</template>
									</ChatBotTextArea>
									<div class="site-signature text-end mt-4">
										<span style="color: #292929;">Powered by </span>
										<a :href="getFullPoweredByUrl" target="_blank" style="color: #151517;"
											class="fw-bold" v-text="siteName" />
									</div>
									<div v-if="!currentConversation && getProjectExampels.length"
										class="prompt-examples d-flex w-100 flex-column flex-wrap justify-center align-items-center">
										<div class="d-flex flex-direction-row flex-wrap">
											<div v-for="(examples, index) in getProjectExampels" :key="index"
												:class="{ 'text-white': isActiveTab === index }" class="cursor-pointer"
												@click="changeActiveTab(index)">
												<v-icon icon="mdi-minus" class="fs-1" />
											</div>
										</div>
										<div
											class="d-flex flex-row overflow-hidden prompt-examples-container align-items-center justify-center">
											<div class="prompt-arrow cursor-pointer fs-4" @click="slidePrevious">
												<v-icon icon="mdi-chevron-left" class="text-white" />
											</div>
											<div class="prompt-example text-white cursor-pointer"
												v-for="(example, index) in getProjectExampels" :key="index"
												:class="{ 'active': isActiveTab === index }" @click="inputPrompt(example)">
												<div class="prompt-example-text mx-auto">
													<span>{{ example }}</span>
												</div>
											</div>
											<div class="prompt-arrow cursor-pointer fs-4" @click="slideNext">
												<v-icon icon="mdi-chevron-right" class="text-white" />
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</template>
				</ConversationList>
			</div>
		</template>
	</CustomGPTLayout>
</template>

<style scope>
:root {
	--conversation-list-width: 320px;
}

.conversation-list {
	width: var(--conversation-list-width);
}

.conversation-name {
	width: var(--conversation-list-width);
}

.chatbot-pane {
	background-size: cover;
	background-position: center;
	background-repeat: no-repeat;
}

.prompt-bubble,
.prompt-reply {
	padding: 1rem;
	border-top-right-radius: .5rem;
	/* border-bottom-right-radius: .5rem; */
	/* border-bottom-left-radius: .5rem; */
	/* width: fit-content; */
}

.prompt-bubble {
	background-color: #7367f0;
}

.avatar-bg-green {
	background-color: rgb(var(--bs-success-rgb)) !important;
}

.prompt-examples-container {
	width: 100%;
}

.prompt-example {
	text-align: center;
	font-size: 1.2rem;
	font-weight: 500;
	width: 0;
	height: 0;
	visibility: hidden;
	opacity: 0;
}

.prompt-example.active {
	padding: 1rem;
	width: 60%;
	height: 100%;
	animation: slide-effect 1s ease-in-out both alternate 1;
}

.prompt-example-text {
	height: 100%;
}

@keyframes slide-effect {
	from {
		transform: translateX(-100%);
		opacity: 0;
		visibility: hidden;
	}

	to {
		transform: translateX(0);
		opacity: 1;
		visibility: visible;
	}
}


.response-output pre {
	white-space: pre-wrap;
}

.response-output .typewritter-wrapper {
	display: flex;
	flex-direction: column;
	width: 100%;
	gap: 0.5rem;
}

.response-output * {
	margin-bottom: 0 !important;
	margin-top: 0 !important;
}

.hl-code {
	background-color: #1e1e1e;
	color: #d4d4d4;
	padding: 1rem;
	border-radius: 0.5rem;
}

.hl-code .hljs-keyword {
	color: #ff9d00;
}

.hl-code .hljs-string {
	color: #7ec699;
}

.hl-code .hljs-function {
	color: #ff9d00;
}

.fade-in-enter-active,
.fade-in-leave-active {
	transition: opacity 0.5s ease-in-out;
}

.fade-in-enter-from,
.fade-in-leave-to {
	opacity: 0;
}
.prompt-inner-info {
    background-color: #F8F7FA;
    border-radius: 0 0 10px 10px;
    padding: 20px 20px;
}

.prompt-user-text {
    width: 93%;
    padding-left: 20px;
}
.prompt-user-text h3 {
    color: #7367F0;
    font-size: 13px;
    margin: 0;
}
.prompt-user-text p {
    color: #4B465C;
    font-size: 13px;
    margin: 0;
}
.prompt-user-control {
    width: 38px;
    min-width: 38px;
    height: 38px;
    overflow: hidden;
    text-align: center;
    display: flex;
    align-items: center;
    cursor: pointer;
}
.prompt-user-control.mobile{
  display: none;
}
.prompt-user-control.left{
  margin-right: 15px;
}
.prompt-user-control.right{
  margin-left: 15px;
}
.prompt-user-img {
    width: 38px;
    height: auto;
    border-radius: 50%;
    overflow: hidden;
}
.prompt-user-img.no-cite{
  width: 20px;
  height: 20px;
}
.prompt-user-img img {
    width: 100%;
    background-color: transparent;
}
.prompt-user-text {
    width: 93%;
    padding-left: 20px;
}
.prompt-user-text h3 {
    color: #7367F0;
    font-size: 13px;
    margin: 0;
}
.prompt-user-text p {
    color: #4B465C;
    font-size: 13px;
    margin: 0;
}
.cite-link{
  color: inherit;
  text-decoration: underline;
}
.sources-count{
  font-family: 'Public Sans';
  font-style: normal;
  font-weight: 400;
  font-size: 11px;
  line-height: 14px;
}
.prompt-user-text {
    width: 93%;
    padding-left: 20px;
}

@media only screen and (max-width: 480px) {
  .prompt-user-control.non-mobile{
    display:none;
  }
  .prompt-user-control.mobile{
    display: flex;
  }
  .prompt-user-reply{
    flex-direction: column;
  }
  .prompt-user-reply .prompt-user-img{
    margin-bottom: 20px;
  }
  .inline-control{
    width: 100%;
    justify-content: center;
    padding-top: 20px;
  }
  .col-xs-10{
    flex: 0 0 auto;
    width: 83.33333333%;
  }
  .prompt-user-control.right{
    justify-content: end;
  }
  .prompt-user-control.left{
    justify-content: start;
  }
  .prompt-user-reply.get-citations{
    flex-direction: row;
  }
}
</style>

<style>
/* width */
::-webkit-scrollbar {
	width: 5px;
}

/* Track */
::-webkit-scrollbar-track {
	background-color: transparent;
}

/* Handle */
::-webkit-scrollbar-thumb {
	background: #ccc;
	transition: all 200ms ease-in-out;
}

/* Handle on hover */
::-webkit-scrollbar-thumb:hover {
	background: #bbb;
}
</style>
