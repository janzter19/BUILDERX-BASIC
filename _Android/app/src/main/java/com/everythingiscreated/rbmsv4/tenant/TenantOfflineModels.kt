package com.everythingiscreated.rbmsv4.tenant

import java.net.URI
import java.security.MessageDigest
import java.util.Locale

private fun stableHash(value: String): String {
    val digest = MessageDigest.getInstance("SHA-256").digest(value.toByteArray(Charsets.UTF_8))
    return digest.joinToString("") { "%02x".format(it.toInt() and 0xff) }.take(24)
}

enum class TenantOfflineStatus {
    QUEUED,
    RETRYING,
    COMPLETED,
    FAILED,
    CONFLICT
}

data class TenantOfflineScope(
    val tenantId: String,
    val branchKey: String,
    val projectKey: String,
    val cacheNamespace: String,
    val queueNamespace: String,
    val mediaBasePath: String
) {
    val stableScopeKey: String
        get() = stableHash(listOf(tenantId, branchKey, projectKey).joinToString(":"))
}

data class TenantQueuedMutation(
    val idempotencyKey: String,
    val operationType: String,
    val payloadJson: String,
    val createdAtMillis: Long,
    val updatedAtMillis: Long,
    val attemptCount: Int,
    val status: TenantOfflineStatus,
    val lastOutcome: String
)

data class TenantCachedRead(
    val cacheKey: String,
    val payloadJson: String,
    val refreshedAtMillis: Long,
    val stale: Boolean
)

data class TenantMediaUpload(
    val idempotencyKey: String,
    val mediaPath: String,
    val metadataJson: String,
    val createdAtMillis: Long,
    val updatedAtMillis: Long,
    val attemptCount: Int,
    val status: TenantOfflineStatus,
    val lastOutcome: String
)

data class TenantRetrySnapshot(
    val cachedReadCount: Int,
    val queuedMutationCount: Int,
    val retryingMutationCount: Int,
    val failedMutationCount: Int,
    val mediaOutboxCount: Int,
    val lastRetryOutcome: String
)

object TenantOfflineModels {
    fun scopeFrom(binding: TenantBindingStore.TenantBinding): TenantOfflineScope {
        return TenantOfflineScope(
            tenantId = binding.tenantId,
            branchKey = binding.branchKey,
            projectKey = binding.projectKey,
            cacheNamespace = binding.cacheNamespace,
            queueNamespace = binding.queueNamespace,
            mediaBasePath = normalizeMediaEndpoint(binding.mediaBasePath)
        )
    }

    fun idempotencyKey(scope: TenantOfflineScope, localIdentity: String): String {
        val stableIdentity = localIdentity.trim().lowercase(Locale.US)
        require(stableIdentity.isNotEmpty()) { "offline_identity_required" }
        return "tenant-offline-${scope.stableScopeKey}-${stableHash(stableIdentity)}"
    }

    fun queuedMutation(
        scope: TenantOfflineScope,
        operationType: String,
        payloadJson: String,
        localIdentity: String,
        nowMillis: Long
    ): TenantQueuedMutation {
        require(operationType.isNotBlank()) { "offline_operation_required" }
        require(payloadJson.trim().startsWith("{")) { "offline_payload_json_required" }
        return TenantQueuedMutation(
            idempotencyKey = idempotencyKey(scope, localIdentity),
            operationType = operationType.trim(),
            payloadJson = payloadJson,
            createdAtMillis = nowMillis,
            updatedAtMillis = nowMillis,
            attemptCount = 0,
            status = TenantOfflineStatus.QUEUED,
            lastOutcome = "queued"
        )
    }

    fun retryTransition(
        mutation: TenantQueuedMutation,
        nowMillis: Long,
        delivered: Boolean,
        conflict: Boolean = false,
        maxAttempts: Int = 3
    ): TenantQueuedMutation {
        val attempts = mutation.attemptCount + 1
        val status = when {
            conflict -> TenantOfflineStatus.CONFLICT
            delivered -> TenantOfflineStatus.COMPLETED
            attempts >= maxAttempts -> TenantOfflineStatus.FAILED
            else -> TenantOfflineStatus.RETRYING
        }
        val outcome = when (status) {
            TenantOfflineStatus.COMPLETED -> "completed"
            TenantOfflineStatus.CONFLICT -> "conflict"
            TenantOfflineStatus.FAILED -> "failed_after_$attempts"
            TenantOfflineStatus.RETRYING -> "retrying_attempt_$attempts"
            TenantOfflineStatus.QUEUED -> "queued"
        }
        return mutation.copy(
            updatedAtMillis = nowMillis,
            attemptCount = attempts,
            status = status,
            lastOutcome = outcome
        )
    }

    fun mediaUpload(
        scope: TenantOfflineScope,
        mediaPath: String,
        metadataJson: String,
        localIdentity: String,
        nowMillis: Long
    ): TenantMediaUpload {
        val normalizedMediaPath = normalizeMediaEndpoint(mediaPath)
        val normalizedBasePath = normalizeMediaEndpoint(scope.mediaBasePath).trimEnd('/')
        require(
            normalizedMediaPath == normalizedBasePath || normalizedMediaPath.startsWith("$normalizedBasePath/")
        ) { "cross_tenant_media_path" }
        require(metadataJson.trim().startsWith("{")) { "offline_media_metadata_json_required" }
        return TenantMediaUpload(
            idempotencyKey = idempotencyKey(scope, "media:$localIdentity"),
            mediaPath = normalizedMediaPath,
            metadataJson = metadataJson,
            createdAtMillis = nowMillis,
            updatedAtMillis = nowMillis,
            attemptCount = 0,
            status = TenantOfflineStatus.QUEUED,
            lastOutcome = "queued"
        )
    }

    fun normalizeRelativePath(value: String): String {
        val normalized = value.trim().replace('\\', '/').trim('/')
        require(
            normalized.isNotEmpty()
                && !normalized.contains(":")
                && normalized.none { it.code < 32 || it.code == 127 }
                && normalized.split('/').none { it == "." || it == ".." }
        ) { "unsafe_relative_path" }
        return normalized
    }

    fun normalizeMediaEndpoint(value: String): String {
        val trimmed = value.trim().replace('\\', '/').trimEnd('/')
        require(trimmed.isNotEmpty()) { "unsafe_media_endpoint" }
        val uri = runCatching { URI(trimmed) }.getOrNull()
        if (uri?.scheme == "http" || uri?.scheme == "https") {
            require(
                !uri.host.isNullOrBlank()
                    && uri.rawPath.orEmpty().split('/').none { it == "." || it == ".." }
                    && trimmed.none { it.code < 32 || it.code == 127 }
            ) { "unsafe_media_endpoint" }
            return trimmed
        }
        return normalizeRelativePath(trimmed)
    }

    private fun stableHash(value: String): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(value.toByteArray(Charsets.UTF_8))
        return digest.joinToString("") { "%02x".format(it.toInt() and 0xff) }.take(24)
    }
}
