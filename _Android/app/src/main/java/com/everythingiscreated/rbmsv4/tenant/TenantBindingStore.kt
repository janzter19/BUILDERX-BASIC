package com.everythingiscreated.rbmsv4.tenant

import android.content.Context
import android.content.SharedPreferences
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import org.json.JSONArray
import org.json.JSONObject
import java.net.URI
import java.nio.charset.StandardCharsets
import java.security.GeneralSecurityException
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

class TenantBindingStore(context: Context) {
    private val appContext = context.applicationContext
    private val prefs: SharedPreferences =
        appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    data class ClientMetadata(
        val clientAppCode: String,
        val clientName: String,
        val status: String
    )

    data class AndroidReleaseConfig(
        val packageName: String,
        val currentVersionCode: Int,
        val minSupportedVersionCode: Int,
        val forceUpdate: Boolean,
        val releaseAcknowledgementRequired: Boolean,
        val geofenceRequired: Boolean,
        val offlineQueueEnabled: Boolean,
        val offlineRetryIntervalSeconds: Int,
        val dashboardRefreshSeconds: Int,
        val mediaUploadEnabled: Boolean,
        val apkDownloadUrl: String,
        val splashImages: List<String>
    )

    data class FirebaseClientConfig(
        val projectId: String,
        val databaseUrl: String,
        val firestoreDatabaseId: String,
        val apiKey: String,
        val appId: String,
        val messagingSenderId: String,
        val storageBucket: String
    )

    data class MediaEndpoints(
        val baseUrl: String,
        val uploaderTargetUrl: String,
        val imageViewerUrl: String
    )

    data class TenantBinding(
        val client: ClientMetadata,
        val android: AndroidReleaseConfig,
        val firebase: FirebaseClientConfig,
        val media: MediaEndpoints,
        val apiBaseUrl: String,
        val verifiedAtMillis: Long,
        val cacheNamespace: String,
        val queueNamespace: String
    ) {
        val clientAppCode: String
            get() = client.clientAppCode
        val clientName: String
            get() = client.clientName
        val shortTenantId: String
            get() = clientAppCode.takeLast(8).uppercase()
        val tenantId: String
            get() = clientAppCode
        val branchKey: String
            get() = clientAppCode
        val projectKey: String
            get() = firebase.projectId
        val firebaseDatabaseUrl: String
            get() = firebase.databaseUrl
        val firebaseProjectId: String
            get() = firebase.projectId
        val firebaseApiPath: String
            get() = "/clients/$clientAppCode"
        val mediaBasePath: String
            get() = media.baseUrl
    }

    data class TenantConfiguration(
        val client: ClientMetadata,
        val android: AndroidReleaseConfig,
        val firebase: FirebaseClientConfig,
        val media: MediaEndpoints,
        val apiBaseUrl: String,
        val verifiedAtMillis: Long,
        val active: Boolean
    )

    fun currentBinding(): TenantBinding? {
        val iv = prefs.getString(KEY_PAYLOAD_IV, null)
        val ciphertext = prefs.getString(KEY_PAYLOAD_CIPHERTEXT, null)
        if (iv.isNullOrBlank() || ciphertext.isNullOrBlank()) {
            return null
        }

        return try {
            val payload = decryptPayload(iv, ciphertext)
            decodeBinding(payload)
        } catch (_: GeneralSecurityException) {
            clearBinding()
            null
        } catch (_: RuntimeException) {
            clearBinding()
            null
        }
    }

    fun bindConfiguration(configuration: TenantConfiguration): TenantBinding {
        val verifiedConfiguration = TenantConfigurationResolver.validateConfiguration(configuration)
        val payload = TenantConfigurationResolver.configurationToJson(verifiedConfiguration)

        val encrypted = encryptPayload(payload.toString())
        prefs.edit()
            .clear()
            .putString(KEY_PAYLOAD_IV, encrypted.iv)
            .putString(KEY_PAYLOAD_CIPHERTEXT, encrypted.ciphertext)
            .apply()

        return currentBinding() ?: error("Tenant binding could not be read back.")
    }

    fun refreshBinding(configuration: TenantConfiguration? = null): TenantBinding {
        if (configuration != null) {
            return bindConfiguration(configuration.copy(verifiedAtMillis = System.currentTimeMillis()))
        }

        val binding = currentBinding() ?: error("Bind a Client App Code before refreshing tenant state.")
        val payload = decryptCurrentPayload()
        val refreshedPayload = JSONObject(payload)
            .put("verifiedAtMillis", System.currentTimeMillis())

        val encrypted = encryptPayload(refreshedPayload.toString())
        prefs.edit()
            .putString(KEY_PAYLOAD_IV, encrypted.iv)
            .putString(KEY_PAYLOAD_CIPHERTEXT, encrypted.ciphertext)
            .apply()

        return currentBinding() ?: binding
    }

