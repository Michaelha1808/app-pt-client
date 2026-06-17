<template>
  <div class="h-svh flex flex-col bg-[#F2F8F5] overflow-hidden">
    <IosStatusBar />
    <div class="flex-1 overflow-y-auto overscroll-contain">
      <slot />
    </div>
    <IosBottomNav />
    <CaloeyeFloatingChar />
    <AppToast />
  </div>
</template>
