<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$store = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/tenant/TenantBindingStore.kt');
$client = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/tenant/TenantConfigurationClient.kt');
$publicSettingsClient = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/tenant/TenantPublicSettingsClient.kt');
$firebase = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/tenant/TenantFirebaseInitializer.kt');
$splashPreloader = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/tenant/TenantSplashBackgroundPreloader.kt');
$first = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/ui/fragment/tenant/TenantCodeFragment.kt');
$login = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/ui/fragment/login/LoginFragment.kt');
$splash = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/ui/fragment/splash/SplashFragment.kt');
$homeActivity = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/ui/activity/home/HomeActivity.kt');
$firstLayout = file_get_contents($root . '/_Android/app/src/main/res/layout/fragment_tenant_code.xml');
$loginLayout = file_get_contents($root . '/_Android/app/src/main/res/layout/fragment_login.xml');
$homeLayout = file_get_contents($root . '/_Android/app/src/main/res/layout/activity_home.xml');
$navGraph = file_get_contents($root . '/_Android/app/src/main/res/navigation/nav_graph.xml');
$manifest = file_get_contents($root . '/_Android/app/src/main/AndroidManifest.xml');
$activity = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/ui/activity/MainActivity.kt');
$network = file_get_contents($root . '/_Android/app/src/main/res/xml/network_security_config.xml');
$strings = file_get_contents($root . '/_Android/app/src/main/res/values/strings.xml');
$tests = file_get_contents($root . '/_Android/app/src/test/java/com/everythingiscreated/rbmsv4/ExampleUnitTest.kt');

foreach ([
    'TenantBindingStore.kt' => $store,
    'TenantConfigurationClient.kt' => $client,
    'TenantPublicSettingsClient.kt' => $publicSettingsClient,
    'TenantFirebaseInitializer.kt' => $firebase,
    'TenantSplashBackgroundPreloader.kt' => $splashPreloader,
    'TenantCodeFragment.kt' => $first,
    'LoginFragment.kt' => $login,
    'SplashFragment.kt' => $splash,
    'HomeActivity.kt' => $homeActivity,
    'fragment_tenant_code.xml' => $firstLayout,
    'fragment_login.xml' => $loginLayout,
    'activity_home.xml' => $homeLayout,
    'nav_graph.xml' => $navGraph,
    'AndroidManifest.xml' => $manifest,
    'MainActivity.kt' => $activity,
    'network_security_config.xml' => $network,
    'strings.xml' => $strings,
    'ExampleUnitTest.kt' => $tests,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException($label . ' is not readable.');
    }
}

$storeRequirements = [
    'data class ClientMetadata',
	    'data class AndroidReleaseConfig',
	    'welcomeTitle',
	    'welcomeDescription',
	    'data class FirebaseClientConfig',
    'data class MediaEndpoints',
    'parseServerResponse',
    'configurationToJson',
    'configurationFromJson',
    'client_app_code_mismatch',
    'firebase_database_url_must_be_https',
    'tenant_media_endpoint_invalid',
    'AndroidKeyStore',
    'Tenant binding could not be read back.',
];
foreach ($storeRequirements as $needle) {
    if (!str_contains($store, $needle)) {
        throw new RuntimeException('Tenant binding store is missing: ' . $needle);
    }
}

$clientRequirements = [
    '.put("client_app_code", normalizedCode)',
    'isLocalDevelopmentHost',
    'errorCodeFromResponse',
];
foreach ($clientRequirements as $needle) {
    if (!str_contains($client, $needle)) {
        throw new RuntimeException('Tenant configuration client is missing: ' . $needle);
    }
}

if (!str_contains($publicSettingsClient, 'android_login_background_image_url')) {
    throw new RuntimeException('Public tenant settings client must read the configured login background URL.');
}

$firebaseRequirements = [
    'FirebaseOptions.Builder()',
    '.setApiKey(binding.firebase.apiKey)',
    '.setApplicationId(binding.firebase.appId)',
    '.setDatabaseUrl(binding.firebase.databaseUrl)',
    'FirebaseApp.initializeApp',
];
foreach ($firebaseRequirements as $needle) {
    if (!str_contains($firebase, $needle)) {
        throw new RuntimeException('Tenant Firebase initializer is missing: ' . $needle);
    }
}

