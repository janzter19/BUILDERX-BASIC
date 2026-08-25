package com.everythingiscreated.rbmsv4.tenant

import android.content.Context
import android.content.SharedPreferences
import org.json.JSONArray
import org.json.JSONObject

class TenantOfflineStore(
    context: Context,
    private val bindingStore: TenantBindingStore
) {
    private val prefs: SharedPreferences =
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun cacheRead(cacheKey: String, payloadJson: String, nowMillis: Long = System.currentTimeMillis()): TenantCachedRead {
        require(payloadJson.trim().startsWith("{")) { "offline_cache_payload_json_required" }
        val binding = requireBinding()
        val normalizedCacheKey = binding.cacheNamespace + ":" + cacheKey.trim()
        val cachedRead = TenantCachedRead(
            cacheKey = normalizedCacheKey,
            payloadJson = payloadJson,
            refreshedAtMillis = nowMillis,
            stale = false
        )
        prefs.edit()
            .putString(normalizedCacheKey, cachedRead.toJson().toString())
            .apply()
        return cachedRead
    }

    fun onlineFirstRead(cacheKey: String, fetchOnline: () -> String): TenantCachedRead {
        return runCatching {
            cacheRead(cacheKey, fetchOnline())
        }.getOrElse {
            cachedRead(cacheKey, stale = true) ?: throw it
        }
    }

    fun cachedRead(cacheKey: String, stale: Boolean = true): TenantCachedRead? {
        val binding = requireBinding()
        val normalizedCacheKey = binding.cacheNamespace + ":" + cacheKey.trim()
        val raw = prefs.getString(normalizedCacheKey, null) ?: return null
        return TenantOfflineJson.cachedReadFromJson(JSONObject(raw)).copy(stale = stale)
    }

    fun queueMutation(
        operationType: String,
        payloadJson: String,
        localIdentity: String,
        nowMillis: Long = System.currentTimeMillis()
    ): TenantQueuedMutation {
        val scope = TenantOfflineModels.scopeFrom(requireBinding())
        val mutation = TenantOfflineModels.queuedMutation(scope, operationType, payloadJson, localIdentity, nowMillis)
        val mutations = readMutationArray(scope.queueNamespace)
        val existingIndex = mutations.indexOfFirst { it.idempotencyKey == mutation.idempotencyKey }
        val updated = if (existingIndex >= 0) {
            mutations.toMutableList().apply { this[existingIndex] = mutation }
        } else {
            mutations + mutation
        }
        writeMutationArray(scope.queueNamespace, updated)
        return mutation
    }

    fun queueMediaUpload(
        mediaPath: String,
        metadataJson: String,
        localIdentity: String,
        nowMillis: Long = System.currentTimeMillis()
    ): TenantMediaUpload {
        val scope = TenantOfflineModels.scopeFrom(requireBinding())
        val upload = TenantOfflineModels.mediaUpload(scope, mediaPath, metadataJson, localIdentity, nowMillis)
        val uploads = readMediaArray(scope.queueNamespace)
        val existingIndex = uploads.indexOfFirst { it.idempotencyKey == upload.idempotencyKey }
        val updated = if (existingIndex >= 0) {
            uploads.toMutableList().apply { this[existingIndex] = upload }
        } else {
            uploads + upload
        }
        writeMediaArray(scope.queueNamespace, updated)
        return upload
    }

    fun markNextRetryAttempt(nowMillis: Long = System.currentTimeMillis()): TenantQueuedMutation? {
        val scope = TenantOfflineModels.scopeFrom(requireBinding())
        val mutations = readMutationArray(scope.queueNamespace)
        val candidate = mutations.firstOrNull {
            it.status == TenantOfflineStatus.QUEUED || it.status == TenantOfflineStatus.RETRYING
        } ?: return null
        val retried = TenantOfflineModels.retryTransition(candidate, nowMillis, delivered = false)
        writeMutationArray(
            scope.queueNamespace,
            mutations.map { if (it.idempotencyKey == candidate.idempotencyKey) retried else it }
        )
        return retried
    }

    fun snapshot(): TenantRetrySnapshot {
        val binding = requireBinding()
        val cacheCount = prefs.all.keys.count { it.startsWith("${binding.cacheNamespace}:") }
        val mutations = readMutationArray(binding.queueNamespace)
        val uploads = readMediaArray(binding.queueNamespace)
        val lastOutcome = (mutations.maxByOrNull { it.updatedAtMillis }?.lastOutcome
            ?: uploads.maxByOrNull { it.updatedAtMillis }?.lastOutcome
            ?: "no retries yet")
        return TenantRetrySnapshot(
            cachedReadCount = cacheCount,
            queuedMutationCount = mutations.count { it.status == TenantOfflineStatus.QUEUED },
            retryingMutationCount = mutations.count { it.status == TenantOfflineStatus.RETRYING },
            failedMutationCount = mutations.count { it.status == TenantOfflineStatus.FAILED || it.status == TenantOfflineStatus.CONFLICT },
            mediaOutboxCount = uploads.count { it.status == TenantOfflineStatus.QUEUED || it.status == TenantOfflineStatus.RETRYING },
            lastRetryOutcome = lastOutcome
        )
    }

    fun purgeCurrentTenantPartitions() {
        val binding = bindingStore.currentBinding() ?: return
        prefs.edit().apply {
            prefs.all.keys
                .filter { it.startsWith("${binding.cacheNamespace}:") || it.startsWith("${binding.queueNamespace}:") }
                .forEach { remove(it) }
        }.apply()
    }

    private fun requireBinding(): TenantBindingStore.TenantBinding {
        return bindingStore.currentBinding() ?: error("Tenant binding is required for offline state.")
    }

    private fun readMutationArray(queueNamespace: String): List<TenantQueuedMutation> {
        val raw = prefs.getString(mutationQueueKey(queueNamespace), null) ?: return emptyList()
        val array = JSONArray(raw)
        return List(array.length()) { index -> TenantOfflineJson.queuedMutationFromJson(array.getJSONObject(index)) }
    }

    private fun writeMutationArray(queueNamespace: String, mutations: List<TenantQueuedMutation>) {
        val array = JSONArray()
        mutations.forEach { array.put(it.toJson()) }
        prefs.edit().putString(mutationQueueKey(queueNamespace), array.toString()).apply()
    }

    private fun readMediaArray(queueNamespace: String): List<TenantMediaUpload> {
        val raw = prefs.getString(mediaQueueKey(queueNamespace), null) ?: return emptyList()
        val array = JSONArray(raw)
        return List(array.length()) { index -> TenantOfflineJson.mediaUploadFromJson(array.getJSONObject(index)) }
    }

    private fun writeMediaArray(queueNamespace: String, uploads: List<TenantMediaUpload>) {
        val array = JSONArray()
        uploads.forEach { array.put(it.toJson()) }
        prefs.edit().putString(mediaQueueKey(queueNamespace), array.toString()).apply()
    }

    private fun mutationQueueKey(queueNamespace: String): String = "$queueNamespace:mutations"

    private fun mediaQueueKey(queueNamespace: String): String = "$queueNamespace:media"

    companion object {
        private const val PREFS_NAME = "builderx_tenant_offline_state"
    }
}

