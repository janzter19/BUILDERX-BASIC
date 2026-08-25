package com.everythingiscreated.rbmsv4.ui.fragment

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.navigation.fragment.findNavController
import com.everythingiscreated.rbmsv4.R
import com.everythingiscreated.rbmsv4.databinding.FragmentSecondBinding
import com.everythingiscreated.rbmsv4.tenant.TenantBindingStore
import com.everythingiscreated.rbmsv4.tenant.TenantConfigurationClient
import com.everythingiscreated.rbmsv4.tenant.TenantFirebaseInitializer
import com.everythingiscreated.rbmsv4.tenant.TenantOfflineStore
import com.everythingiscreated.rbmsv4.tenant.TenantRuntimeBinding
import org.json.JSONObject
import java.text.DateFormat
import java.util.Date

class SecondFragment : Fragment() {

    private var _binding: FragmentSecondBinding? = null
    private lateinit var tenantBindingStore: TenantBindingStore
    private lateinit var tenantConfigurationClient: TenantConfigurationClient
    private lateinit var tenantOfflineStore: TenantOfflineStore

    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {

        _binding = FragmentSecondBinding.inflate(inflater, container, false)
        return binding.root

    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        tenantBindingStore = TenantBindingStore(requireContext())
        tenantConfigurationClient = TenantConfigurationClient(getString(R.string.tenant_configuration_endpoint_url))
        tenantOfflineStore = TenantOfflineStore(requireContext(), tenantBindingStore)
        renderTenantDashboard()

        binding.buttonRefreshTenant.setOnClickListener {
            val tenant = tenantBindingStore.currentBinding()
            if (tenant == null) {
                findNavController().navigate(R.id.action_SecondFragment_to_FirstFragment)
                return@setOnClickListener
            }

            binding.buttonRefreshTenant.isEnabled = false
            val activity = activity ?: run {
                binding.buttonRefreshTenant.isEnabled = true
                return@setOnClickListener
            }
            Thread {
                val result = runCatching {
                    val configuration = tenantConfigurationClient.fetch(tenant.clientAppCode)
                    tenantBindingStore.refreshBinding(configuration)
                }
                activity.runOnUiThread {
                    if (_binding == null) {
                        return@runOnUiThread
                    }
                    result.onSuccess { refreshed ->
                        TenantRuntimeBinding.apply(refreshed)
                        renderTenantDashboard()
                        Toast.makeText(requireContext(), R.string.tenant_binding_refreshed, Toast.LENGTH_SHORT).show()
                    }.onFailure {
                        TenantRuntimeBinding.clear()
                        findNavController().navigate(R.id.action_SecondFragment_to_FirstFragment)
                    }
                }
            }.start()
        }

        binding.buttonSwitchTenant.setOnClickListener {
            tenantOfflineStore.purgeCurrentTenantPartitions()
            tenantBindingStore.clearBinding()
            TenantRuntimeBinding.clear()
            findNavController().navigate(R.id.action_SecondFragment_to_FirstFragment)
        }

        binding.buttonCommonTask.isEnabled = false
        binding.buttonStageDone.setOnClickListener {
            renderQueuedActionFeedback("stage_done", R.string.dashboard_stage_done_feedback)
        }
        binding.buttonStageBlocked.setOnClickListener {
            renderQueuedActionFeedback("stage_blocked", R.string.dashboard_stage_blocked_feedback)
        }
        binding.buttonChat.setOnClickListener {
            renderQueuedActionFeedback("chat_preview", R.string.dashboard_chat_feedback)
        }
        binding.buttonMedia.setOnClickListener {
            renderQueuedMediaFeedback(R.string.dashboard_media_feedback)
        }
        binding.buttonAccount.setOnClickListener {
            renderQueuedActionFeedback("account_preview", R.string.dashboard_account_feedback)
        }
        binding.buttonReleasePrompt.setOnClickListener {
            renderQueuedActionFeedback("release_prompt_preview", R.string.dashboard_release_prompt_feedback)
        }
        binding.buttonGeofence.setOnClickListener {
            renderQueuedActionFeedback("geofence_preview", R.string.dashboard_geofence_feedback)
        }
        binding.buttonRetryQueue.setOnClickListener {
            retryPendingOfflineWork()
        }
    }

