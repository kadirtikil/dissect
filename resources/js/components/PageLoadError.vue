<script setup lang="ts">
import { Button } from '@/components/ui/button'

/**
 * Shown in place of a page whose code could not be fetched.
 *
 * Pages are split into chunks that load the first time somebody opens them. If
 * the package was updated while this tab was open, the chunk the old entry asks
 * for no longer exists — and without this the page area simply stays empty,
 * with the reason only in the console.
 */
function reload() {
  window.location.reload()
}
</script>

<template>
  <div class="grid h-full place-items-center p-6" role="alert">
    <div class="max-w-sm text-center font-mono">
      <p class="text-sm font-semibold">This page could not be loaded.</p>
      <p class="mt-2 text-xs text-muted-foreground">
        Usually dissect was updated since this tab was opened, and the code it asked for has been
        replaced. Reloading fetches the current version.
      </p>
      <Button class="mt-4" size="sm" variant="outline" @click="reload">Reload</Button>
    </div>
  </div>
</template>
