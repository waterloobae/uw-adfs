# University of Waterloo ADFS SAML Authentication Package

This Laravel package provides SAML authentication integration with University of Waterloo's Active Directory Federation Services (ADFS). It includes a **built-in SAML 2.0 handler** that requires no external dependencies.

## CSRF exception
Insert following codes in /bootstrap/app.php to avoid 419 Page Expired error
```php
->withMiddleware(function (Middleware $middleware): void {
     $middleware->validateCsrfTokens(except: [
       'saml/proxy/acs',
       'saml/proxy/sls',
       'saml/acs',
       'saml/sls',
    ]);
})
```

## Features

- ✨ **No external SAML dependencies** - Built-in SAML 2.0 implementation using only PHP stdlib
- **SAML 2.0 authentication** with UW ADFS (with optional OneLogin library support)
- **ForceAuthn enabled** - Prevents automatic re-authentication after logout (v1.5.12+)
- **Automatic online metadata fetching** with caching and fallback
- **Advanced access control** with department, group, and whitelist filtering
- **SAML Proxy mode** - Act as intermediary for staging environments (v1.5.17+)
- **Session persistence** - Robust session handling for proxy scenarios (v1.5.19+)
- **Flexible logout options** - Full ADFS logout or app-only session clearing (v1.5.21+)
- Support for both production and development environments
- Automatic user creation and updates
- Easy-to-use middleware for route protection
- Configurable attribute mapping
- Single Sign-On (SSO) and Single Logout (SLO) with signed requests
- Metadata caching for improved performance
- Comprehensive logging and access decision tracking
- Custom access denied pages with detailed information
- XML digital signatures (RSA-SHA256) for ADFS compliance

## Requirements

- PHP 8.1 or higher (with OpenSSL extension for cryptography)
- Laravel 10.0 or higher

## Installation

1. Install the package via Composer:

```bash
composer require waterloobae/uw-adfs
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --tag=uw-adfs-config
```

3. Publish the SAML metadata files:

```bash
php artisan vendor:publish --tag=uw-adfs-metadata
```

## SAML Implementation

This package uses a **built-in SAML 2.0 handler** that:
- Works out-of-the-box with no additional dependencies
- Uses only PHP's built-in OpenSSL extension for cryptography
- Includes XML digital signatures (RSA-SHA256) for ADFS compliance
- Handles SAML authentication, assertion validation, and single logout
- Provides automatic metadata fetching with caching
- Supports all standard SAML 2.0 flows

**No configuration needed** - it automatically uses the built-in handler.

```env
# ADFS Environment (production or development)
UW_ADFS_ENVIRONMENT=development

# Service Provider Configuration
UW_ADFS_SP_ENTITY_ID=https://your-app.example.com
UW_ADFS_SP_ACS_URL=https://your-app.example.com/saml/acs
UW_ADFS_SP_SLS_URL=https://your-app.example.com/saml/sls

# Optional: SP Certificate and Private Key for signing
UW_ADFS_SP_X509_CERT=
UW_ADFS_SP_PRIVATE_KEY=

# Metadata Configuration (optional)
UW_ADFS_METADATA_CACHE=true
UW_ADFS_METADATA_CACHE_DURATION=3600
UW_ADFS_METADATA_TIMEOUT=30
UW_ADFS_METADATA_FALLBACK_LOCAL=true

# Access Control Configuration (optional)
UW_ADFS_DEPARTMENT_RESTRICTION=false
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Computer Science"
UW_ADFS_WHITELIST_ENABLED=false
UW_ADFS_WHITELIST_EMAILS="admin@uwaterloo.ca,special.user@uwaterloo.ca"
UW_ADFS_GROUP_RESTRICTION=false
UW_ADFS_REQUIRED_GROUPS="Faculty,Staff"
UW_ADFS_BLOCKED_GROUPS="Suspended Accounts,Guest Users"
UW_ADFS_ACCESS_DENIED_MESSAGE="Access denied. Contact administrator."

# Redirect Configuration (optional)
UW_ADFS_REDIRECT_AFTER_LOGIN=/dashboard

# User Model (optional, defaults to App\Models\User)
UW_ADFS_USER_MODEL=App\Models\User
```

5. Ensure your User model has the necessary fields:

```php
// database/migrations/xxxx_xx_xx_xxxxxx_create_users_table.php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('first_name')->nullable();
    $table->string('last_name')->nullable();
    // ... other fields
    $table->timestamps();
});
```

## Metadata Management

The package automatically fetches SAML metadata from the online UW ADFS endpoints:

