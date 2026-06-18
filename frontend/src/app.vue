<template>
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>

  <!-- PWA: update toast + install banner (rendered outside the layout stack) -->
  <PwaPwaToast />
  <PwaInstallBanner />
</template>