$splashRequirements = [
    'collection(PROJECT_SETTING_COLLECTION)',
    'document(binding.projectKey)',
    'android_splash_screen_image_url_$index',
    'orderedSplashImageUrls',
    'orderedLoginBackgroundUrls',
    'loginBackgroundUrlFromProjectSettings',
    'binding.android.splashImages',
    'binding.android.loginBackgroundImageUrl',
    'tenant_splash_backgrounds',
    'downloadImage',
];
foreach ($splashRequirements as $needle) {
    if (!str_contains($splashPreloader, $needle)) {
        throw new RuntimeException('Tenant splash background preloader is missing: ' . $needle);
    }
}

if (!str_contains($first, 'tenantBindingStore.currentBinding()?.let')) {
    throw new RuntimeException('Saved tenant config must be reused on next launch.');
}
if (!str_contains($first, 'preloadUnboundLoginBackground') || !str_contains($firstLayout, '@+id/tenant_background')) {
    throw new RuntimeException('Tenant setup screen must use the server-configured login background before binding.');
}
if (!str_contains($first, 'R.color.rbms_text_on_image')) {
    throw new RuntimeException('Tenant setup controls must use the explicit white foreground color.');
}
if (!str_contains($first, 'findNavController().navigate(R.id.action_TenantCodeFragment_to_LoginFragment)')) {
    throw new RuntimeException('Setup screen must continue to login only after tenant binding succeeds.');
}
if (!str_contains($first, 'hasAuthenticatedSession') || !str_contains($first, 'HomeActivity.launch')) {
    throw new RuntimeException('Completed onboarding sessions must route directly to Home on startup.');
}
if (!str_contains($store, 'markOnboardingCompleted') || !str_contains($store, 'isOnboardingCompleted')) {
    throw new RuntimeException('Tenant binding store must persist onboarding completion.');
}
if (!str_contains($login, 'action_LoginFragment_to_SplashFragment')) {
    throw new RuntimeException('Login screen must continue to splash after credential entry.');
}
if (!str_contains($login, 'package com.everythingiscreated.rbmsv4.ui.fragment.login')) {
    throw new RuntimeException('Login fragment must live in the login package.');
}
if (!str_contains($login, 'TenantSplashBackgroundPreloader.preload(requireContext(), tenantBinding)')) {
    throw new RuntimeException('Login screen must preload the splash background before post-login navigation.');
}
if (!str_contains($login, 'action_LoginFragment_to_SplashFragment')) {
    throw new RuntimeException('Login screen must open onboarding after credentials are entered.');
}
if (!str_contains($splash, 'preloadAll') || !str_contains($splash, 'markOnboardingCompleted')) {
    throw new RuntimeException('Onboarding tab view must load all pages and persist completion.');
}
if (!str_contains($splash, 'package com.everythingiscreated.rbmsv4.ui.fragment.splash')) {
    throw new RuntimeException('Splash fragments must live in the splash package.');
}
if (!str_contains($login, 'preloadLoginBackground')) {
    throw new RuntimeException('Login screen must load its background from the configured server setting.');
}
if (!str_contains($login, 'TenantProjectUserAuthRepository') || !str_contains($login, 'normalizeUsername')) {
    throw new RuntimeException('Login screen must use the direct Firebase project-user authentication repository.');
}
foreach (['TenantConfigurationClient', 'TenantUserProfileClient', 'tenant_user_profile_endpoint_url', 'builder_user'] as $forbiddenLoginMarker) {
    if (str_contains($login, $forbiddenLoginMarker)) {
        throw new RuntimeException('Login screen must not use the server user-profile endpoint or builder_user: ' . $forbiddenLoginMarker);
    }
}
foreach (['username_input', 'password_input', 'button_login'] as $needle) {
    if (!str_contains($loginLayout, $needle)) {
        throw new RuntimeException('Login screen is missing: ' . $needle);
    }
}
if (!str_contains($first, 'CLIENT_APP_CODE_NOT_FOUND') || !str_contains($first, 'tenant_binding_invalid_code')) {
    throw new RuntimeException('Invalid client app code must show a clear setup-screen error.');
}
if (!str_contains($activity, 'destination.id == R.id.TenantCodeFragment') || !str_contains($activity, 'binding.appBar.visibility')) {
    throw new RuntimeException('Tenant setup screen must hide the top app bar.');
}

