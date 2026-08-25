package com.everythingiscreated.rbmsv4.tenant

object TenantRuntimeBinding {
    @Volatile
    private var currentBinding: TenantBindingStore.TenantBinding? = null

    fun apply(binding: TenantBindingStore.TenantBinding) {
        currentBinding = binding
    }

    fun current(): TenantBindingStore.TenantBinding? = currentBinding

    fun clear() {
        currentBinding = null
    }
}
