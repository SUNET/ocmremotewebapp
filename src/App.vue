<script setup>
import { computed, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ApplicationBracketsOutline from 'vue-material-design-icons/ApplicationBracketsOutline.vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

const APP = 'ocmremotewebapp'

/**
 * Parse a value the backend may hand us either as a JSON array string
 * (e.g. '["read"]') or already as an array. Returns a string[].
 */
function asList(value) {
	if (Array.isArray(value)) {
		return value
	}
	if (typeof value === 'string' && value.trim() !== '') {
		try {
			const parsed = JSON.parse(value)
			return Array.isArray(parsed) ? parsed : [value]
		} catch (e) {
			return [value]
		}
	}
	return []
}

function normalise(share) {
	return {
		...share,
		permissionList: asList(share.permissions),
		targetList: asList(share.targets),
	}
}

const shares = ref(loadState(APP, 'shares', []).map(normalise))
const displayModes = ref(loadState(APP, 'displayModes', ['iframe', 'popup', 'redirect']))
const displayMode = ref(loadState(APP, 'displayMode', 'iframe'))
const busyId = ref(null)

const hasShares = computed(() => shares.value.length > 0)

function senderOf(share) {
	return share.remoteSharedBy || share.remoteOwner || ''
}

function openUrl(share) {
	return generateUrl('/apps/{app}/ocm/open/{token}', { app: APP, token: share.token })
}

function launch(share) {
	const url = openUrl(share)
	// The server renders the right surface (iframe/popup/redirect) for the
	// stored display mode. Popups want a fresh tab; the others take over the
	// current one.
	if (displayMode.value === 'popup') {
		window.open(url, '_blank', 'noopener')
	} else {
		window.location.href = url
	}
}

async function accept(share) {
	busyId.value = share.id
	try {
		const { data } = await axios.post(
			generateUrl('/apps/{app}/api/v1/shares/{id}/accept', { app: APP, id: share.id }),
		)
		const idx = shares.value.findIndex((s) => s.id === share.id)
		if (idx !== -1) {
			shares.value.splice(idx, 1, normalise(data))
		}
		showSuccess(t(APP, 'Share accepted'))
	} catch (e) {
		console.error(e)
		showError(t(APP, 'Could not accept the share'))
	} finally {
		busyId.value = null
	}
}

async function decline(share) {
	busyId.value = share.id
	try {
		await axios.post(
			generateUrl('/apps/{app}/api/v1/shares/{id}/decline', { app: APP, id: share.id }),
		)
		shares.value = shares.value.filter((s) => s.id !== share.id)
		showSuccess(t(APP, 'Share removed'))
	} catch (e) {
		console.error(e)
		showError(t(APP, 'Could not remove the share'))
	} finally {
		busyId.value = null
	}
}

async function onDisplayModeChange(mode) {
	if (!mode || mode === displayMode.value) {
		return
	}
	const previous = displayMode.value
	displayMode.value = mode
	try {
		await axios.put(generateUrl('/apps/{app}/api/v1/config/display-mode', { app: APP }), {
			displayMode: mode,
		})
	} catch (e) {
		console.error(e)
		displayMode.value = previous
		showError(t(APP, 'Could not save the display mode'))
	}
}
</script>

<template>
	<NcContent app-name="ocmremotewebapp">
		<NcAppContent>
			<div :class="$style.wrapper">
				<div :class="$style.header">
					<h2>{{ t('ocmremotewebapp', 'Remote web app shares') }}</h2>
					<div :class="$style.modePicker">
						<label :for="'ocmrw-display-mode'">{{ t('ocmremotewebapp', 'Open shares in') }}</label>
						<NcSelect
							input-id="ocmrw-display-mode"
							:options="displayModes"
							:model-value="displayMode"
							:clearable="false"
							:searchable="false"
							@update:model-value="onDisplayModeChange" />
					</div>
				</div>

				<NcEmptyContent
					v-if="!hasShares"
					:name="t('ocmremotewebapp', 'No shared web apps')"
					:description="t('ocmremotewebapp', 'Web app shares from other servers will appear here.')">
					<template #icon>
						<ApplicationBracketsOutline :size="20" />
					</template>
				</NcEmptyContent>

				<ul v-else :class="$style.list">
					<li v-for="share in shares" :key="share.id" :class="$style.item">
						<span :class="$style.icon">
							<img v-if="share.appIcon" :src="share.appIcon" alt="" :class="$style.iconImg">
							<ApplicationBracketsOutline v-else :size="32" />
						</span>
						<div :class="$style.meta">
							<span :class="$style.title">{{ share.appName || share.resourceName }}</span>
							<span :class="$style.subtitle">
								{{ t('ocmremotewebapp', 'Shared by {sender}', { sender: senderOf(share) }) }}
							</span>
							<span :class="$style.badges">
								<span :class="$style.badge">{{ share.permissionList.join(', ') || 'read' }}</span>
								<span :class="[$style.badge, share.state === 'accepted' ? $style.badgeOk : '']">
									{{ share.state }}
								</span>
							</span>
						</div>
						<div :class="$style.actions">
							<template v-if="share.state === 'accepted'">
								<NcButton
									type="primary"
									:disabled="busyId === share.id"
									@click="launch(share)">
									<template #icon>
										<OpenInNew :size="20" />
									</template>
									{{ t('ocmremotewebapp', 'Open') }}
								</NcButton>
								<NcButton
									type="tertiary"
									:disabled="busyId === share.id"
									@click="decline(share)">
									<template #icon>
										<Close :size="20" />
									</template>
									{{ t('ocmremotewebapp', 'Remove') }}
								</NcButton>
							</template>
							<template v-else>
								<NcButton
									type="primary"
									:disabled="busyId === share.id"
									@click="accept(share)">
									<template #icon>
										<Check :size="20" />
									</template>
									{{ t('ocmremotewebapp', 'Accept') }}
								</NcButton>
								<NcButton
									type="tertiary"
									:disabled="busyId === share.id"
									@click="decline(share)">
									<template #icon>
										<Close :size="20" />
									</template>
									{{ t('ocmremotewebapp', 'Decline') }}
								</NcButton>
							</template>
						</div>
					</li>
				</ul>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style module>
.wrapper {
	max-width: 800px;
	margin: 0 auto;
	padding: 16px;
	width: 100%;
}

.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.modePicker {
	display: flex;
	align-items: center;
	gap: 8px;
}

.modePicker label {
	color: var(--color-text-maxcontrast);
}

.list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.item {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	flex: 0 0 40px;
	color: var(--color-text-maxcontrast);
}

.iconImg {
	width: 32px;
	height: 32px;
	object-fit: contain;
}

.meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1 1 auto;
	min-width: 0;
}

.title {
	font-weight: 600;
}

.subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.badges {
	display: flex;
	gap: 6px;
	margin-top: 4px;
}

.badge {
	font-size: 0.8em;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.badgeOk {
	background: var(--color-success);
	color: var(--color-primary-element-text, #fff);
}

.actions {
	display: flex;
	gap: 8px;
	flex: 0 0 auto;
}
</style>