- **Production**: `https://adfs.uwaterloo.ca/FederationMetadata/2007-06/FederationMetadata.xml`
- **Development**: `https://adfstest.uwaterloo.ca/FederationMetadata/2007-06/FederationMetadata.xml`

### Metadata Features

- **Automatic caching**: Metadata is cached for 1 hour by default
- **Fallback support**: Falls back to local XML files if online fetch fails
- **Console command**: Refresh metadata manually

### Refresh Metadata Command

```bash
# Refresh metadata for current environment
php artisan uw-adfs:refresh-metadata

# Refresh for specific environment
php artisan uw-adfs:refresh-metadata --environment=production

# Clear cache and refresh
php artisan uw-adfs:refresh-metadata --clear-cache
```

### Local Fallback Files

The local XML files (`dev.xml` and `prod.xml`) serve as:
- **Reference documentation** of ADFS configuration
- **Fallback data** when online endpoints are unavailable
- **Development backup** for offline work
- **Disaster recovery** metadata source

These files are automatically published to `storage/app/saml/` when you install the package.

### Metadata Configuration Options

Configure metadata behavior in your `.env` file:

```env
# Enable/disable metadata caching (default: true)
UW_ADFS_METADATA_CACHE=true

# Cache duration in seconds (default: 3600 = 1 hour)
UW_ADFS_METADATA_CACHE_DURATION=3600

# HTTP timeout for fetching metadata (default: 30 seconds)
UW_ADFS_METADATA_TIMEOUT=30

# Enable fallback to local files (default: true)
UW_ADFS_METADATA_FALLBACK_LOCAL=true
```

## Configuration

### Environment Configuration

The package supports both production and development ADFS environments. Set `UW_ADFS_ENVIRONMENT` in your `.env` file:

- `production`: Uses `adfs.uwaterloo.ca`
- `development`: Uses `adfstest.uwaterloo.ca`

### Redirect Configuration

After successful login, users are redirected to a default page. Configure this in your `.env`:

```env
# Default: /dashboard
UW_ADFS_REDIRECT_AFTER_LOGIN=/dashboard

# Other examples:
# UW_ADFS_REDIRECT_AFTER_LOGIN=/home
# UW_ADFS_REDIRECT_AFTER_LOGIN=/admin/panel
```

**Note**: This redirect only applies when there's no `RelayState` in the SAML request. If a `RelayState` is provided, users will be redirected to that URL instead.

### Attribute Mapping

Configure how SAML attributes map to your user model in `config/uw-adfs.php`:

```php
'attribute_mapping' => [
    'name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
    'email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
    'first_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
    'last_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
    'groups' => 'http://schemas.xmlsoap.org/claims/Group',
],
```

## Usage

### Basic Authentication

1. **Login Link**: Direct users to the SAML login:

```php
<a href="{{ route('saml.login') }}">Login with UW ADFS</a>
```

2. **Logout Options**:

```php
<!-- Full SAML logout (logs out from both app and ADFS) -->
<a href="{{ route('saml.logout') }}">Logout from ADFS</a>

<!-- App-only logout (clears local session, keeps ADFS session active) -->
<a href="{{ route('saml.logout.app') }}">Logout from App</a>
```

**Important Authentication Behavior:**
- ADFS uses **ForceAuthn** to prevent automatic re-authentication
- After logout, users will be prompted to enter credentials again on next login
- This prevents the "back button auto-login" issue where users could bypass logout
- Use `/saml/logout/app` for quick session clearing without ADFS interaction

### Route Protection

Use the provided middleware to protect routes:

```php
// Basic ADFS authentication
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['adfs.auth']);

// Group-based access control
Route::get('/admin', function () {
    return view('admin');
})->middleware(['adfs.group:Domain Admins']);
```

Register the middleware in your `app/Http/Kernel.php`:

```php
protected $routeMiddleware = [
    // ... other middleware
    'adfs.auth' => \WaterlooBae\UwAdfs\Http\Middleware\AdfsAuthenticated::class,
    'adfs.group' => \WaterlooBae\UwAdfs\Http\Middleware\AdfsGroup::class,
];
```

### Using the Service

You can also use the ADFS service directly:

```php
use WaterlooBae\UwAdfs\Facades\UwAdfs;

// Get user groups from current session
$samlSession = session('saml_session');
$groups = UwAdfs::getUserGroups($samlSession['attributes']);

// Check if user has specific group
$hasAccess = UwAdfs::userHasGroup($samlSession['attributes'], 'Required Group Name');
```

### Available Routes

The package registers the following routes:

