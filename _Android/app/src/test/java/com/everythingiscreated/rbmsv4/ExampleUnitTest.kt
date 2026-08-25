package com.everythingiscreated.rbmsv4

import com.everythingiscreated.rbmsv4.tenant.TenantBindingStore
import com.everythingiscreated.rbmsv4.tenant.TenantOfflineModels
import com.everythingiscreated.rbmsv4.tenant.TenantOfflineScope
import com.everythingiscreated.rbmsv4.tenant.TenantOfflineStatus
import org.junit.Test

import org.junit.Assert.*
import java.io.File

/**
 * Example local unit test, which will execute on the development machine (host).
 *
 * See [testing documentation](http://d.android.com/tools/testing).
 */
class ExampleUnitTest {
    @Test
    fun tenantConfigurationAcceptsCompleteClientAppPayload() {
        val parsed = TenantBindingStore.TenantConfigurationResolver.parseServerResponse(
            sampleTenantResponse(),
            "RBMS-VRP",
            "http://localhost/rbms.com"
        )

        assertEquals("RBMS-VRP", parsed.client.clientAppCode)
        assertEquals("RBMS VRP Demo", parsed.client.clientName)
        assertEquals("rbmsv4-vrp", parsed.firebase.projectId)
        assertEquals("https://rbmsv4-vrp-default-rtdb.asia-southeast1.firebasedatabase.app", parsed.firebase.databaseUrl)
        assertEquals("http://localhost/rbms.com/_Mobile/rbmsv4-vrp/upload-image.php", parsed.media.uploaderTargetUrl)
    }

    @Test
    fun tenantConfigurationRejectsInvalidClientAppCode() {
        assertThrows(IllegalArgumentException::class.java) {
            TenantBindingStore.TenantConfigurationResolver.parseServerResponse(
                """
                {
                  "ok": false,
                  "error": {
                    "code": "CLIENT_APP_CODE_NOT_FOUND",
                    "message": "Client app code is not active or does not exist."
                  }
                }
                """.trimIndent(),
                "RBMS-MISSING",
                "http://localhost/rbms.com"
            )
        }
    }

    @Test
    fun tenantConfigurationRejectsNonHttpsFirebaseUrl() {
        assertThrows(IllegalArgumentException::class.java) {
            TenantBindingStore.TenantConfigurationResolver.parseServerResponse(
                sampleTenantResponse().replace(
                    "https://rbmsv4-vrp-default-rtdb.asia-southeast1.firebasedatabase.app",
                    "http://rbmsv4-vrp.firebaseio.com"
                ),
                "RBMS-VRP",
                "http://localhost/rbms.com"
            )
        }
    }

    @Test
    fun tenantConfigurationPersistsFirebaseAndMediaResponseFields() {
        val parsed = TenantBindingStore.TenantConfigurationResolver.parseServerResponse(
            sampleTenantResponse(),
            "RBMS-VRP",
            "http://localhost/rbms.com"
        )
        val restored = TenantBindingStore.TenantConfigurationResolver.configurationFromJson(
            TenantBindingStore.TenantConfigurationResolver.configurationToJson(parsed)
        )

        assertEquals("sample-vrp-api-key", restored.firebase.apiKey)
        assertEquals("1:100000000001:android:rbmsv4vrp001", restored.firebase.appId)
        assertEquals("100000000001", restored.firebase.messagingSenderId)
        assertEquals("http://localhost/rbms.com/_Mobile/rbmsv4-vrp/", restored.media.baseUrl)
        assertEquals("http://localhost/rbms.com/_Mobile/rbmsv4-vrp/view.php", restored.media.imageViewerUrl)
        assertTrue(restored.android.releaseAcknowledgementRequired)
        assertTrue(restored.android.offlineQueueEnabled)
    }

    @Test
    fun tenantDashboardExposesReadBackActionsWithoutCommonTaskWrites() {
        val layout = File("src/main/res/layout/fragment_second.xml").readText()
        val fragment = File("src/main/java/com/everythingiscreated/rbmsv4/ui/fragment/SecondFragment.kt").readText()

        listOf(
            "@+id/dashboard_assignments_value",
            "@+id/button_common_task",
            "@+id/button_stage_done",
            "@+id/button_stage_blocked",
            "@+id/button_chat",
            "@+id/button_media",
            "@+id/button_account",
            "@+id/button_release_prompt",
            "@+id/button_geofence",
            "@+id/dashboard_action_feedback"
        ).forEach { requiredId ->
            assertTrue("Missing dashboard control $requiredId", layout.contains(requiredId))
        }

        assertTrue(fragment.contains("binding.buttonCommonTask.isEnabled = false"))
        assertTrue(fragment.contains("tenantBindingStore.tenantCacheKey(\"assigned_tasks\")"))
        assertTrue(fragment.contains("tenantBindingStore.tenantQueueKey(\"stage_responses\")"))
        assertFalse(fragment.contains("setOnClickListener {\n            tenantBindingStore.bind"))
    }

    @Test
    fun offlineQueueKeepsStableTenantScopedIdempotencyKeys() {
        val scope = TenantOfflineScope(
            tenantId = "tenant-hsp-001",
            branchKey = "branch-main",
            projectKey = "project-stockroom",
            cacheNamespace = "tenant:TENANT:BRANCH:PROJECT:cache",
            queueNamespace = "tenant:TENANT:BRANCH:PROJECT:queue",
            mediaBasePath = "tenant-media/HSP001"
        )

        val first = TenantOfflineModels.queuedMutation(scope, "stage_done", "{\"ok\":true}", "stage:001", 1000L)
        val second = TenantOfflineModels.queuedMutation(scope, "stage_done", "{\"ok\":true}", "stage:001", 2000L)

        assertEquals(first.idempotencyKey, second.idempotencyKey)
        assertEquals(TenantOfflineStatus.QUEUED, first.status)
        assertTrue(first.idempotencyKey.startsWith("tenant-offline-"))
    }