private fun TenantCachedRead.toJson(): JSONObject {
    return JSONObject()
        .put("cacheKey", cacheKey)
        .put("payloadJson", payloadJson)
        .put("refreshedAtMillis", refreshedAtMillis)
        .put("stale", stale)
}

private fun TenantQueuedMutation.toJson(): JSONObject {
    return JSONObject()
        .put("idempotencyKey", idempotencyKey)
        .put("operationType", operationType)
        .put("payloadJson", payloadJson)
        .put("createdAtMillis", createdAtMillis)
        .put("updatedAtMillis", updatedAtMillis)
        .put("attemptCount", attemptCount)
        .put("status", status.name)
        .put("lastOutcome", lastOutcome)
}

private fun TenantMediaUpload.toJson(): JSONObject {
    return JSONObject()
        .put("idempotencyKey", idempotencyKey)
        .put("mediaPath", mediaPath)
        .put("metadataJson", metadataJson)
        .put("createdAtMillis", createdAtMillis)
        .put("updatedAtMillis", updatedAtMillis)
        .put("attemptCount", attemptCount)
        .put("status", status.name)
        .put("lastOutcome", lastOutcome)
}

private object TenantOfflineJson {
    fun cachedReadFromJson(json: JSONObject): TenantCachedRead {
        return TenantCachedRead(
            cacheKey = json.getString("cacheKey"),
            payloadJson = json.getString("payloadJson"),
            refreshedAtMillis = json.getLong("refreshedAtMillis"),
            stale = json.optBoolean("stale", false)
        )
    }

    fun queuedMutationFromJson(json: JSONObject): TenantQueuedMutation {
        return TenantQueuedMutation(
            idempotencyKey = json.getString("idempotencyKey"),
            operationType = json.getString("operationType"),
            payloadJson = json.getString("payloadJson"),
            createdAtMillis = json.getLong("createdAtMillis"),
            updatedAtMillis = json.getLong("updatedAtMillis"),
            attemptCount = json.getInt("attemptCount"),
            status = TenantOfflineStatus.valueOf(json.getString("status")),
            lastOutcome = json.getString("lastOutcome")
        )
    }

    fun mediaUploadFromJson(json: JSONObject): TenantMediaUpload {
        return TenantMediaUpload(
            idempotencyKey = json.getString("idempotencyKey"),
            mediaPath = json.getString("mediaPath"),
            metadataJson = json.getString("metadataJson"),
            createdAtMillis = json.getLong("createdAtMillis"),
            updatedAtMillis = json.getLong("updatedAtMillis"),
            attemptCount = json.getInt("attemptCount"),
            status = TenantOfflineStatus.valueOf(json.getString("status")),
            lastOutcome = json.getString("lastOutcome")
        )
    }
}
