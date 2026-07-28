<script setup>
import PageHeader from '../../components/common/PageHeader.vue'; import { sessionStore } from '../../stores/session.js'
const props=defineProps({title:String,description:String,legacy:String,permission:String})
const legacyUrl=`../../legacy-frontend/${props.legacy}`
</script>
<template><PageHeader :title="title" :description="description"><a v-if="!permission || sessionStore.can(permission)" class="primary-button" :href="legacyUrl">Open full workspace</a></PageHeader><section class="notice-panel"><h2>Vue migration in progress</h2><p>This module remains fully available in the existing workspace while its screens are moved into the new component-based interface. Your data and backend endpoints are unchanged.</p><a class="secondary-button" :href="legacyUrl">Continue to {{ title }}</a></section></template>