    fun clearBinding() {
        prefs.edit().clear().apply()
    }

    fun tenantCacheKey(baseKey: String): String {
        val binding = currentBinding() ?: error("Tenant binding is required for cache access.")
        return "${binding.cacheNamespace}:${stableKeyPart(baseKey)}"
    }

    fun tenantQueueKey(baseKey: String): String {
        val binding = currentBinding() ?: error("Tenant binding is required for queue access.")
        return "${binding.queueNamespace}:${stableKeyPart(baseKey)}"
    }

    private fun decodeBinding(payload: String): TenantBinding? {
        val json = JSONObject(payload)
        if (json.optInt("v") != PAYLOAD_VERSION) {
            return null
        }

        val configuration = TenantConfigurationResolver.configurationFromJson(json)
        if (configuration.verifiedAtMillis <= 0L) {
            return null
        }

        val verifiedConfiguration = TenantConfigurationResolver.validateConfiguration(configuration)
        return TenantBinding(
            client = verifiedConfiguration.client,
            android = verifiedConfiguration.android,
            firebase = verifiedConfiguration.firebase,
            media = verifiedConfiguration.media,
            apiBaseUrl = verifiedConfiguration.apiBaseUrl,
            verifiedAtMillis = verifiedConfiguration.verifiedAtMillis,
            cacheNamespace = namespaceFor(verifiedConfiguration, "cache"),
            queueNamespace = namespaceFor(verifiedConfiguration, "queue")
        )
    }

    private fun decryptCurrentPayload(): String {
        val iv = prefs.getString(KEY_PAYLOAD_IV, null)
        val ciphertext = prefs.getString(KEY_PAYLOAD_CIPHERTEXT, null)
        require(!iv.isNullOrBlank() && !ciphertext.isNullOrBlank()) {
            "Tenant binding is unavailable."
        }
        return decryptPayload(iv, ciphertext)
    }