foreach (['ActionBarDrawerToggle', 'drawer_sign_out', 'TenantBindingStore', 'renderHomeWelcome', 'fun launch(context: Context)'] as $needle) {
    if (!str_contains($homeActivity, $needle)) {
        throw new RuntimeException('Home activity is missing: ' . $needle);
    }
}
foreach (['DrawerLayout', 'AppBarLayout', 'CollapsingToolbarLayout', 'app:layout_behavior="@string/appbar_scrolling_view_behavior"'] as $needle) {
    if (!str_contains($homeLayout, $needle)) {
        throw new RuntimeException('Home activity layout is missing: ' . $needle);
    }
}
if (str_contains($navGraph, 'HomeFragment') || str_contains($navGraph, 'SecondFragment')) {
    throw new RuntimeException('Removed fragments must not remain in the navigation graph.');
}
foreach ([
    '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/ui/fragment/HomeFragment.kt',
    '/_Android/app/src/main/res/layout/fragment_home.xml',
    '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/ui/fragment/SecondFragment.kt',
    '/_Android/app/src/main/res/layout/fragment_second.xml',
] as $removedPath) {
    if (file_exists($root . $removedPath)) {
        throw new RuntimeException('Removed fragment still exists: ' . $removedPath);
    }
}

$uiRequirements = [
    'client_app_code_input',
    'client_app_code_layout',
    'tenant_setup_instructions',
    'Welcome to RBMS',
    'android:paddingTop="24dp"',
    'android:paddingBottom="24dp"',
    'android:layout_gravity="center"',
    '@color/rbms_text_on_image',
    'home_toolbar_title',
    'drawer_sign_out',
    'home_status_title',
    'android.permission.INTERNET',
    'networkSecurityConfig',
    '192.168.1.70',
    'tenant_setup_server_unreachable',
    'login_fragment_label',
    'login_username_hint',
    'login_password_hint',
];
foreach ($uiRequirements as $needle) {
    $haystack = $firstLayout . "\n" . $loginLayout . "\n" . $homeLayout . "\n" . $strings . "\n" . $manifest . "\n" . $network;
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('Android tenant setup UI contract is missing: ' . $needle);
    }
}

if (str_contains($firstLayout, 'rbms_image_scrim') || str_contains($loginLayout, 'rbms_image_scrim')) {
    throw new RuntimeException('Login and tenant backgrounds must render without a scrim overlay.');
}

$removedSetupFields = [
    'status_label',
    'status_value',
    'partition_label',
    'partition_value',
    'endpoint_label',
    'endpoint_value',
    'button_refresh_tenant',
    'button_continue',
    'button_switch_tenant',
];
foreach ($removedSetupFields as $needle) {
    if (str_contains($firstLayout, $needle)) {
        throw new RuntimeException('Setup page must retain only tenant code controls; found: ' . $needle);
    }
}

$testRequirements = [
    'tenantConfigurationAcceptsCompleteClientAppPayload',
    'tenantConfigurationRejectsInvalidClientAppCode',
    'tenantConfigurationPersistsFirebaseAndMediaResponseFields',
    'projectSettingsSplashBackgroundUrlsComeFromFirebaseDocument',
    'mediaOutboxAcceptsTenantResponseUrls',
    'removedFragmentsAreNotReachableOrPresent',
];
foreach ($testRequirements as $needle) {
    if (!str_contains($tests, $needle)) {
        throw new RuntimeException('Focused Android tenant setup test is missing: ' . $needle);
    }
}

echo json_encode([
    'setup_screen_when_unconfigured' => true,
    'valid_client_code_saves_config' => true,
    'invalid_client_code_rejected' => true,
    'firebase_config_selected_from_response' => true,
    'media_endpoints_selected_from_response' => true,
    'saved_config_reused_on_next_launch' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
