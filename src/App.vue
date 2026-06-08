<script setup>
import { computed, reactive, ref } from 'vue'
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

// Human labels for the OCM wire targets (blank/redirect/iframe).
const TARGET_LABELS = {
	iframe: t(APP, 'Embedded'),
	blank: t(APP, 'New tab'),
	redirect: t(APP, 'This tab'),
}

/** Parse a JSON-array string (or pass through an array) to string[]. */
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

// What this receiver can render, in preference order.
const supportedTargets = ref(loadState(APP, 'supportedTargets', ['iframe', 'blank', 'redirect']))

// Themed icon URL for a media type; the receiver picks it (OCM no longer
// ships an icon). Null falls back to a generic component icon.
function iconFor(mediaType) {
	if (!mediaType) {
		return null
	}
	return window.OC?.MimeType?.getIconUrl?.(mediaType) ?? null
}

function normalise(share) {
	const shareTargets = asList(share.targets)
	// Offer only targets both ends support, preserving our preference order.
	const available = supportedTargets.value.filter((tg) => shareTargets.includes(tg))
	return {
		...share,
		permissionList: asList(share.permissions),
		availableTargets: available.length ? available : ['redirect'],
		icon: iconFor(share.mediaType),
	}
}

const shares = ref(loadState(APP, 'shares', []).map(normalise))
const busyId = ref(null)
// Per-share chosen target, keyed by share id; defaults to the first
// available target for that share.
const chosenTarget = reactive({})
shares.value.forEach((s) => { chosenTarget[s.id] = s.availableTargets[0] })

const hasShares = computed(() => shares.value.length > 0)

function senderOf(share) {
	return share.remoteSharedBy || share.remoteOwner || ''
}

function targetOptions(share) {
	return share.availableTargets.map((value) => ({ value, label: TARGET_LABELS[value] ?? value }))
}

function selectedOption(share) {
	const value = chosenTarget[share.id] ?? share.availableTargets[0]
	return { value, label: TARGET_LABELS[value] ?? value }
}

function onTargetChange(share, option) {
	chosenTarget[share.id] = option?.value ?? share.availableTargets[0]
}

function launch(share) {
	const target = chosenTarget[share.id] ?? share.availableTargets[0]
	const url = generateUrl('/apps/{app}/ocm/open/{token}?target={target}', {
		app: APP,
		token: share.token,
		target,
	})
	if (target === 'blank') {
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
			chosenTarget[share.id] = shares.value[idx].availableTargets[0]
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
</script>

<template>
	<NcContent app-name="ocmremotewebapp">
		<NcAppContent>
			<div :class="$style.wrapper">
				<h2>{{ t('ocmremotewebapp', 'Remote web app shares') }}</h2>

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
							<img v-if="share.icon" :src="share.icon" alt="" :class="$style.iconImg">
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
								<NcSelect
									v-if="share.availableTargets.length > 1"
									:class="$style.targetSelect"
									:options="targetOptions(share)"
									:model-value="selectedOption(share)"
									label="label"
									:clearable="false"
									:searchable="false"
									:aria-label-combobox="t('ocmremotewebapp', 'Open in')"
									@update:model-value="(opt) => onTargetChange(share, opt)" />
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
	max-width: 820px;
	margin: 0 auto;
	padding: 16px;
	width: 100%;
}

.list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 16px;
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
	align-items: center;
	gap: 8px;
	flex: 0 0 auto;
}

.targetSelect {
	min-width: 140px;
}
</style>