    private fun renderTenantDashboard() {
        val tenant = tenantBindingStore.currentBinding()
        if (tenant == null) {
            findNavController().navigate(R.id.action_SecondFragment_to_FirstFragment)
            return
        }
        TenantRuntimeBinding.apply(tenant)
        TenantFirebaseInitializer.initialize(requireContext(), tenant)
        binding.buttonRefreshTenant.isEnabled = true
        seedDashboardReadCache(tenant)

        binding.dashboardStatusValue.text = getString(
            R.string.dashboard_tenant_ready,
            tenant.shortTenantId,
            DateFormat.getDateTimeInstance(DateFormat.SHORT, DateFormat.SHORT)
                .format(Date(tenant.verifiedAtMillis))
        )
        binding.dashboardPartitionValue.text = getString(
            R.string.dashboard_partition_summary,
            tenantBindingStore.tenantCacheKey("dashboard"),
            tenantBindingStore.tenantQueueKey("pending_mutations")
        )
        binding.dashboardEndpointValue.text = getString(
            R.string.dashboard_endpoint_summary,
            tenant.apiBaseUrl,
            tenant.firebaseDatabaseUrl,
            tenant.firebaseProjectId,
            tenant.media.uploaderTargetUrl
        )
        binding.dashboardAssignmentsValue.text = getString(
            R.string.dashboard_assignments_summary,
            tenant.clientAppCode,
            tenantBindingStore.tenantCacheKey("assigned_tasks")
        )
        binding.dashboardCommonTaskValue.text = getString(
            R.string.dashboard_common_task_gate,
            tenant.projectKey
        )
        binding.dashboardStageValue.text = getString(
            R.string.dashboard_stage_summary,
            tenantBindingStore.tenantQueueKey("stage_responses")
        )
        binding.dashboardEngagementValue.text = getString(
            R.string.dashboard_engagement_summary,
            tenant.mediaBasePath,
            tenant.media.imageViewerUrl,
            tenantBindingStore.tenantQueueKey("chat_media_account")
        )
        binding.dashboardReleaseValue.text = getString(
            R.string.dashboard_release_geofence_summary,
            tenant.shortTenantId
        )
        renderOfflineSnapshot()
        binding.dashboardActionFeedback.text = getString(R.string.dashboard_feedback_ready)
    }

    private fun renderQueuedActionFeedback(operationType: String, messageResId: Int) {
        val tenant = tenantBindingStore.currentBinding()
        if (tenant == null) {
            findNavController().navigate(R.id.action_SecondFragment_to_FirstFragment)
            return
        }

        tenantOfflineStore.queueMutation(
            operationType = operationType,
            payloadJson = JSONObject()
                .put("tenantId", tenant.tenantId)
                .put("branchKey", tenant.branchKey)
                .put("projectKey", tenant.projectKey)
                .put("operationType", operationType)
                .put("queuedFrom", "tenant_dashboard")
                .toString(),
            localIdentity = "${tenant.queueNamespace}:$operationType"
        )
        renderOfflineSnapshot()
        binding.dashboardActionFeedback.text = getString(messageResId, tenant.shortTenantId)
    }

    private fun renderQueuedMediaFeedback(messageResId: Int) {
        val tenant = tenantBindingStore.currentBinding()
        if (tenant == null) {
            findNavController().navigate(R.id.action_SecondFragment_to_FirstFragment)
            return
        }

        val tenantMediaPath = "${tenant.mediaBasePath.trimEnd('/')}/dashboard-preview"
        tenantOfflineStore.queueMediaUpload(
            mediaPath = tenantMediaPath,
            metadataJson = JSONObject()
                .put("tenantId", tenant.tenantId)
                .put("branchKey", tenant.branchKey)
                .put("projectKey", tenant.projectKey)
                .put("source", "tenant_dashboard")
                .toString(),
            localIdentity = tenantMediaPath
        )
        renderOfflineSnapshot()
        binding.dashboardActionFeedback.text = getString(messageResId, tenant.shortTenantId)
    }

    private fun retryPendingOfflineWork() {
        val tenant = tenantBindingStore.currentBinding()
        if (tenant == null) {
            findNavController().navigate(R.id.action_SecondFragment_to_FirstFragment)
            return
        }

        val retried = tenantOfflineStore.markNextRetryAttempt()
        renderOfflineSnapshot()
        binding.dashboardActionFeedback.text = if (retried == null) {
            getString(R.string.dashboard_offline_queue_empty)
        } else {
            getString(R.string.dashboard_offline_retry_feedback, retried.idempotencyKey.take(8), retried.status.name)
        }
    }

    private fun renderOfflineSnapshot() {
        val snapshot = tenantOfflineStore.snapshot()
        binding.dashboardOfflineQueueValue.text = getString(
            R.string.dashboard_offline_queue_summary,
            snapshot.cachedReadCount,
            snapshot.queuedMutationCount,
            snapshot.retryingMutationCount,
            snapshot.failedMutationCount,
            snapshot.mediaOutboxCount,
            snapshot.lastRetryOutcome
        )
        binding.buttonRetryQueue.isEnabled = snapshot.queuedMutationCount > 0 || snapshot.retryingMutationCount > 0
    }

    private fun seedDashboardReadCache(tenant: TenantBindingStore.TenantBinding) {
        tenantOfflineStore.onlineFirstRead("dashboard") {
            JSONObject()
                .put("tenantId", tenant.tenantId)
                .put("clientAppCode", tenant.clientAppCode)
                .put("clientName", tenant.clientName)
                .put("apiBaseUrl", tenant.apiBaseUrl)
                .put("firebaseProjectId", tenant.firebaseProjectId)
                .put("mediaBaseUrl", tenant.media.baseUrl)
                .put("refreshedAtMillis", System.currentTimeMillis())
                .toString()
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
