package com.everythingiscreated.rbmsv4.tenant

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.nio.charset.StandardCharsets
import java.security.MessageDigest

class TenantOfflineQueueStore(context: Context) {
    private val appContext = context.applicationContext

    data class OfflineQueueEntry(
        val idempotencyKey: String,
        val tenantId: String,
        val queueNamespace: String,
        val operation: String,
        val payload: String,
        val status: String,
        val retryCount: Int,
        val updatedAtMillis: Long
    )

    fun queueWrite(binding: TenantBindingStore.TenantBinding, operation: String, payload: String): OfflineQueueEntry {
        val entry = OfflineQueueEntry(
            idempotencyKey = idempotencyKey(binding, operation, payload),
            tenantId = binding.tenantId,
            queueNamespace = binding.queueNamespace,
            operation = operation,
            payload = payload,
            status = "QUEUED",
            retryCount = 0,
            updatedAtMillis = System.currentTimeMillis()
        )
        upsert(binding, entry)
        return entry
    }

    fun queueMediaUpload(binding: TenantBindingStore.TenantBinding, mediaPath: String): OfflineQueueEntry {
        require(mediaPath.startsWith(binding.mediaBasePath)) { "Media upload path must stay inside the tenant media partition." }
        return queueWrite(binding, "media_upload", mediaPath)
    }

    fun retryNext(binding: TenantBindingStore.TenantBinding, online: Boolean): OfflineQueueEntry? {
        val next = entries(binding).firstOrNull { it.status != "SYNCED" } ?: return null
        val updated = next.copy(
            status = if (online) "SYNCED" else "RETRY_WAITING",
            retryCount = next.retryCount + 1,
            updatedAtMillis = System.currentTimeMillis()
        )
        upsert(binding, updated)
        return updated
    }

    fun entries(binding: TenantBindingStore.TenantBinding): List<OfflineQueueEntry> {
        val array = JSONArray(prefs(binding).getString(KEY_ENTRIES, "[]"))
        return buildList {
            for (index in 0 until array.length()) {
                val item = array.optJSONObject(index) ?: continue
                if (item.optString("tenantId") != binding.tenantId) {
                    continue
                }
                add(
                    OfflineQueueEntry(
                        idempotencyKey = item.optString("idempotencyKey"),
                        tenantId = item.optString("tenantId"),
                        queueNamespace = item.optString("queueNamespace"),
                        operation = item.optString("operation"),
                        payload = item.optString("payload"),
                        status = item.optString("status"),
                        retryCount = item.optInt("retryCount"),
                        updatedAtMillis = item.optLong("updatedAtMillis")
                    )
                )
            }
        }
    }

    fun clearTenant(binding: TenantBindingStore.TenantBinding) {
        prefs(binding).edit().clear().apply()
    }

    private fun upsert(binding: TenantBindingStore.TenantBinding, entry: OfflineQueueEntry) {
        val kept = entries(binding)
            .filterNot { it.idempotencyKey == entry.idempotencyKey }
            .plus(entry)
            .sortedBy { it.updatedAtMillis }
        val array = JSONArray()
        kept.forEach { item ->
            array.put(
                JSONObject()
                    .put("idempotencyKey", item.idempotencyKey)
                    .put("tenantId", item.tenantId)
                    .put("queueNamespace", item.queueNamespace)
                    .put("operation", item.operation)
                    .put("payload", item.payload)
                    .put("status", item.status)
                    .put("retryCount", item.retryCount)
                    .put("updatedAtMillis", item.updatedAtMillis)
            )
        }
        prefs(binding).edit().putString(KEY_ENTRIES, array.toString()).apply()
    }

    private fun prefs(binding: TenantBindingStore.TenantBinding) =
        appContext.getSharedPreferences("builderx_offline_queue_${binding.tenantId}", Context.MODE_PRIVATE)

    private fun idempotencyKey(binding: TenantBindingStore.TenantBinding, operation: String, payload: String): String {
        val input = "${binding.tenantId}:${binding.queueNamespace}:$operation:$payload"
        return MessageDigest.getInstance("SHA-256")
            .digest(input.toByteArray(StandardCharsets.UTF_8))
            .joinToString(separator = "") { byte -> "%02x".format(byte) }
    }

    companion object {
        private const val KEY_ENTRIES = "offline_queue_entries"
    }
}
