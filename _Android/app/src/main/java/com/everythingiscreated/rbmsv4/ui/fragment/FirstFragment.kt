package com.everythingiscreated.rbmsv4.ui.fragment

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.navigation.fragment.findNavController
import com.everythingiscreated.rbmsv4.R
import com.everythingiscreated.rbmsv4.databinding.FragmentFirstBinding
import com.everythingiscreated.rbmsv4.tenant.TenantBindingStore
import com.everythingiscreated.rbmsv4.tenant.TenantConfigurationClient
import com.everythingiscreated.rbmsv4.tenant.TenantFirebaseInitializer
import com.everythingiscreated.rbmsv4.tenant.TenantRuntimeBinding

class FirstFragment : Fragment() {

    private var _binding: FragmentFirstBinding? = null
    private lateinit var tenantBindingStore: TenantBindingStore
    private lateinit var tenantConfigurationClient: TenantConfigurationClient

    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {

        _binding = FragmentFirstBinding.inflate(inflater, container, false)
        return binding.root

    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        tenantBindingStore = TenantBindingStore(requireContext())
        tenantConfigurationClient = TenantConfigurationClient(getString(R.string.tenant_configuration_endpoint_url))
        tenantBindingStore.currentBinding()?.let { tenantBinding ->
            TenantRuntimeBinding.apply(tenantBinding)
            TenantFirebaseInitializer.initialize(requireContext(), tenantBinding)
            view.post {
                if (_binding != null) {
                    findNavController().navigate(R.id.action_FirstFragment_to_SecondFragment)
                }
            }
            return
        }

        binding.buttonBindTenant.setOnClickListener {
            verifyClientAppCode(binding.clientAppCodeInput.text?.toString().orEmpty())
        }
    }

    private fun verifyClientAppCode(clientAppCode: String, successMessage: Int = R.string.tenant_binding_verified) {
        val normalizedCode = TenantBindingStore.normalizeClientAppCode(clientAppCode)
        if (normalizedCode.isEmpty()) {
            binding.clientAppCodeLayout.error = getString(R.string.tenant_binding_required)
            return
        }

        setTenantRequestRunning(true)
        val activity = activity ?: run {
            setTenantRequestRunning(false)
            return
        }
        Thread {
            val result = runCatching {
                val configuration = tenantConfigurationClient.fetch(normalizedCode)
                tenantBindingStore.bindConfiguration(configuration)
            }
            activity.runOnUiThread {
                if (_binding == null) {
                    return@runOnUiThread
                }
                result.onSuccess { tenantBinding ->
                    TenantRuntimeBinding.apply(tenantBinding)
                    TenantFirebaseInitializer.initialize(requireContext(), tenantBinding)
                    binding.clientAppCodeLayout.error = null
                    Toast.makeText(requireContext(), successMessage, Toast.LENGTH_SHORT).show()
                    findNavController().navigate(R.id.action_FirstFragment_to_SecondFragment)
                }.onFailure { error ->
                    binding.clientAppCodeLayout.error = tenantErrorMessage(error)
                    setTenantRequestRunning(false)
                }
            }
        }.start()
    }

    private fun setTenantRequestRunning(running: Boolean) {
        binding.buttonBindTenant.isEnabled = !running
    }

    private fun tenantErrorMessage(error: Throwable): String {
        return when (error.message) {
            "client_app_code_required" -> getString(R.string.tenant_binding_required)
            "client_app_code_mismatch" -> getString(R.string.tenant_binding_mismatch)
            "CLIENT_APP_CODE_NOT_FOUND", "INVALID_CLIENT_APP_CODE" -> getString(R.string.tenant_binding_invalid_code)
            "tenant_configuration_endpoint_must_be_https", "tenant_api_endpoint_must_be_https" ->
                getString(R.string.tenant_endpoint_invalid)
            "firebase_database_url_must_be_https" -> getString(R.string.tenant_firebase_https_required)
            "tenant_configuration_incomplete", "firebase_destination_incomplete", "tenant_client_missing",
            "tenant_android_missing", "tenant_firebase_missing", "tenant_media_missing",
            "android_release_config_incomplete", "tenant_client_incomplete" ->
                getString(R.string.tenant_configuration_incomplete)
            "android_package_mismatch" -> getString(R.string.tenant_android_package_mismatch)
            "tenant_media_endpoint_invalid", "tenant_splash_image_invalid" -> getString(R.string.tenant_media_endpoint_invalid)
            else -> error.message ?: getString(R.string.tenant_binding_error)
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
