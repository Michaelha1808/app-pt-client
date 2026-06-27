<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRoute, RouterView } from 'vue-router'
import AppLayout from '@/layouts/AppLayout.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import PwaToast from '@/components/pwa/PwaToast.vue'
import InstallBanner from '@/components/pwa/InstallBanner.vue'

const route = useRoute()
const layouts: Record<string, any> = { app: AppLayout, auth: AuthLayout, admin: AdminLayout }
const layout = computed(() => layouts[route.meta.layout as string])

const { initPush } = useNotifications()
onMounted(initPush)
</script>

<template>
  <component v-if="layout" :is="layout">
    <RouterView />
  </component>
  <RouterView v-else />
  <PwaToast />
  <InstallBanner />
</template>
