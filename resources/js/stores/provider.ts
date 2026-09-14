import { ref } from "vue";
import { defineStore } from "pinia";



export const useProviderStore = defineStore('provider-store', () => {
  const providers = ref<string[]>([])
  const selected = ref<string>('')

  async function fetchProviders() {
    providers.value = [
      'DatabaseProvider',
      'DocumentProvider',
      'UserProvider'
    ]
  }

  function setSelectedProvider(provider: string) {
    selected.value = provider
  }


  return {
    providers,
    selected,
    fetchProviders,
    setSelectedProvider
  }
})
