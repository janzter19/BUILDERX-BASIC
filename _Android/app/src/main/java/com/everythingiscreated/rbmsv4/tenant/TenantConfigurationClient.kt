package com.everythingiscreated.rbmsv4.tenant

import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.URI
import java.net.URL
import java.nio.charset.StandardCharsets

class TenantConfigurationClient(
    private val endpointUrl: String,
    private val connectTimeoutMillis: Int = 10000,
    private val readTimeoutMillis: Int = 10000
) {
    fun fetch(clientAppCode: String): TenantBindingStore.TenantConfiguration {
        val normalizedCode = TenantBindingStore.normalizeClientAppCode(clientAppCode)
        require(normalizedCode.isNotEmpty()) { "client_app_code_required" }

        val endpoint = validatedEndpoint()
        val connection = endpoint.openConnection() as HttpURLConnection
        return try {
            val request = JSONObject()
                .put("client_app_code", normalizedCode)
                .toString()
                .toByteArray(StandardCharsets.UTF_8)

            connection.requestMethod = "POST"
            connection.connectTimeout = connectTimeoutMillis
            connection.readTimeout = readTimeoutMillis
            connection.doOutput = true
            connection.setRequestProperty("Accept", "application/json")
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            connection.outputStream.use { stream -> stream.write(request) }

            val responseBody = readResponseBody(connection)
            if (connection.responseCode !in 200..299) {
                throw IllegalArgumentException(errorCodeFromResponse(responseBody))
            }

            TenantBindingStore.TenantConfigurationResolver.parseServerResponse(
                responseBody,
                normalizedCode,
                apiBaseUrlFromEndpoint(endpoint)
            )
        } finally {
            connection.disconnect()
        }
    }

    private fun validatedEndpoint(): URL {
        val uri = runCatching { URI(endpointUrl.trim()) }.getOrNull()
        val isAllowedScheme = uri?.scheme.equals("https", ignoreCase = true) ||
            (uri?.scheme.equals("http", ignoreCase = true) && isLocalDevelopmentHost(uri?.host.orEmpty()))
        if (uri == null || !isAllowedScheme || uri.host.isNullOrBlank()) {
            throw IllegalArgumentException("tenant_configuration_endpoint_must_be_https")
        }
        return uri.toURL()
    }

    private fun apiBaseUrlFromEndpoint(endpoint: URL): String {
        val port = if (endpoint.port > -1) ":${endpoint.port}" else ""
        return "${endpoint.protocol}://${endpoint.host}$port"
    }

    private fun readResponseBody(connection: HttpURLConnection): String {
        val stream = if (connection.responseCode in 200..299) {
            connection.inputStream
        } else {
            connection.errorStream ?: connection.inputStream
        }
        return BufferedReader(InputStreamReader(stream, StandardCharsets.UTF_8)).use { reader ->
            reader.readText()
        }
    }

    private fun errorCodeFromResponse(responseBody: String): String {
        return runCatching {
            JSONObject(responseBody)
                .optJSONObject("error")
                ?.optString("code")
                ?.takeIf { it.isNotBlank() }
        }.getOrNull() ?: "tenant_configuration_request_failed"
    }

    private fun isLocalDevelopmentHost(host: String): Boolean {
        return host.equals("localhost", ignoreCase = true) ||
            host == "127.0.0.1" ||
            host == "10.0.2.2" ||
            host == "::1"
    }
}
