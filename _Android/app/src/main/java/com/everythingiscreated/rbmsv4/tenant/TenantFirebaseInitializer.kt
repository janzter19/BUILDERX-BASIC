package com.everythingiscreated.rbmsv4.tenant

import android.content.Context
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions

object TenantFirebaseInitializer {
    fun initialize(context: Context, binding: TenantBindingStore.TenantBinding): FirebaseApp {
        val appName = appNameFor(binding)
        FirebaseApp.getApps(context).firstOrNull { it.name == appName }?.let { return it }

        val options = FirebaseOptions.Builder()
            .setApiKey(binding.firebase.apiKey)
            .setApplicationId(binding.firebase.appId)
            .setDatabaseUrl(binding.firebase.databaseUrl)
            .setGcmSenderId(binding.firebase.messagingSenderId)
            .setProjectId(binding.firebase.projectId)
            .setStorageBucket(binding.firebase.storageBucket)
            .build()

        return FirebaseApp.initializeApp(context.applicationContext, options, appName)
            ?: error("Tenant Firebase app could not be initialized.")
    }

    fun appNameFor(binding: TenantBindingStore.TenantBinding): String {
        return "tenant-${binding.clientAppCode.lowercase().replace(Regex("[^a-z0-9_-]"), "-")}"
    }
}