    private fun encryptPayload(payload: String): EncryptedPayload {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, secretKey())
        val ciphertext = cipher.doFinal(payload.toByteArray(StandardCharsets.UTF_8))
        return EncryptedPayload(
            iv = Base64.encodeToString(cipher.iv, Base64.NO_WRAP),
            ciphertext = Base64.encodeToString(ciphertext, Base64.NO_WRAP)
        )
    }

    private fun decryptPayload(iv: String, ciphertext: String): String {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        val spec = GCMParameterSpec(GCM_TAG_LENGTH_BITS, Base64.decode(iv, Base64.NO_WRAP))
        cipher.init(Cipher.DECRYPT_MODE, secretKey(), spec)
        val plaintext = cipher.doFinal(Base64.decode(ciphertext, Base64.NO_WRAP))
        return String(plaintext, StandardCharsets.UTF_8)
    }

    private fun secretKey(): SecretKey {
        val keyStore = KeyStore.getInstance(ANDROID_KEY_STORE).apply { load(null) }
        val existing = keyStore.getEntry(KEY_ALIAS, null) as? KeyStore.SecretKeyEntry
        if (existing != null) {
            return existing.secretKey
        }

        val keyGenerator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEY_STORE)
        val spec = KeyGenParameterSpec.Builder(
            KEY_ALIAS,
            KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
        )
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setRandomizedEncryptionRequired(true)
            .build()
        keyGenerator.init(spec)
        return keyGenerator.generateKey()
    }

    private data class EncryptedPayload(
        val iv: String,
        val ciphertext: String
    )

    object TenantConfigurationResolver {
        fun parseServerResponse(
            responseJson: String,
            expectedClientAppCode: String,
            fallbackApiBaseUrl: String
        ): TenantConfiguration {
            val root = JSONObject(responseJson)
            require(root.optBoolean("ok", false)) { serverErrorMessage(root) }

            val clientJson = root.optJSONObject("client")
                ?: throw IllegalArgumentException("tenant_client_missing")
            val androidJson = root.optJSONObject("android")
                ?: throw IllegalArgumentException("tenant_android_missing")
            val firebaseJson = root.optJSONObject("firebase")
                ?: throw IllegalArgumentException("tenant_firebase_missing")
            val mediaJson = root.optJSONObject("media")
                ?: throw IllegalArgumentException("tenant_media_missing")

            val normalizedExpected = normalizeClientAppCode(expectedClientAppCode)
            val clientAppCode = normalizeClientAppCode(firstString(clientJson, "client_app_code", "clientAppCode"))
            require(clientAppCode.isNotEmpty()) { "client_app_code_required" }
            require(normalizedExpected.isEmpty() || clientAppCode == normalizedExpected) { "client_app_code_mismatch" }

            return validateConfiguration(
                TenantConfiguration(
                    client = ClientMetadata(
                        clientAppCode = clientAppCode,
                        clientName = firstString(clientJson, "client_name", "clientName"),
                        status = firstString(clientJson, "status", "client_app_status", "clientAppStatus").uppercase()
                    ),
                    android = AndroidReleaseConfig(
                        packageName = firstString(androidJson, "package_name", "packageName"),
                        currentVersionCode = androidJson.optInt("current_version_code", 0),
                        minSupportedVersionCode = androidJson.optInt("min_supported_version_code", 0),
                        forceUpdate = androidJson.optBoolean("force_update", false),
                        releaseAcknowledgementRequired = androidJson.optBoolean("release_acknowledgement_required", false),
                        geofenceRequired = androidJson.optBoolean("geofence_required", false),
                        offlineQueueEnabled = androidJson.optBoolean("offline_queue_enabled", false),
                        offlineRetryIntervalSeconds = androidJson.optInt("offline_retry_interval_seconds", 0),
                        dashboardRefreshSeconds = androidJson.optInt("dashboard_refresh_seconds", 0),
                        mediaUploadEnabled = androidJson.optBoolean("media_upload_enabled", false),
                        apkDownloadUrl = firstString(androidJson, "apk_download_url", "apkDownloadUrl"),
                        splashImages = stringArray(androidJson.optJSONArray("splash_images"))
                    ),
                    firebase = FirebaseClientConfig(
                        projectId = firstString(firebaseJson, "project_id", "projectId"),
                        databaseUrl = firstString(firebaseJson, "database_url", "databaseUrl"),
                        firestoreDatabaseId = firstString(firebaseJson, "firestore_database_id", "firestoreDatabaseId"),
                        apiKey = firstString(firebaseJson, "api_key", "apiKey"),
                        appId = firstString(firebaseJson, "app_id", "appId"),
                        messagingSenderId = firstString(firebaseJson, "messaging_sender_id", "messagingSenderId"),
                        storageBucket = firstString(firebaseJson, "storage_bucket", "storageBucket")
                    ),
                    media = MediaEndpoints(
                        baseUrl = firstString(mediaJson, "base_url", "baseUrl"),
                        uploaderTargetUrl = firstString(mediaJson, "uploader_target_url", "uploaderTargetUrl"),
                        imageViewerUrl = firstString(mediaJson, "image_viewer_url", "imageViewerUrl")
                    ),
                    apiBaseUrl = fallbackApiBaseUrl,
                    verifiedAtMillis = System.currentTimeMillis(),
                    active = true
                )
            )
        }

        fun validateConfiguration(configuration: TenantConfiguration): TenantConfiguration {
            require(configuration.active) { "tenant_configuration_inactive" }
            require(configuration.client.clientAppCode.isNotBlank()) { "client_app_code_required" }
            require(configuration.client.status == "ACTIVE") { "tenant_configuration_inactive" }
            require(configuration.client.clientName.isNotBlank()) { "tenant_client_incomplete" }
            require(configuration.android.packageName == EXPECTED_ANDROID_PACKAGE_NAME) { "android_package_mismatch" }
            require(configuration.android.currentVersionCode > 0) { "android_release_config_incomplete" }
            require(configuration.android.minSupportedVersionCode > 0) { "android_release_config_incomplete" }
            require(configuration.android.offlineRetryIntervalSeconds > 0) { "android_release_config_incomplete" }
            require(configuration.android.dashboardRefreshSeconds > 0) { "android_release_config_incomplete" }
            require(configuration.firebase.projectId.isNotBlank()) { "firebase_destination_incomplete" }
            require(isValidHttpsUrl(configuration.firebase.databaseUrl)) { "firebase_database_url_must_be_https" }
            require(configuration.firebase.apiKey.isNotBlank()) { "firebase_destination_incomplete" }
            require(configuration.firebase.appId.isNotBlank()) { "firebase_destination_incomplete" }
            require(configuration.firebase.messagingSenderId.isNotBlank()) { "firebase_destination_incomplete" }
            require(configuration.firebase.storageBucket.isNotBlank()) { "firebase_destination_incomplete" }
            require(isValidNetworkUrl(configuration.media.baseUrl)) { "tenant_media_endpoint_invalid" }
            require(isValidNetworkUrl(configuration.media.uploaderTargetUrl)) { "tenant_media_endpoint_invalid" }
            require(isValidNetworkUrl(configuration.media.imageViewerUrl)) { "tenant_media_endpoint_invalid" }
            require(configuration.android.splashImages.all(::isValidNetworkUrl)) { "tenant_splash_image_invalid" }
            return configuration.copy(
                client = configuration.client.copy(
                    clientAppCode = normalizeClientAppCode(configuration.client.clientAppCode),
                    status = configuration.client.status.uppercase()
                )
            )
        }

        fun configurationToJson(configuration: TenantConfiguration): JSONObject {
            return JSONObject()
                .put("v", PAYLOAD_VERSION)
                .put("client", JSONObject()
                    .put("client_app_code", configuration.client.clientAppCode)
                    .put("client_name", configuration.client.clientName)
                    .put("status", configuration.client.status))
                .put("android", JSONObject()
                    .put("package_name", configuration.android.packageName)
                    .put("current_version_code", configuration.android.currentVersionCode)
                    .put("min_supported_version_code", configuration.android.minSupportedVersionCode)
                    .put("force_update", configuration.android.forceUpdate)
                    .put("release_acknowledgement_required", configuration.android.releaseAcknowledgementRequired)
                    .put("geofence_required", configuration.android.geofenceRequired)
                    .put("offline_queue_enabled", configuration.android.offlineQueueEnabled)
                    .put("offline_retry_interval_seconds", configuration.android.offlineRetryIntervalSeconds)
                    .put("dashboard_refresh_seconds", configuration.android.dashboardRefreshSeconds)
                    .put("media_upload_enabled", configuration.android.mediaUploadEnabled)
                    .put("apk_download_url", configuration.android.apkDownloadUrl)
                    .put("splash_images", JSONArray(configuration.android.splashImages)))
                .put("firebase", JSONObject()
                    .put("project_id", configuration.firebase.projectId)
                    .put("database_url", configuration.firebase.databaseUrl)
                    .put("firestore_database_id", configuration.firebase.firestoreDatabaseId)
                    .put("api_key", configuration.firebase.apiKey)
                    .put("app_id", configuration.firebase.appId)
                    .put("messaging_sender_id", configuration.firebase.messagingSenderId)
                    .put("storage_bucket", configuration.firebase.storageBucket))
                .put("media", JSONObject()
                    .put("base_url", configuration.media.baseUrl)
                    .put("uploader_target_url", configuration.media.uploaderTargetUrl)
                    .put("image_viewer_url", configuration.media.imageViewerUrl))
                .put("apiBaseUrl", configuration.apiBaseUrl)
                .put("verifiedAtMillis", configuration.verifiedAtMillis)
                .put("active", configuration.active)
        }

        fun configurationFromJson(json: JSONObject): TenantConfiguration {
            val clientJson = json.getJSONObject("client")
            val androidJson = json.getJSONObject("android")
            val firebaseJson = json.getJSONObject("firebase")
            val mediaJson = json.getJSONObject("media")
            return validateConfiguration(
                TenantConfiguration(
                    client = ClientMetadata(
                        clientAppCode = firstString(clientJson, "client_app_code", "clientAppCode"),
                        clientName = firstString(clientJson, "client_name", "clientName"),
                        status = firstString(clientJson, "status").uppercase()
                    ),
                    android = AndroidReleaseConfig(
                        packageName = firstString(androidJson, "package_name", "packageName"),
                        currentVersionCode = androidJson.optInt("current_version_code", 0),
                        minSupportedVersionCode = androidJson.optInt("min_supported_version_code", 0),
                        forceUpdate = androidJson.optBoolean("force_update", false),
                        releaseAcknowledgementRequired = androidJson.optBoolean("release_acknowledgement_required", false),
                        geofenceRequired = androidJson.optBoolean("geofence_required", false),
                        offlineQueueEnabled = androidJson.optBoolean("offline_queue_enabled", false),
                        offlineRetryIntervalSeconds = androidJson.optInt("offline_retry_interval_seconds", 0),
                        dashboardRefreshSeconds = androidJson.optInt("dashboard_refresh_seconds", 0),
                        mediaUploadEnabled = androidJson.optBoolean("media_upload_enabled", false),
                        apkDownloadUrl = firstString(androidJson, "apk_download_url", "apkDownloadUrl"),
                        splashImages = stringArray(androidJson.optJSONArray("splash_images"))
                    ),
                    firebase = FirebaseClientConfig(
                        projectId = firstString(firebaseJson, "project_id", "projectId"),
                        databaseUrl = firstString(firebaseJson, "database_url", "databaseUrl"),
                        firestoreDatabaseId = firstString(firebaseJson, "firestore_database_id", "firestoreDatabaseId"),
                        apiKey = firstString(firebaseJson, "api_key", "apiKey"),
                        appId = firstString(firebaseJson, "app_id", "appId"),
                        messagingSenderId = firstString(firebaseJson, "messaging_sender_id", "messagingSenderId"),
                        storageBucket = firstString(firebaseJson, "storage_bucket", "storageBucket")
                    ),
                    media = MediaEndpoints(
                        baseUrl = firstString(mediaJson, "base_url", "baseUrl"),
                        uploaderTargetUrl = firstString(mediaJson, "uploader_target_url", "uploaderTargetUrl"),
                        imageViewerUrl = firstString(mediaJson, "image_viewer_url", "imageViewerUrl")
                    ),
                    apiBaseUrl = json.optString("apiBaseUrl").trim(),
                    verifiedAtMillis = json.optLong("verifiedAtMillis"),
                    active = json.optBoolean("active", true)
                )
            )
        }

        fun normalizeClientAppCode(clientAppCode: String): String {
            return clientAppCode.trim().uppercase().replace(Regex("\\s+"), "")
        }

        fun isValidNetworkUrl(value: String): Boolean {
            return runCatching {
                val uri = URI(value.trim())
                val scheme = uri.scheme?.lowercase()
                val host = uri.host.orEmpty()
                (scheme == "https" || (scheme == "http" && isLocalDevelopmentHost(host))) && host.isNotBlank()
            }.getOrDefault(false)
        }

        private fun serverErrorMessage(root: JSONObject): String {
            val error = root.optJSONObject("error")
            return error?.optString("code")?.takeIf { it.isNotBlank() } ?: "tenant_configuration_request_failed"
        }

        private fun firstString(root: JSONObject, vararg keys: String): String {
            for (key in keys) {
                if (root.has(key)) {
                    return root.optString(key).trim()
                }
            }
            return ""
        }

        private fun stringArray(array: JSONArray?): List<String> {
            if (array == null) {
                return emptyList()
            }
            return buildList {
                for (index in 0 until array.length()) {
                    val value = array.optString(index).trim()
                    if (value.isNotEmpty()) {
                        add(value)
                    }
                }
            }
        }

        private fun isValidHttpsUrl(value: String): Boolean {
            return runCatching {
                val uri = URI(value.trim())
                uri.scheme.equals("https", ignoreCase = true) && !uri.host.isNullOrBlank()
            }.getOrDefault(false)
        }

        private fun isLocalDevelopmentHost(host: String): Boolean {
            return host.equals("localhost", ignoreCase = true) ||
                host == "127.0.0.1" ||
                host == "10.0.2.2" ||
                host == "::1"
        }
    }

    companion object {
        private const val ANDROID_KEY_STORE = "AndroidKeyStore"
        private const val GCM_TAG_LENGTH_BITS = 128
        private const val KEY_ALIAS = "builderx_rbmsv4_tenant_binding"
        private const val KEY_PAYLOAD_CIPHERTEXT = "tenant_payload_ciphertext"
        private const val KEY_PAYLOAD_IV = "tenant_payload_iv"
        private const val PAYLOAD_VERSION = 3
        private const val PREFS_NAME = "builderx_tenant_binding_state"
        private const val TRANSFORMATION = "AES/GCM/NoPadding"
        private const val EXPECTED_ANDROID_PACKAGE_NAME = "com.everythingiscreated.rbmsv4"

        fun normalizeClientAppCode(clientAppCode: String): String {
            return TenantConfigurationResolver.normalizeClientAppCode(clientAppCode)
        }

        fun normalizeHospitalCode(hospitalCode: String): String {
            return normalizeClientAppCode(hospitalCode)
        }

        private fun namespaceFor(configuration: TenantConfiguration, suffix: String): String {
            return listOf(
                "client",
                stableKeyPart(configuration.client.clientAppCode),
                stableKeyPart(configuration.firebase.projectId),
                suffix
            ).joinToString(":")
        }

        private fun stableKeyPart(baseKey: String): String {
            return normalizeClientAppCode(baseKey).ifEmpty { "default" }
        }
    }
}