- `GET /saml/login` - Initiate SAML login (with ForceAuthn to prevent auto re-authentication)
- `POST /saml/acs` - SAML Assertion Consumer Service (includes access control)
- `GET|POST /saml/sls` - SAML Single Logout Service
- `GET|POST /saml/logout` - Initiate SAML logout (full logout from ADFS)
- `GET|POST /saml/logout/app` - App-only logout (local session only, no ADFS interaction)
- `GET /saml/metadata` - SP metadata (for ADFS configuration)
- `GET /access-denied` - Access denied page with detailed information

**Proxy Routes** (when `UW_ADFS_PROXY_ENABLED=true`):
- `GET|POST /saml/proxy/sso` - Proxy SSO endpoint for client applications
- `POST /saml/proxy/acs` - Proxy ACS endpoint (receives ADFS responses)
- `GET|POST /saml/proxy/sls` - Proxy logout endpoint
- `GET /saml/proxy/metadata` - Proxy metadata for client applications
- `GET /saml/proxy/status` - Proxy health and configuration status

## ADFS Configuration

Provide your ADFS administrator with:

1. **SP Metadata URL**: `https://your-app.example.com/saml/metadata`
2. **Assertion Consumer Service URL**: `https://your-app.example.com/saml/acs`
3. **Single Logout Service URL**: `https://your-app.example.com/saml/sls`

## Group-Based Access Control

To restrict access based on Active Directory groups, use the `adfs.group` middleware:

```php
Route::group(['middleware' => ['adfs.group:Faculty']], function () {
    Route::get('/faculty-only', 'FacultyController@index');
});
```

### Troubleshooting

### Common Issues

1. **Certificate Issues**: Ensure the SAML metadata is accessible and contains valid certificates
2. **Clock Skew**: SAML assertions are time-sensitive. Ensure server time is synchronized
3. **URL Mismatch**: Ensure the URLs in your configuration match exactly with what's configured in ADFS
4. **Network Issues**: Check firewall rules if metadata fetching fails
5. **Cache Problems**: Clear metadata cache if you see stale certificate errors

### Proxy-Specific Troubleshooting

When using proxy mode, check proxy status and logs:

```bash
# Check proxy status
curl https://your-proxy.uwaterloo.ca/saml/proxy/status

# Monitor proxy logs
tail -f storage/logs/laravel.log | grep "UW ADFS Proxy"
```

**Common Proxy Issues:**

1. **Missing Client Context / Session Lost**
   - **Symptom**: "Client context not found for request" error
   - **Cause**: Session data not persisting between proxy SSO and ACS calls
   - **Solution**: Ensure session driver supports cross-request persistence (database, redis recommended)
   - **Fix**: Package automatically calls `Session::save()` after storing client context

2. **Client App Using Wrong Endpoints**
   - **Symptom**: Client app hits `/saml/proxy/acs` instead of `/saml/acs`
   - **Cause**: Client has `UW_ADFS_PROXY_ENABLED=true` incorrectly set
   - **Solution**: Client apps should use `UW_ADFS_ENVIRONMENT=proxy` with `UW_ADFS_PROXY_ENABLED=false`

3. **Invalid Proxy Relay State**
   - **Symptom**: "Invalid proxy relay state - missing proxy flag" error
   - **Cause**: Client sending wrong entity ID or ACS URL in AuthnRequest
   - **Solution**: Verify client's `UW_ADFS_SP_ENTITY_ID` and `UW_ADFS_SP_ACS_URL` are correctly set

4. **ForceAuthn Preventing Auto Re-authentication**
   - **Behavior**: Users prompted for credentials even with active ADFS session
   - **Explanation**: This is intentional to prevent "back button" auto-login after logout
   - **Solution**: Working as designed; use app-only logout (`/saml/logout/app`) for quick session clearing

5. **Upstream Timeout**: UW ADFS taking too long to respond (increase timeout in config)
6. **Attribute Filtering**: Required attributes being filtered out (check filter configuration)">

## Access Control & User Filtering

The package includes comprehensive access control features to restrict access based on departments, groups, and whitelists.

### Department-Based Access Control

Restrict access to users from specific departments:

```env
# Enable department filtering
UW_ADFS_DEPARTMENT_RESTRICTION=true

# Allow only Math and Computer Science departments
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Computer Science,Statistics"
```

### Email Whitelist

Allow specific users regardless of other restrictions:

```env
# Enable whitelist
UW_ADFS_WHITELIST_ENABLED=true

# Specific users who should always have access
UW_ADFS_WHITELIST_EMAILS="admin@uwaterloo.ca,director@math.uwaterloo.ca,special.user@uwaterloo.ca"
```

### Group-Based Access Control

