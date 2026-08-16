<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { Moon, Sun } from '@lucide/vue'
import { useDark, useToggle } from '@vueuse/core'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { pages } from '@/pages/registry'
import { useNavigationStore } from '@/stores/navigation'

const isDark = useDark()
const toggleDark = useToggle(isDark)

const nav = useNavigationStore()
const { current } = storeToRefs(nav)
</script>

<template>
  <aside class="flex w-[184px] shrink-0 flex-col border-r">
    <!-- Same height as the page header beside it, so the two borders meet. -->
    <div class="flex h-14 shrink-0 items-center gap-2 border-b px-4">
      <span class="font-mono text-sm font-semibold tracking-tight">dissect</span>
      <Badge class="bg-accent text-accent-foreground font-mono text-[10px] hover:bg-accent">
        beta
      </Badge>
    </div>

    <!-- `aria-current` rather than the tablist this replaces: these are whole
         surfaces with their own address, not panels within one view, and a
         screen reader announcing them as tabs would promise a relationship
         between them that no longer exists. -->
    <nav class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto p-2">
      <button
        v-for="entry in pages"
        :key="entry.id"
        class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-left font-mono text-xs transition-colors"
        :class="
          current === entry.id
            ? 'bg-muted font-medium text-foreground'
            : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground'
        "
        :aria-current="current === entry.id ? 'page' : undefined"
        @click="nav.go(entry.id)"
      >
        <component :is="entry.icon" class="size-3.5 shrink-0" />
        {{ entry.label }}
      </button>
    </nav>

    <div class="shrink-0 border-t p-2">
      <Button
        variant="ghost"
        size="sm"
        class="w-full justify-start font-mono text-xs text-muted-foreground"
        aria-label="Toggle theme"
        @click="toggleDark()"
      >
        <Moon v-if="isDark" class="size-3.5" />
        <Sun v-else class="size-3.5" />
        {{ isDark ? 'Dark' : 'Light' }}
      </Button>
    </div>
  </aside>
</template>