    @Test
    fun offlineRetryTransitionsExposeVisibleStatus() {
        val scope = TenantOfflineScope(
            tenantId = "tenant-hsp-001",
            branchKey = "branch-main",
            projectKey = "project-stockroom",
            cacheNamespace = "tenant:TENANT:BRANCH:PROJECT:cache",
            queueNamespace = "tenant:TENANT:BRANCH:PROJECT:queue",
            mediaBasePath = "tenant-media/HSP001"
        )
        val queued = TenantOfflineModels.queuedMutation(scope, "stage_done", "{\"ok\":true}", "stage:001", 1000L)

        val retrying = TenantOfflineModels.retryTransition(queued, 2000L, delivered = false)
        val completed = TenantOfflineModels.retryTransition(retrying, 3000L, delivered = true)

        assertEquals(TenantOfflineStatus.RETRYING, retrying.status)
        assertEquals("retrying_attempt_1", retrying.lastOutcome)
        assertEquals(TenantOfflineStatus.COMPLETED, completed.status)
        assertEquals("completed", completed.lastOutcome)
    }

    @Test
    fun mediaOutboxRejectsCrossTenantPaths() {
        val scope = TenantOfflineScope(
            tenantId = "tenant-hsp-001",
            branchKey = "branch-main",
            projectKey = "project-stockroom",
            cacheNamespace = "tenant:TENANT:BRANCH:PROJECT:cache",
            queueNamespace = "tenant:TENANT:BRANCH:PROJECT:queue",
            mediaBasePath = "tenant-media/HSP001"
        )

        TenantOfflineModels.mediaUpload(
            scope,
            "tenant-media/HSP001/stage-photo.jpg",
            "{\"kind\":\"stage-photo\"}",
            "photo-001",
            1000L
        )

        assertThrows(IllegalArgumentException::class.java) {
            TenantOfflineModels.mediaUpload(
                scope,
                "tenant-media/HSP002/stage-photo.jpg",
                "{\"kind\":\"stage-photo\"}",
                "photo-001",
                1000L
            )
        }
    }

    @Test
    fun mediaOutboxAcceptsTenantResponseUrls() {
        val scope = TenantOfflineScope(
            tenantId = "RBMS-VRP",
            branchKey = "RBMS-VRP",
            projectKey = "rbmsv4-vrp",
            cacheNamespace = "client:RBMS-VRP:rbmsv4-vrp:cache",
            queueNamespace = "client:RBMS-VRP:rbmsv4-vrp:queue",
            mediaBasePath = "http://localhost/rbms.com/_Mobile/rbmsv4-vrp/"
        )

        val upload = TenantOfflineModels.mediaUpload(
            scope,
            "http://localhost/rbms.com/_Mobile/rbmsv4-vrp/dashboard-preview",
            "{\"kind\":\"dashboard-preview\"}",
            "photo-001",
            1000L
        )

        assertEquals("http://localhost/rbms.com/_Mobile/rbmsv4-vrp/dashboard-preview", upload.mediaPath)
        assertThrows(IllegalArgumentException::class.java) {
            TenantOfflineModels.mediaUpload(
                scope,
                "http://localhost/rbms.com/_Mobile/rbmsv4-cab/dashboard-preview",
                "{\"kind\":\"dashboard-preview\"}",
                "photo-001",
                1000L
            )
        }
    }

    private fun sampleTenantResponse(): String {
        return """
            {
              "ok": true,
              "client": {
                "client_app_code": "RBMS-VRP",
                "client_name": "RBMS VRP Demo",
                "status": "ACTIVE"
              },
              "android": {
                "package_name": "com.everythingiscreated.rbmsv4",
                "current_version_code": 1,
                "min_supported_version_code": 1,
                "force_update": false,
                "release_acknowledgement_required": true,
                "geofence_required": false,
                "offline_queue_enabled": true,
                "offline_retry_interval_seconds": 300,
                "dashboard_refresh_seconds": 60,
                "media_upload_enabled": false,
                "apk_download_url": "/downloads/rbmsv4-latest.apk",
                "splash_images": [
                  "http://localhost/rbms.com/_Mobile/rbmsv4-vrp/splash/splash-1.jpg"
                ]
              },
              "firebase": {
                "project_id": "rbmsv4-vrp",
                "database_url": "https://rbmsv4-vrp-default-rtdb.asia-southeast1.firebasedatabase.app",
                "firestore_database_id": "(default)",
                "api_key": "sample-vrp-api-key",
                "app_id": "1:100000000001:android:rbmsv4vrp001",
                "messaging_sender_id": "100000000001",
                "storage_bucket": "rbmsv4-vrp.appspot.com"
              },
              "media": {
                "base_url": "http://localhost/rbms.com/_Mobile/rbmsv4-vrp/",
                "uploader_target_url": "http://localhost/rbms.com/_Mobile/rbmsv4-vrp/upload-image.php",
                "image_viewer_url": "http://localhost/rbms.com/_Mobile/rbmsv4-vrp/view.php"
              }
            }
            """.trimIndent()
    }
}