Control access based on Active Directory group membership:

```env
# Enable group restrictions
UW_ADFS_GROUP_RESTRICTION=true

# Users must belong to at least one of these groups
UW_ADFS_REQUIRED_GROUPS="Faculty,Staff,Graduate Students"

# Block users from these groups
UW_ADFS_BLOCKED_GROUPS="Suspended Accounts,Inactive Users"
```

### Access Control Hierarchy

1. **Whitelist** (if enabled): Overrides all other restrictions
2. **Blocked Groups**: Users in these groups are denied access
3. **Required Groups**: Users must belong to at least one
4. **Department Restrictions**: Users must be from allowed departments

### Custom Access Denied Page

```env
# Custom access denied configuration
UW_ADFS_ACCESS_DENIED_URL="/custom-access-denied"
UW_ADFS_ACCESS_DENIED_MESSAGE="Access restricted to authorized personnel only."
```

### Access Control Examples

See `examples/access-control-examples.env` for complete configuration scenarios.

## Complete Implementation Example

### Environment Configuration (.env)

```env
# Basic ADFS Configuration
UW_ADFS_ENVIRONMENT=production
UW_ADFS_SP_ENTITY_ID=https://test.uwaterloo.ca
UW_ADFS_SP_ACS_URL=https://test.uwaterloo.ca/saml/acs
UW_ADFS_SP_SLS_URL=https://test.uwaterloo.ca/saml/sls

# Access Control - Mathematics Department Only
UW_ADFS_DEPARTMENT_RESTRICTION=true
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Statistics"

# Whitelist for Special Users
UW_ADFS_WHITELIST_ENABLED=true
UW_ADFS_WHITELIST_EMAILS="admin@uwaterloo.ca,cemc-director@math.uwaterloo.ca"

# Group Restrictions
UW_ADFS_GROUP_RESTRICTION=true
UW_ADFS_REQUIRED_GROUPS="Faculty,Staff,Graduate Students"
UW_ADFS_BLOCKED_GROUPS="Suspended Accounts"

# Custom Messages
UW_ADFS_ACCESS_DENIED_MESSAGE="Access restricted to Mathematics department members."
```

### Route Configuration (routes/web.php)

```php
// Public routes
Route::get('/', function () {
    return view('welcome');
});

// Protected routes with ADFS authentication + access control
Route::middleware(['adfs.auth'])->group(function () {
    Route::get('/dashboard', 'DashboardController@index');
    Route::get('/member-resources', 'ResourceController@index');
});

// Faculty-only routes
Route::middleware(['adfs.group:Faculty'])->group(function () {
    Route::get('/faculty-tools', 'FacultyController@tools');
});

// Admin routes with multiple restrictions
Route::middleware(['adfs.group:Administrators'])->group(function () {
    Route::get('/admin', 'AdminController@index');
});
```

### Controller Implementation

```php
<?php

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $attributes = session('saml_attributes', []);
        
        return view('dashboard', [
            'user' => $user,
            'department' => $attributes['department'][0] ?? 'Unknown',
            'groups' => $attributes['memberOf'] ?? []
        ]);
    }
}
```

## SAML Proxy/Staging AP Support

The package supports **SAML Proxy mode** (also known as Staging Authentication Proxy), allowing your application to act as an intermediary between client applications and UW ADFS. This is particularly useful for:

- **Staging Environments**: Provide ADFS authentication for development/staging without direct ADFS integration
- **Multi-Tier Architectures**: Centralize authentication through a proxy layer
- **Attribute Filtering**: Control which SAML attributes are passed to downstream applications
- **Access Control Layering**: Apply additional access control before forwarding authentication

### Quick Start: Using a Proxy Server

If you have a staging/proxy server already set up, configure your client application:

```env
# Client app configuration (e.g., local development)
APP_URL=http://localhost:8000
UW_ADFS_ENVIRONMENT=proxy
UW_ADFS_SP_ENTITY_ID=http://localhost:8000
UW_ADFS_SP_ACS_URL=http://localhost:8000/saml/acs
UW_ADFS_SP_SLS_URL=http://localhost:8000/saml/sls

# Proxy server details (configured via 'proxy' IdP option)
UW_ADFS_PROXY_ENTITY_ID=https://proxy-server.uwaterloo.ca/proxy
UW_ADFS_PROXY_SSO_URL=https://proxy-server.uwaterloo.ca/saml/proxy/sso
UW_ADFS_PROXY_SLS_URL=https://proxy-server.uwaterloo.ca/saml/proxy/sls
UW_ADFS_PROXY_METADATA_URL=https://proxy-server.uwaterloo.ca/saml/proxy/metadata

# IMPORTANT: Client apps should NOT enable proxy mode
UW_ADFS_PROXY_ENABLED=false
```

**Authentication Flow:**
1. Client app → Proxy SSO endpoint
2. Proxy → UW ADFS (authentication)
3. ADFS → Proxy (response with attributes)
4. Proxy → Client app (filtered response)

### Setting Up a Proxy Server

To run your application as a SAML proxy server:

```env
# Enable proxy mode
UW_ADFS_PROXY_ENABLED=true

# Proxy mode: 'server' (for clients), 'client' (to ADFS), 'both'
UW_ADFS_PROXY_MODE=both

# Upstream ADFS configuration (when acting as client)
UW_ADFS_UPSTREAM_IDP_ENTITY_ID=https://adfs.uwaterloo.ca/adfs/services/trust
UW_ADFS_UPSTREAM_SSO_URL=https://adfs.uwaterloo.ca/adfs/ls/
UW_ADFS_UPSTREAM_SLS_URL=https://adfs.uwaterloo.ca/adfs/ls/

# Proxy settings
UW_ADFS_PROXY_SESSION_LIFETIME=3600
UW_ADFS_PROXY_ATTRIBUTE_FILTERING=true
UW_ADFS_PROXY_SIGN_ASSERTIONS=true
```

### Proxy Endpoints

When proxy mode is enabled, these additional endpoints are available:

- **SSO Endpoint**: `/saml/proxy/sso` - Receives authentication requests from client apps
- **ACS Endpoint**: `/saml/proxy/acs` - Processes responses from upstream ADFS
- **SLS Endpoint**: `/saml/proxy/sls` - Handles logout requests
- **Metadata**: `/saml/proxy/metadata` - Proxy metadata for client applications
- **Status**: `/saml/proxy/status` - Proxy configuration and health status

### Client Application Configuration

Client applications should configure their SAML settings to use your proxy:

```php
// Client app SAML configuration
'idp' => [
    'entityId' => 'https://your-proxy.uwaterloo.ca/proxy',
    'singleSignOnService' => [
        'url' => 'https://your-proxy.uwaterloo.ca/saml/proxy/sso',
        'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
    ],
    'singleLogoutService' => [
        'url' => 'https://your-proxy.uwaterloo.ca/saml/proxy/sls',
        'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
    ],
],
```

### Proxy Flow

1. **Client Request**: Client app sends SAML auth request to proxy SSO endpoint
2. **Request Processing**: Proxy validates and stores client context
3. **Upstream Forward**: Proxy forwards authentication to UW ADFS
4. **ADFS Response**: UW ADFS authenticates user and responds to proxy
5. **Access Control**: Proxy applies access control rules
6. **Attribute Filtering**: Proxy filters attributes based on configuration
7. **Client Response**: Proxy generates and sends SAML response to client\n\n## Key Benefits\n\n1. **Always Up-to-Date**: Metadata is fetched from authoritative UW ADFS sources\n2. **High Performance**: Intelligent caching reduces network latency\n3. **Reliable**: Robust fallback ensures service continuity during outages\n4. **Low Maintenance**: No manual XML file updates required\n5. **Comprehensive Monitoring**: Clear logging and administrative commands\n6. **Flexible Configuration**: Adaptable behavior for different environments\n7. **Advanced Access Control**: Department, group, and whitelist filtering\n8. **SAML Proxy Support**: Act as intermediary for staging and multi-tier scenarios\n9. **Backward Compatible**: Existing installations work without changes">

## Security Considerations

1. **Always use HTTPS in production** - Required for SAML security
2. **Keep your SP private key secure** - Never commit to version control
3. **Regularly update metadata** - Use `php artisan uw-adfs:refresh-metadata`
4. **Session security** - Use database or redis session driver for proxy mode
5. **ForceAuthn enabled** - Prevents automatic re-authentication bypass
6. **Remove debug routes in production** - `/saml/attributes` route is disabled by default (v1.5.20+)

### Security Configuration

The `security` section in `config/uw-adfs.php` has been streamlined to include only actively used settings:

```php
'security' => [
    // Active settings
    'authnRequestsSigned' => false,
    'logoutRequestSigned' => false,  // ADFS requires unsigned logout requests
    'logoutResponseSigned' => false,
    'wantNameId' => true,
    'wantXMLValidation' => true,
    
    // Unused settings are commented out for clarity
    // See config file for full list of available options
],
```

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Support

For issues related to this package, please open an issue on the GitHub repository.

For ADFS configuration issues, contact the University of Waterloo IT Services.
